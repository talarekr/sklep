<?php

namespace WEI\Adapters;

use WEI\Plugin;

use WEI\Interfaces\MarketplaceAdapterInterface;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayClient;
use WEI\Services\EbayTaxonomyService;
use WEI\Services\Logger;
use WEI\Services\Translation\OpenAiTranslationProvider;
use WEI\Interfaces\TranslationProviderInterface;

class EbayAdapter implements MarketplaceAdapterInterface
{
    public function __construct(private EbayClient $client, private MappingRepository $repo, private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger)
    {
    }

    public function readiness_check(): array
    {
        $settings = $this->settings();
        $marketplaceId = $this->marketplace_id();

        if (trim((string) ($settings['marketplace_id'] ?? '')) === '') {
            return ['ready' => false, 'failed' => 'marketplace_id_missing'];
        }

        if (trim((string) ($settings['default_category_id'] ?? '')) === '') {
            return ['ready' => false, 'failed' => 'category_id_missing'];
        }

        $token = $this->client->get_access_token();
        if (is_wp_error($token)) {
            return ['ready' => false, 'failed' => 'oauth', 'error' => $token->get_error_message()];
        }

        $checks = [
            'fulfillment_policy' => $this->client->get_policies('fulfillment_policy', $marketplaceId),
            'payment_policy' => $this->client->get_policies('payment_policy', $marketplaceId),
            'return_policy' => $this->client->get_policies('return_policy', $marketplaceId),
            'locations' => $this->client->get_locations(),
        ];

        foreach ($checks as $k => $v) {
            if (is_wp_error($v)) {
                return [
                    'ready' => false,
                    'failed' => $k,
                    'error' => $v->get_error_message(),
                    'error_details' => $v->get_error_data(),
                ];
            }
        }

        $locations = is_array($checks['locations']['locations'] ?? null) ? $checks['locations']['locations'] : [];
        if (count($locations) < 1) {
            return ['ready' => false, 'failed' => 'locations', 'details' => $checks];
        }

        $requiredPolicyIds = [
            'fulfillment_policy' => (string) ($settings['ebay_fulfillment_policy_id'] ?? ''),
            'payment_policy' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'return_policy' => (string) ($settings['ebay_return_policy_id'] ?? ''),
        ];

        if (in_array('', $requiredPolicyIds, true)) {
            return ['ready' => false, 'failed' => 'policy_id_missing', 'details' => $checks, 'required_policy_ids' => $requiredPolicyIds];
        }

        $policySeen = [
            'fulfillment_policy' => $this->policy_id_exists($checks['fulfillment_policy']['fulfillmentPolicies'] ?? [], $requiredPolicyIds['fulfillment_policy']),
            'payment_policy' => $this->policy_id_exists($checks['payment_policy']['paymentPolicies'] ?? [], $requiredPolicyIds['payment_policy']),
            'return_policy' => $this->policy_id_exists($checks['return_policy']['returnPolicies'] ?? [], $requiredPolicyIds['return_policy']),
        ];

        foreach ($policySeen as $key => $seen) {
            if (!$seen) {
                return ['ready' => false, 'failed' => $key . '_not_found', 'details' => $checks, 'required_policy_ids' => $requiredPolicyIds];
            }
        }

        return ['ready' => true, 'details' => $checks];
    }

    public function upsert_inventory_location(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        $merchantLocationKey = (string) ($settings['inventory_location_key'] ?? 'gpswiss-pl');
        $name = (string) ($settings['inventory_location_name'] ?? 'gpswiss-pl');
        $country = (string) ($settings['inventory_location_country'] ?? 'PL');
        $postalCode = (string) ($settings['inventory_location_postal_code'] ?? '08-460');
        $city = (string) ($settings['inventory_location_city'] ?? 'Sobolew');
        $addressLine1 = (string) ($settings['inventory_location_address_line_1'] ?? '');

        $res = $this->client->create_or_update_location($merchantLocationKey, [
            'location' => [
                'address' => [
                    'addressLine1' => $addressLine1,
                    'city' => $city,
                    'postalCode' => $postalCode,
                    'country' => $country,
                ],
            ],
            'name' => $name,
            'merchantLocationStatus' => 'ENABLED',
            'locationTypes' => ['WAREHOUSE'],
        ]);

        if (is_wp_error($res)) {
            return ['result' => 'error', 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()];
        }

        return ['result' => 'success', 'merchantLocationKey' => $merchantLocationKey];
    }

    public function export_product(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];

        $marketplaceId = $this->marketplace_id();
        $settings = $this->settings();
        $skuResolution = $this->resolve_ebay_sku($product, $product_id, $variation_id, $settings);
        $sku = $skuResolution['sku'];
        $content = $this->resolve_german_content($product, $product_id, $marketplaceId, $settings);
        $category = $this->resolve_category($product, $product_id, $sku, $marketplaceId, $settings);
        $categoryId = $category['category_id'];
        $aspects = $this->resolve_product_aspects($product, $product_id, $sku, $settings, $categoryId);
        $preflight = $this->preflight_validate($product, $product_id, $skuResolution, $content, $category, $aspects, $settings);
        update_post_meta($product_id, '_wei_ebay_export_status', $preflight['status']);
        if (!$preflight['ready']) {
            $this->logger->error('Product not ready for eBay export', $preflight);
            return ['result' => 'error', 'error' => $preflight['status'], 'message' => $preflight['message'], 'details' => $preflight];
        }

        $itemPayload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => 'NEW',
            'product' => [
                'title' => $content['title'],
                'description' => $content['description'],
                'imageUrls' => array_values(array_filter(array_map('wp_get_attachment_url', array_merge([$product->get_image_id()], $product->get_gallery_image_ids())))),
                'aspects' => $aspects,
            ],
        ];

        $this->logger->info('eBay product.aspects before createOrReplaceInventoryItem', [
            'stage' => 'createOrReplaceInventoryItem',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
            'product_aspects' => $aspects,
        ]);

        $item = $this->client->create_or_replace_inventory_item($sku, $itemPayload, [
            'stage' => 'createOrReplaceInventoryItem',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId ?? null,
        ]);
        if (is_wp_error($item)) return $this->export_error_response('createOrReplaceInventoryItem', $item, $product_id, $sku);

        $policyValidation = $this->validate_selected_policies($settings);
        if (!$policyValidation['valid']) {
            return [
                'result' => 'error',
                'error' => 'business_policy_ids_missing_or_invalid',
                'message' => 'Missing or invalid eBay Business Policy IDs. Refresh EBAY_DE policies and select fulfillmentPolicyId, paymentPolicyId and returnPolicyId before export.',
                'details' => $policyValidation,
            ];
        }

        $priceValue = (float) $product->get_price();
        $priceCurrency = $this->offer_currency($marketplaceId);
        if ($marketplaceId === 'EBAY_DE') {
            // TODO: replace fixed 4.25 with dynamic NBP exchange rate
            $priceValue = round($priceValue / 4.25, 2);
        }

        $listingPolicies = [
            'fulfillmentPolicyId' => (string) ($settings['ebay_fulfillment_policy_id'] ?? ''),
            'paymentPolicyId' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'returnPolicyId' => (string) ($settings['ebay_return_policy_id'] ?? ''),
        ];

        $offerPayload = [
            'sku' => $sku,
            'marketplaceId' => $marketplaceId,
            'merchantLocationKey' => $this->merchant_location_key(),
            'categoryId' => $categoryId,
            'listingPolicies' => $listingPolicies,
            'format' => 'FIXED_PRICE',
            'availableQuantity' => max(0, (int) $product->get_stock_quantity()),
            'listingDuration' => 'GTC',
            'pricingSummary' => ['price' => ['value' => (string) $priceValue, 'currency' => $priceCurrency]],
        ];
        $mapping = $this->repo->find_by_product($product_id, $variation_id);
        $offer_id = trim((string) ($mapping['remote_offer_id'] ?? ''));

        if ($offer_id === '') {
            $this->logger->info('eBay offer payload before createOffer', [
                'stage' => 'createOffer',
                'product_id' => $product_id,
                'sku' => $sku,
                'marketplace_id' => $marketplaceId,
                'category_id' => $categoryId,
                'offer_payload' => $offerPayload,
            ]);

            $offer = $this->client->create_offer($offerPayload, [
                'stage' => 'createOffer',
                'product_id' => $product_id,
                'sku' => $sku,
                'marketplace_id' => $marketplaceId,
                'category_id' => $categoryId,
            ]);

            if (is_wp_error($offer)) {
                $error_data = $offer->get_error_data();
                $response_body = is_array($error_data) && isset($error_data['response_body']) && is_array($error_data['response_body'])
                    ? $error_data['response_body']
                    : [];

                $existing_offer_id = '';
                $errors = is_array($response_body['errors'] ?? null) ? $response_body['errors'] : [];
                foreach ($errors as $error_entry) {
                    if (!is_array($error_entry)) {
                        continue;
                    }

                    $parameters = is_array($error_entry['parameters'] ?? null) ? $error_entry['parameters'] : [];
                    foreach ($parameters as $parameter) {
                        if (!is_array($parameter)) {
                            continue;
                        }

                        if ((string) ($parameter['name'] ?? '') !== 'offerId') {
                            continue;
                        }

                        $existing_offer_id = trim((string) ($parameter['value'] ?? ''));
                        if ($existing_offer_id !== '') {
                            break 2;
                        }
                    }
                }
                $error_message = strtolower((string) ($response_body['message'] ?? ''));
                if ($error_message === '' && isset($errors[0]) && is_array($errors[0])) {
                    $error_message = strtolower((string) ($errors[0]['message'] ?? ''));
                }
                $is_offer_exists = $existing_offer_id !== '' && str_contains($error_message, 'offer entity already exists');

                if (!$is_offer_exists) {
                    return $this->export_error_response('createOffer', $offer, $product_id, $sku);
                }

                $offer_id = $existing_offer_id;
                $this->logger->info('Existing offer detected, switching to update flow: ' . $offer_id, [
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'marketplace_id' => $marketplaceId,
                    'category_id' => $categoryId,
                ]);
            } else {
                $offer_id = (string) ($offer['offerId'] ?? '');
            }
        }

        if ($offer_id === '') {
            return $this->export_error_response('createOffer', new \WP_Error('wei_offer_missing', 'Missing offerId', [
                'stage' => 'createOffer',
                'product_id' => $product_id,
                'sku' => $sku,
            ]), $product_id, $sku);
        }

        $this->logger->info('eBay offer payload before updateOffer', [
            'stage' => 'updateOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'offer_id' => $offer_id,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
            'offer_payload' => $offerPayload,
        ]);

        $updated = $this->client->update_offer($offer_id, $offerPayload, [
            'stage' => 'updateOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
        ]);
        if (is_wp_error($updated)) return $this->export_error_response('updateOffer', $updated, $product_id, $sku);

        $policyValidation = $this->validate_selected_policies($this->settings());
        if (!$policyValidation['valid']) {
            return [
                'result' => 'error',
                'error' => 'business_policy_ids_missing_or_invalid_before_publish',
                'message' => 'Cannot publish eBay offer because one or more selected EBAY_DE Business Policy IDs are missing or no longer exist in cached policies.',
                'details' => $policyValidation,
            ];
        }

        $published = $this->client->publish_offer($offer_id, [
            'stage' => 'publishOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
        ]);
        if (is_wp_error($published)) return $this->export_error_response('publishOffer', $published, $product_id, $sku);

        $this->logger->info('eBay publishOffer response', [
            'stage' => 'publishOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'offer_id' => $offer_id,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
            'response' => $published,
        ]);

        $listing_id = (string) ($published['listingId'] ?? '');
        update_post_meta($product_id, '_wei_ebay_sku', $sku);
        update_post_meta($product_id, '_wei_ebay_offer_id', $offer_id);
        update_post_meta($product_id, '_wei_ebay_item_id', $listing_id);
        update_post_meta($product_id, '_wei_ebay_export_status', $listing_id !== '' ? 'published' : 'exported');
        $this->repo->upsert([
            'marketplace' => 'ebay',
            'woo_product_id' => $product_id,
            'woo_variation_id' => $variation_id,
            'sku' => $sku,
            'remote_inventory_id' => $sku,
            'remote_offer_id' => $offer_id,
            'remote_listing_id' => $listing_id,
            'marketplace_id' => $marketplaceId,
            'status' => 'active',
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['result' => 'success', 'offer_id' => $offer_id, 'listing_id' => $listing_id, 'inventory_id' => $sku, 'aspects' => $aspects, 'content_source' => $content['source'], 'sku_resolution' => $skuResolution];
    }

    private function marketplace_id(): string
    {
        $settings = $this->settings();
        $marketplaceId = (string) ($settings['marketplace_id'] ?? 'EBAY_DE');
        return $marketplaceId !== '' ? $marketplaceId : 'EBAY_DE';
    }

    private function merchant_location_key(): string
    {
        $settings = $this->settings();
        $merchantLocationKey = (string) ($settings['inventory_location_key'] ?? 'gpswiss-pl');
        return $merchantLocationKey !== '' ? $merchantLocationKey : 'gpswiss-pl';
    }

    private function offer_currency(string $marketplaceId): string
    {
        if ($marketplaceId === 'EBAY_DE') {
            return 'EUR';
        }

        return get_woocommerce_currency();
    }

    public function refresh_policies(): array
    {
        $marketplaceId = $this->marketplace_id();

        $fulfillment = $this->client->get_policies('fulfillment_policy', $marketplaceId);
        $payment = $this->client->get_policies('payment_policy', $marketplaceId);
        $return = $this->client->get_policies('return_policy', $marketplaceId);

        foreach (['fulfillment' => $fulfillment, 'payment' => $payment, 'return' => $return] as $key => $res) {
            if (is_wp_error($res)) {
                return ['result' => 'error', 'failed' => $key, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()];
            }
        }

        $settings = $this->settings();
        $settings['wei_cached_policies'] = [
            'marketplace_id' => $marketplaceId,
            'fulfillmentPolicies' => $fulfillment['fulfillmentPolicies'] ?? [],
            'paymentPolicies' => $payment['paymentPolicies'] ?? [],
            'returnPolicies' => $return['returnPolicies'] ?? [],
        ];
        update_option(Plugin::OPTION_KEY, $settings, false);

        $counts = [
            'fulfillment' => count($settings['wei_cached_policies']['fulfillmentPolicies']),
            'payment' => count($settings['wei_cached_policies']['paymentPolicies']),
            'return' => count($settings['wei_cached_policies']['returnPolicies']),
        ];

        $this->logger->info('Marketplace used for policies: ' . $marketplaceId, [
            'marketplace_id' => $marketplaceId,
            'counts' => $counts,
        ]);
        $this->log_policy_details('Fulfillment policy', $settings['wei_cached_policies']['fulfillmentPolicies'], 'fulfillmentPolicyId', true);
        $this->log_policy_details('Payment policy', $settings['wei_cached_policies']['paymentPolicies'], 'paymentPolicyId');
        $this->log_policy_details('Return policy', $settings['wei_cached_policies']['returnPolicies'], 'returnPolicyId');

        return ['result' => 'success', 'marketplace_id' => $marketplaceId, 'counts' => $counts];
    }



    public function preflight_product(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return ['ready' => false, 'status' => 'not_ready', 'message' => 'Product not found.'];
        }

        $settings = $this->settings();
        $marketplaceId = $this->marketplace_id();
        $skuResolution = $this->resolve_ebay_sku($product, $product_id, $variation_id, $settings);
        $content = $this->resolve_german_content($product, $product_id, $marketplaceId, $settings);
        $category = $this->resolve_category($product, $product_id, $skuResolution['sku'], $marketplaceId, $settings);
        $aspects = $this->resolve_product_aspects($product, $product_id, $skuResolution['sku'], $settings, $category['category_id']);
        $preflight = $this->preflight_validate($product, $product_id, $skuResolution, $content, $category, $aspects, $settings);
        update_post_meta($product_id, '_wei_ebay_export_status', $preflight['status']);
        return $preflight;
    }

    private function resolve_ebay_sku($product, int $product_id, ?int $variation_id, array $settings): array
    {
        $wooSku = trim((string) $product->get_sku());
        $stored = trim((string) get_post_meta($variation_id ?: $product_id, '_wei_ebay_sku', true));
        $useWooSku = !empty($settings['use_woo_sku_for_ebay']) && $wooSku !== '';
        $generated = false;

        if ($useWooSku) {
            $final = $wooSku;
        } elseif ($stored !== '') {
            $final = $stored;
        } else {
            $final = 'GPSW-' . (string) ($variation_id ?: $product_id);
            update_post_meta($variation_id ?: $product_id, '_wei_ebay_sku', $final);
            $generated = true;
        }

        if (!empty($settings['write_generated_sku_to_woo']) && $wooSku === '' && $final !== '') {
            // Safety default is OFF. Only write when an admin explicitly opts in.
            $product->set_sku($final);
            $product->save();
        }

        $context = ['product_id' => $product_id, 'variation_id' => $variation_id, 'woo_sku' => $wooSku, 'wei_ebay_sku' => $stored ?: $final, 'generated' => $generated, 'final_sku_used_for_ebay' => $final];
        $this->logger->info('Resolved eBay SKU', $context);
        return ['sku' => $final, 'woo_sku' => $wooSku, 'wei_ebay_sku' => $stored ?: $final, 'generated' => $generated];
    }

    private function resolve_german_content($product, int $product_id, string $marketplaceId, array $settings): array
    {
        if ($marketplaceId !== 'EBAY_DE') {
            return ['ready' => true, 'title' => (string) $product->get_name(), 'description' => (string) $product->get_description(), 'source' => 'default', 'language' => ''];
        }

        $currentHash = $this->german_content_source_hash($product);
        $storedHash = trim((string) get_post_meta($product_id, '_wei_ebay_de_content_hash', true));
        $metaTitle = trim((string) get_post_meta($product_id, '_wei_ebay_de_title', true));
        $metaDescription = trim((string) get_post_meta($product_id, '_wei_ebay_de_description', true));
        if ($metaTitle !== '' && $metaDescription !== '') {
            $stale = $storedHash !== '' && $storedHash !== $currentHash;
            if ($stale && !empty($settings['regenerate_german_content_on_hash_change'])) {
                return $this->maybe_generate_german_content($product, $product_id, $settings, 'stale_meta_hash_changed');
            }
            $metaSource = trim((string) get_post_meta($product_id, '_wei_ebay_de_content_source', true));
            if ($metaSource === '') {
                $metaSource = 'custom_meta';
            }
            return $this->log_german_content($product_id, $product_id, $metaSource, $metaTitle, $metaDescription, [
                'generated' => false,
                'stale' => $stale,
                'content_hash' => $storedHash ?: $currentHash,
                'current_content_hash' => $currentHash,
                'description_length' => mb_strlen($metaDescription),
            ]);
        }

        if (function_exists('wpml_object_id_filter')) {
            $translatedId = (int) wpml_object_id_filter($product_id, 'product', false, 'de');
            if ($translatedId > 0 && $translatedId !== $product_id) {
                return $this->content_from_product_id($product_id, $translatedId, 'wpml');
            }
        }

        if (function_exists('pll_get_post')) {
            $translatedId = (int) pll_get_post($product_id, 'de');
            if ($translatedId > 0 && $translatedId !== $product_id) {
                return $this->content_from_product_id($product_id, $translatedId, 'polylang');
            }
        }

        if (class_exists('TRP_Translate_Press')) {
            $metaTitle = trim((string) get_post_meta($product_id, '_wei_translatepress_de_title', true));
            $metaDescription = trim((string) get_post_meta($product_id, '_wei_translatepress_de_description', true));
            if ($metaTitle !== '' || $metaDescription !== '') {
                return $this->log_german_content($product_id, $product_id, 'translatepress_meta', $metaTitle, $metaDescription);
            }
        }

        return $this->maybe_generate_german_content($product, $product_id, $settings, 'missing');
    }

    private function content_from_product_id(int $sourceProductId, int $translatedProductId, string $source): array
    {
        $translated = wc_get_product($translatedProductId);
        if (!$translated) {
            return $this->log_german_content($sourceProductId, $translatedProductId, $source, '', '');
        }

        return $this->log_german_content($sourceProductId, $translatedProductId, $source, (string) $translated->get_name(), (string) $translated->get_description());
    }

    private function log_german_content(int $sourceProductId, int $translatedProductId, string $source, string $title, string $description, array $extra = []): array
    {
        $title = trim(wp_strip_all_tags($title));
        $description = trim((string) $description);
        $result = array_merge([
            'ready' => $title !== '' && $description !== '',
            'title' => $title,
            'description' => $description,
            'source' => $source,
            'language' => 'de-DE',
            'source_product_id' => $sourceProductId,
            'translated_product_id' => $translatedProductId,
            'title_found' => $title !== '',
            'description_found' => $description !== '',
            'title_length' => mb_strlen($title),
            'description_length' => mb_strlen($description),
        ], $extra);
        $this->logger->info('Resolved German content for EBAY_DE', $result);
        return $result;
    }

    private function maybe_generate_german_content($product, int $product_id, array $settings, string $reason): array
    {
        $providerKey = $this->configured_translation_provider_key($settings);
        $hash = $this->german_content_source_hash($product);
        $baseLog = [
            'product_id' => $product_id,
            'source_language' => 'pl-PL',
            'target_language' => 'de-DE',
            'provider' => $providerKey,
            'generated' => false,
            'content_hash' => $hash,
            'source_used' => $reason,
            'ebay_write_calls' => false,
            'message' => 'Preflight-only German content generation writes plugin meta _wei_* only; no publishOffer/createOrReplaceInventoryItem/createOffer/updateOffer calls are executed.',
        ];

        if (empty($settings['auto_generate_german_content_preflight'])) {
            $message = 'German content missing and automatic generation during preflight is disabled.';
            $this->logger->warning('German content generator skipped', array_merge($baseLog, ['error_message' => $message]));
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }

        $provider = $this->translation_provider($settings);
        if (!$provider || !$provider->is_configured()) {
            $message = 'German content missing and translation provider is not configured.';
            $this->logger->warning('German content generator unavailable', array_merge($baseLog, ['error_message' => $message]));
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }

        try {
            $sourceTitle = (string) $product->get_name();
            $sourceDescription = trim((string) $product->get_description());
            if ($sourceDescription === '') {
                $sourceDescription = trim((string) $product->get_short_description());
            }
            $translated = $provider->translate_product_content($product, [
                'source_language' => 'pl-PL',
                'target_language' => 'de-DE',
                'source_title' => $sourceTitle,
                'source_description' => $sourceDescription,
                'mpn' => $this->resolve_mpn_aspect_value($product, $product_id, (string) $product->get_sku()),
                'manufacturer' => $this->resolve_hersteller_aspect_value($product, $product_id, $settings, ''),
                'title_limit' => 80,
            ]);

            $title = trim(wp_strip_all_tags((string) ($translated['title_de'] ?? '')));
            if (mb_strlen($title) > 80) {
                $title = trim(mb_substr($title, 0, 80));
            }
            $description = trim(wp_kses_post((string) ($translated['description_de'] ?? '')));
            if ($title === '' || $description === '') {
                throw new \RuntimeException('Translation provider returned empty German title or description.');
            }

            update_post_meta($product_id, '_wei_ebay_de_title', $title);
            update_post_meta($product_id, '_wei_ebay_de_description', $description);
            update_post_meta($product_id, '_wei_ebay_de_content_source', 'generated_' . $provider->provider_key());
            update_post_meta($product_id, '_wei_ebay_de_content_generated_at', gmdate('c'));
            update_post_meta($product_id, '_wei_ebay_de_content_hash', $hash);

            $extra = array_merge($baseLog, [
                'provider' => $provider->provider_key(),
                'generated' => true,
                'source_used' => 'generated_' . $provider->provider_key(),
                'title_length' => mb_strlen($title),
                'description_length' => mb_strlen($description),
            ]);
            $this->logger->info('German content generated and saved to plugin meta only', $extra);
            return $this->log_german_content($product_id, $product_id, 'generated_' . $provider->provider_key(), $title, $description, $extra);
        } catch (\Throwable $e) {
            $message = 'German content generation failed: ' . $e->getMessage();
            $this->logger->error('German content generator failed', array_merge($baseLog, ['error_message' => $message]));
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }
    }

    private function configured_translation_provider_key(array $settings): string
    {
        $provider = strtolower(trim((string) ($settings['translation_provider'] ?? 'disabled')));
        if ($provider === '' || !in_array($provider, ['disabled', 'openai', 'deepl', 'google'], true)) {
            return 'disabled';
        }
        if ($provider !== 'disabled' && trim((string) ($settings['translation_api_key'] ?? '')) === '') {
            return 'disabled';
        }
        return $provider;
    }

    private function translation_provider(array $settings): ?TranslationProviderInterface
    {
        $provider = $this->configured_translation_provider_key($settings);
        if ($provider === 'openai') {
            return new OpenAiTranslationProvider($settings, $this->logger);
        }
        return null;
    }

    private function german_content_source_hash($product): string
    {
        return hash('sha256', wp_json_encode([
            'title' => (string) $product->get_name(),
            'description' => (string) $product->get_description(),
            'short_description' => (string) $product->get_short_description(),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function resolve_category($product, int $product_id, string $sku, string $marketplaceId, array $settings): array
    {
        $override = trim((string) get_post_meta($product_id, '_wei_ebay_category_id', true));
        if ($override !== '') {
            return ['category_id' => $override, 'status' => 'ready_manual', 'source' => 'product_override', 'confidence' => 1.0];
        }

        $terms = wp_get_post_terms($product_id, 'product_cat');
        if (!is_wp_error($terms)) {
            foreach ((array) $terms as $term) {
                $mapping = $this->categoryRepo->find($marketplaceId, (int) $term->term_id);
                if ($mapping && trim((string) ($mapping['ebay_category_id'] ?? '')) !== '') {
                    return ['category_id' => (string) $mapping['ebay_category_id'], 'status' => 'ready_manual', 'source' => 'woo_category_mapping', 'mapping' => $mapping, 'confidence' => (float) $mapping['confidence']];
                }
            }
        }

        $skuOverrides = $this->parse_sku_category_overrides((string) ($settings['sku_category_overrides'] ?? ''));
        if (isset($skuOverrides[$sku]) && $skuOverrides[$sku] !== '') {
            return ['category_id' => $skuOverrides[$sku], 'status' => 'ready_manual', 'source' => 'debug_sku_override', 'confidence' => 1.0];
        }

        if (!is_wp_error($terms) && !empty($terms[0])) {
            $query = $this->categoryRepo->woo_category_path((int) $terms[0]->term_id) . ' ' . $product->get_name();
            $suggestions = $this->taxonomy->get_category_suggestions($marketplaceId, $query);
            $first = $suggestions[0] ?? [];
            $category = is_array($first['category'] ?? null) ? $first['category'] : [];
            $categoryId = trim((string) ($category['categoryId'] ?? ''));
            $score = isset($first['categoryTreeNodeAncestors']) ? 0.6 : 0.5;
            if ($categoryId !== '' && $score >= 0.75) {
                return ['category_id' => $categoryId, 'status' => 'ready_auto', 'source' => 'taxonomy_suggestion', 'confidence' => $score, 'suggestion' => $first];
            }
        }

        $default = trim((string) ($settings['default_category_id'] ?? ''));
        if ($default !== '') {
            return ['category_id' => $default, 'status' => 'ready_manual', 'source' => 'default_category', 'confidence' => 1.0];
        }

        return ['category_id' => '', 'status' => 'needs_category_review', 'source' => 'missing', 'confidence' => 0.0];
    }

    private function preflight_validate($product, int $product_id, array $skuResolution, array $content, array $category, array $aspects, array $settings): array
    {
        $errors = [];
        $status = 'ready';
        $categoryId = (string) ($category['category_id'] ?? '');
        $requiredAspects = $this->taxonomy->get_required_aspects($this->marketplace_id(), $categoryId);
        $missingAspects = array_values(array_filter($requiredAspects, static fn($name) => empty($aspects[$name])));

        if ($skuResolution['sku'] === '') $errors[] = 'final eBay SKU missing';
        if (empty($content['title']) || empty($content['description'])) { $errors[] = (string) ($content['error_message'] ?? 'German title/description missing'); $status = 'not_ready_missing_german_content'; }
        if (!empty($content['title']) && mb_strlen((string) $content['title']) > 80) $errors[] = 'German title is longer than 80 characters';
        if ($categoryId === '') { $errors[] = 'eBay leaf category missing'; $status = 'needs_category_review'; }
        if ($missingAspects !== []) { $errors[] = 'missing required aspect ' . implode(', ', $missingAspects); $status = 'missing_required_aspects'; }
        if (!$this->validate_selected_policies($settings)['valid']) $errors[] = 'business policies missing or invalid';
        if ((float) $product->get_price() <= 0) $errors[] = 'price invalid';
        if ((int) $product->get_stock_quantity() < 0) $errors[] = 'stock invalid';
        if (!$product->get_image_id()) $errors[] = 'image missing';
        if ($this->merchant_location_key() === '') $errors[] = 'inventory location missing';

        $ready = $errors === [];
        $message = $ready ? 'Product ready for eBay export.' : 'Product not ready for eBay: ' . implode('; ', $errors) . '.';
        if (in_array('Hersteller', $missingAspects, true)) {
            $message = 'Product not ready for eBay: missing required aspect Hersteller. Configure brand/manufacturer mapping.';
        }

        return ['ready' => $ready, 'status' => $ready ? 'ready' : $status, 'message' => $message, 'product_id' => $product_id, 'sku_resolution' => $skuResolution, 'content' => $content, 'category' => $category, 'required_aspects' => $requiredAspects, 'missing_aspects' => $missingAspects, 'aspects' => $aspects, 'errors' => $errors];
    }

    private function parse_category_aspect_fallbacks(string $raw): array
    {
        $fallbacks = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts = preg_split('/\s*[=|]\s*/', $line, 3);
            if (!is_array($parts) || count($parts) !== 3) continue;
            [$categoryId, $aspect, $value] = array_map('trim', $parts);
            if ($categoryId !== '' && $aspect !== '' && $value !== '') {
                $fallbacks[$categoryId][$aspect] = [$value];
            }
        }
        return $fallbacks;
    }

    private function resolve_product_aspects($product, int $product_id, string $sku, array $settings, string $categoryId = ''): array
    {
        $aspects = [];

        $mpn = $this->resolve_mpn_aspect_value($product, $product_id, $sku);
        if ($mpn !== '') {
            $aspects['MPN'] = [$mpn];
        }

        if (method_exists($product, 'get_attributes')) {
            foreach ((array) $product->get_attributes() as $attribute) {
                $name = '';
                $values = [];

                if (is_object($attribute) && method_exists($attribute, 'get_name')) {
                    $name = wc_attribute_label((string) $attribute->get_name(), $product);
                    if (method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy() && method_exists($attribute, 'get_terms')) {
                        foreach ((array) $attribute->get_terms() as $term) {
                            if (is_object($term) && isset($term->name)) {
                                $values[] = (string) $term->name;
                            }
                        }
                    } elseif (method_exists($attribute, 'get_options')) {
                        $values = array_map('strval', (array) $attribute->get_options());
                    }
                }

                $name = trim(wp_strip_all_tags($name));
                $values = $this->normalize_aspect_values($values);
                if ($name !== '' && $values !== []) {
                    $aspects[$name] = $values;
                }
            }
        }

        $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id, $categoryId, $settings);
        if ($manufacturer !== '') {
            $aspects['Hersteller'] = [$manufacturer];
        }

        $productOverrides = $this->parse_aspects_json((string) get_post_meta($product_id, '_wei_ebay_aspects_json', true));
        $settingsOverrides = $this->resolve_configured_aspect_overrides($sku, $settings);

        $resolved = $this->merge_aspects($aspects, $settingsOverrides, $productOverrides);
        $required = $this->taxonomy->get_required_aspects($this->marketplace_id(), $categoryId);
        $missing = array_values(array_filter($required, static fn($name) => empty($resolved[$name])));
        $this->logger->info('Required aspects for category ' . $categoryId . ': ' . implode(', ', $required), [
            'product_id' => $product_id,
            'sku' => $sku,
            'category_id' => $categoryId,
            'required_aspects' => $required,
            'missing_aspects' => $missing,
            'final_product_aspects' => $resolved,
        ]);

        return $resolved;
    }

    private function resolve_mpn_aspect_value($product, int $product_id, string $sku): string
    {
        foreach (['_mpn', 'mpn', '_part_number', 'part_number', '_oem_number', 'oem_number', '_oe_number'] as $metaKey) {
            $value = trim((string) get_post_meta($product_id, $metaKey, true));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['MPN', 'Herstellernummer', 'OEM', 'Numer części', 'Numer czesci'] as $attributeName) {
            if (!method_exists($product, 'get_attribute')) {
                continue;
            }
            $value = trim(wp_strip_all_tags((string) $product->get_attribute($attributeName)));
            if ($value !== '') {
                return $value;
            }
        }

        return $sku;
    }

    private function resolve_manufacturer_aspect_value($product, int $product_id, string $categoryId, array $settings): string
    {
        foreach (['_manufacturer', '_brand'] as $metaKey) {
            $value = trim((string) get_post_meta($product_id, $metaKey, true));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['Producent', 'Marka', 'Manufacturer'] as $attributeName) {
            if (!method_exists($product, 'get_attribute')) {
                continue;
            }

            $value = trim(wp_strip_all_tags((string) $product->get_attribute($attributeName)));
            if ($value !== '') {
                return $value;
            }
        }

        $brandTaxonomies = ['product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand', 'pa_marka'];
        foreach ($brandTaxonomies as $taxonomy) {
            $terms = taxonomy_exists($taxonomy) ? wp_get_post_terms($product_id, $taxonomy) : [];
            if (!is_wp_error($terms) && !empty($terms[0]->name)) {
                return (string) $terms[0]->name;
            }
        }

        $fallbacks = $this->parse_category_aspect_fallbacks((string) ($settings['category_aspect_fallbacks'] ?? ''));
        if ($categoryId !== '' && !empty($fallbacks[$categoryId]['Hersteller'][0])) {
            return (string) $fallbacks[$categoryId]['Hersteller'][0];
        }

        $global = trim((string) ($settings['default_hersteller_fallback'] ?? ''));
        return $global;
    }

    private function resolve_configured_aspect_overrides(string $sku, array $settings): array
    {
        $configured = $this->parse_aspects_json((string) ($settings['sku_aspect_overrides'] ?? ''));
        if ($configured === []) {
            return [];
        }

        $overrides = [];
        foreach (['*', '_global', 'global'] as $globalKey) {
            if (isset($configured[$globalKey]) && is_array($configured[$globalKey])) {
                $overrides = $this->merge_aspects($overrides, $configured[$globalKey]);
            }
        }

        if (isset($configured[$sku]) && is_array($configured[$sku])) {
            $overrides = $this->merge_aspects($overrides, $configured[$sku]);
        }

        return $overrides;
    }

    private function parse_aspects_json(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $name => $values) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            if (is_array($values) && $this->is_assoc($values)) {
                $normalized[$name] = $this->normalize_aspects($values);
                continue;
            }

            $valueList = $this->normalize_aspect_values($values);
            if ($valueList !== []) {
                $normalized[$name] = $valueList;
            }
        }

        return $normalized;
    }

    private function normalize_aspects(array $aspects): array
    {
        $normalized = [];
        foreach ($aspects as $name => $values) {
            $name = trim((string) $name);
            $valueList = $this->normalize_aspect_values($values);
            if ($name !== '' && $valueList !== []) {
                $normalized[$name] = $valueList;
            }
        }

        return $normalized;
    }

    private function normalize_aspect_values($values): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $value = trim(wp_strip_all_tags((string) $value));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function merge_aspects(array ...$aspectSets): array
    {
        $merged = [];
        foreach ($aspectSets as $aspectSet) {
            foreach ($aspectSet as $name => $values) {
                $name = trim((string) $name);
                $valueList = $this->normalize_aspect_values($values);
                if ($name !== '' && $valueList !== []) {
                    $merged[$name] = $valueList;
                }
            }
        }

        return $merged;
    }

    private function is_assoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function resolve_category_id(int $product_id, string $sku, array $settings): string
    {
        $productCategoryId = trim((string) get_post_meta($product_id, '_wei_ebay_category_id', true));
        if ($productCategoryId !== '') {
            return $productCategoryId;
        }

        $skuOverrides = $this->parse_sku_category_overrides((string) ($settings['sku_category_overrides'] ?? ''));
        if (isset($skuOverrides[$sku]) && $skuOverrides[$sku] !== '') {
            return $skuOverrides[$sku];
        }

        return trim((string) ($settings['default_category_id'] ?? ''));
    }

    private function parse_sku_category_overrides(string $raw): array
    {
        $overrides = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s*[=:,]\s*/', $line, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $sku = trim((string) $parts[0]);
            $categoryId = trim((string) $parts[1]);
            if ($sku !== '' && $categoryId !== '') {
                $overrides[$sku] = $categoryId;
            }
        }

        return $overrides;
    }

    private function export_error_response(string $stage, \WP_Error $error, int $product_id, string $sku): array
    {
        $errorData = $error->get_error_data();
        $details = is_array($errorData) ? $errorData : [];
        $responseBody = is_array($details['response_body'] ?? null) ? $details['response_body'] : [];
        $ebayErrors = $this->extract_ebay_errors($responseBody);
        $primaryEbayError = $ebayErrors[0] ?? [];
        $errorId = (string) ($primaryEbayError['errorId'] ?? '');
        $marketplaceId = (string) ($details['marketplace_id'] ?? $this->marketplace_id());
        $requestPayload = is_array($details['request_payload'] ?? null) ? $details['request_payload'] : [];
        $categoryId = (string) ($details['category_id'] ?? $requestPayload['categoryId'] ?? '');
        $missingAspects = $this->extract_missing_aspects($ebayErrors);
        $message = $this->admin_error_message($errorId, (string) ($primaryEbayError['message'] ?? $error->get_error_message()), $missingAspects);

        $logContext = [
            'stage' => $stage,
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
            'wp_error_code' => $error->get_error_code(),
            'wp_error_message' => $error->get_error_message(),
            'ebay_error_id' => $errorId,
            'ebay_error_message' => (string) ($primaryEbayError['message'] ?? ''),
            'ebay_errors' => $ebayErrors,
            'missing_aspects' => $missingAspects,
            'error_details' => $details,
        ];
        $this->logger->error('eBay export failed without fatal error', $logContext);

        return [
            'result' => 'error',
            'stage' => $stage,
            'error' => $error->get_error_code(),
            'message' => $message,
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
            'ebay_error_id' => $errorId,
            'ebay_errors' => $ebayErrors,
            'missing_aspects' => $missingAspects,
            'error_details' => $details,
        ];
    }

    private function extract_ebay_errors(array $responseBody): array
    {
        $errors = is_array($responseBody['errors'] ?? null) ? $responseBody['errors'] : [];
        if ($errors === [] && $responseBody !== []) {
            $errors[] = $responseBody;
        }

        return array_values(array_filter($errors, 'is_array'));
    }

    private function admin_error_message(string $errorId, string $fallback, array $missingAspects = []): string
    {
        if ($missingAspects !== []) {
            return 'Missing required eBay item specifics/aspects: ' . implode(', ', $missingAspects) . '. Add them in eBay Aspects / Item specifics JSON, e.g. "Hersteller": ["SEAT"].';
        }

        if ($errorId === '25005') {
            return 'Invalid category ID. Selected eBay category is not a leaf category. Choose a final EBAY_DE category.';
        }

        return $fallback !== '' ? $fallback : 'eBay export failed. Check logs for the full API response.';
    }

    private function extract_missing_aspects(array $ebayErrors): array
    {
        $missing = [];
        foreach ($ebayErrors as $ebayError) {
            $messages = [
                (string) ($ebayError['message'] ?? ''),
                (string) ($ebayError['longMessage'] ?? ''),
            ];

            foreach ($messages as $message) {
                if (preg_match_all('/Artikelmerkmal\s+(.+?)\s+fehlt/iu', $message, $matches)) {
                    foreach ($matches[1] as $match) {
                        $missing[] = trim((string) $match, " \t\n\r\0\x0B.:");
                    }
                }

                if (preg_match_all('/(?:item specific|aspect)\s+["“]?(.+?)["”]?\s+(?:is\s+)?missing/iu', $message, $matches)) {
                    foreach ($matches[1] as $match) {
                        $missing[] = trim((string) $match, " \t\n\r\0\x0B.:");
                    }
                }
            }

            $parameters = is_array($ebayError['parameters'] ?? null) ? $ebayError['parameters'] : [];
            foreach ($parameters as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                $name = strtolower((string) ($parameter['name'] ?? ''));
                if (str_contains($name, 'aspect') || str_contains($name, 'specific')) {
                    $value = trim((string) ($parameter['value'] ?? ''));
                    if ($value !== '') {
                        $missing[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($missing)));
    }

    private function validate_selected_policies(array $settings): array
    {
        $cached = is_array($settings['wei_cached_policies'] ?? null) ? $settings['wei_cached_policies'] : [];
        $required = [
            'fulfillmentPolicyId' => (string) ($settings['ebay_fulfillment_policy_id'] ?? ''),
            'paymentPolicyId' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'returnPolicyId' => (string) ($settings['ebay_return_policy_id'] ?? ''),
        ];
        $policySets = [
            'fulfillmentPolicyId' => is_array($cached['fulfillmentPolicies'] ?? null) ? $cached['fulfillmentPolicies'] : [],
            'paymentPolicyId' => is_array($cached['paymentPolicies'] ?? null) ? $cached['paymentPolicies'] : [],
            'returnPolicyId' => is_array($cached['returnPolicies'] ?? null) ? $cached['returnPolicies'] : [],
        ];

        $currentMarketplaceId = $this->marketplace_id();
        $cachedMarketplaceId = (string) ($cached['marketplace_id'] ?? '');

        $missing = [];
        $invalid = [];
        if ($cachedMarketplaceId !== '' && $cachedMarketplaceId !== $currentMarketplaceId) {
            $invalid['marketplace_id'] = $cachedMarketplaceId;
        }

        foreach ($required as $field => $id) {
            if ($id === '') {
                $missing[] = $field;
                continue;
            }

            if (!$this->policy_id_exists($policySets[$field], $id)) {
                $invalid[$field] = $id;
            }
        }

        return [
            'valid' => empty($missing) && empty($invalid),
            'marketplace_id' => $currentMarketplaceId,
            'cached_marketplace_id' => $cachedMarketplaceId,
            'required_policy_ids' => $required,
            'missing' => $missing,
            'invalid' => $invalid,
        ];
    }

    private function log_policy_details(string $message, array $policies, string $idField, bool $includeShipping = false): void
    {
        foreach ($policies as $policy) {
            if (!is_array($policy)) {
                continue;
            }

            $details = [
                'id' => (string) ($policy[$idField] ?? ''),
                'name' => (string) ($policy['name'] ?? ''),
                'marketplaceId' => (string) ($policy['marketplaceId'] ?? ''),
            ];

            if ($includeShipping) {
                $details['shippingOptions'] = $policy['shippingOptions'] ?? [];
                $details['shippingServices'] = $this->extract_shipping_services($policy['shippingOptions'] ?? []);
            }

            $this->logger->info($message . ': ' . wp_json_encode($details), $details);
        }
    }

    private function extract_shipping_services($shippingOptions): array
    {
        if (!is_array($shippingOptions)) {
            return [];
        }

        $shippingServices = [];
        foreach ($shippingOptions as $shippingOption) {
            if (!is_array($shippingOption)) {
                continue;
            }

            $services = is_array($shippingOption['shippingServices'] ?? null) ? $shippingOption['shippingServices'] : [];
            foreach ($services as $service) {
                if (is_array($service)) {
                    $shippingServices[] = $service;
                }
            }
        }

        return $shippingServices;
    }

    private function settings(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        if (!isset($settings['sku_category_overrides'])) {
            $settings['sku_category_overrides'] = "CFM-001=179847";
        }
        if (!isset($settings['sku_aspect_overrides'])) {
            $settings['sku_aspect_overrides'] = wp_json_encode([
                'CFM-001' => [
                    'Hersteller' => ['SEAT'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if (!isset($settings['category_aspect_fallbacks'])) {
            $settings['category_aspect_fallbacks'] = "179847|Hersteller|SEAT";
        }
        if (!isset($settings['default_hersteller_fallback'])) {
            $settings['default_hersteller_fallback'] = '';
        }
        if (!isset($settings['use_woo_sku_for_ebay'])) {
            $settings['use_woo_sku_for_ebay'] = 1;
        }
        if (!isset($settings['write_generated_sku_to_woo'])) {
            $settings['write_generated_sku_to_woo'] = 0;
        }
        if (!isset($settings['stock_sync_mode'])) {
            $settings['stock_sync_mode'] = 'set_zero';
        }
        if (!isset($settings['translation_provider'])) {
            $settings['translation_provider'] = 'disabled';
        }
        if (!isset($settings['translation_api_key'])) {
            $settings['translation_api_key'] = '';
        }
        if (!isset($settings['auto_generate_german_content_preflight'])) {
            $settings['auto_generate_german_content_preflight'] = 1;
        }
        if (!isset($settings['regenerate_german_content_on_hash_change'])) {
            $settings['regenerate_german_content_on_hash_change'] = 0;
        }
        if (!isset($settings['translation_openai_model'])) {
            $settings['translation_openai_model'] = 'gpt-4o-mini';
        }
        return $settings;
    }

    private function policy_id_exists(array $policies, string $requiredId): bool
    {
        if ($requiredId === '') return false;
        foreach ($policies as $policy) {
            foreach (['fulfillmentPolicyId', 'paymentPolicyId', 'returnPolicyId'] as $field) {
                if ((string) ($policy[$field] ?? '') === $requiredId) {
                    return true;
                }
            }
        }
        return false;
    }

    public function sync_stock(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];
        $skuResolution = $this->resolve_ebay_sku($product, $product_id, $variation_id, $this->settings());
        $sku = $skuResolution['sku'];
        $map = $this->repo->find_by_sku($sku);
        if (!$map) return ['result' => 'skipped', 'reason' => 'mapping_not_found', 'sku_resolution' => $skuResolution];

        $res = $this->client->bulk_update_price_quantity([[
            'offerId' => $map['remote_offer_id'],
            'shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())],
            'price' => ['value' => (string) $product->get_price(), 'currency' => get_woocommerce_currency()],
        ]]);

        if (is_wp_error($res)) return ['result' => 'error', 'error' => $res->get_error_message()];

        $this->repo->upsert(array_merge($map, ['last_sync_at' => gmdate('Y-m-d H:i:s')]));
        return ['result' => 'success'];
    }

    public function import_orders(array $query = []): array
    {
        return $this->client->get_orders($query);
    }
}
