<?php

namespace WEI\Adapters;

use WEI\Plugin;

use WEI\Interfaces\MarketplaceAdapterInterface;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayClient;
use WEI\Services\EbayTaxonomyService;
use WEI\Services\EbaySkuGenerator;
use WEI\Services\EbayPriceResolver;
use WEI\Services\EbayConditionResolver;
use WEI\Services\EbayShippingPolicyResolver;
use WEI\Services\CategoryMappingSafety;
use WEI\Services\Logger;
use WEI\Services\Translation\GoogleCloudTranslateProvider;
use WEI\Services\Translation\OpenAiTranslationProvider;
use WEI\Interfaces\TranslationProviderInterface;

class EbayAdapter implements MarketplaceAdapterInterface
{
    private const EBAY_SKU_MAX_LENGTH = 50;
    private bool $suppressVerboseLogs = false;

    public function __construct(private EbayClient $client, private MappingRepository $repo, private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger, private ?EbaySkuGenerator $skuGenerator = null, private ?EbayPriceResolver $priceResolver = null)
    {
    }

    public function readiness_check(): array
    {
        $settings = $this->settings();
        $marketplaceId = $this->marketplace_id();

        if (trim((string) ($settings['marketplace_id'] ?? '')) === '') {
            return ['ready' => false, 'failed' => 'marketplace_id_missing'];
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
            'fulfillment_policy_30_eur' => EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_DEFAULT_30_EUR, $settings),
            'fulfillment_policy_50_eur' => EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_PARCEL_50_EUR, $settings),
            'fulfillment_policy_100_eur' => EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_PALLET_100_EUR, $settings),
            'payment_policy' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'return_policy' => (string) ($settings['ebay_return_policy_id'] ?? ''),
        ];

        if (in_array('', $requiredPolicyIds, true)) {
            return ['ready' => false, 'failed' => 'policy_id_missing', 'details' => $checks, 'required_policy_ids' => $requiredPolicyIds];
        }

        $policySeen = [
            'fulfillment_policy_30_eur' => $this->policy_id_exists($checks['fulfillment_policy']['fulfillmentPolicies'] ?? [], $requiredPolicyIds['fulfillment_policy_30_eur']),
            'fulfillment_policy_50_eur' => $this->policy_id_exists($checks['fulfillment_policy']['fulfillmentPolicies'] ?? [], $requiredPolicyIds['fulfillment_policy_50_eur']),
            'fulfillment_policy_100_eur' => $this->policy_id_exists($checks['fulfillment_policy']['fulfillmentPolicies'] ?? [], $requiredPolicyIds['fulfillment_policy_100_eur']),
            'payment_policy' => $this->policy_id_exists($checks['payment_policy']['paymentPolicies'] ?? [], $requiredPolicyIds['payment_policy']),
            'return_policy' => $this->policy_id_exists($checks['return_policy']['returnPolicies'] ?? [], $requiredPolicyIds['return_policy']),
        ];

        foreach ($policySeen as $key => $seen) {
            if (!$seen) {
                return ['ready' => false, 'failed' => $key . '_not_found', 'details' => $checks, 'required_policy_ids' => $requiredPolicyIds];
            }
        }

        $categoryReadiness = $this->category_mapping_readiness($marketplaceId);
        $priceReadiness = $this->priceResolver ? $this->priceResolver->readiness_summary($settings) : [];
        if (!$categoryReadiness['ready']) {
            return ['ready' => false, 'failed' => 'category_mapping_review_required', 'details' => $checks, 'category_mappings' => $categoryReadiness, 'price_readiness' => $priceReadiness];
        }

        return ['ready' => true, 'details' => $checks, 'category_mappings' => $categoryReadiness, 'price_readiness' => $priceReadiness];
    }

    private function category_mapping_readiness(string $marketplaceId): array
    {
        $settings = $this->settings();
        $rows = $this->categoryRepo->list_used_woo_categories($marketplaceId, 200);
        $summary = [
            'ready' => true,
            'total_categories' => count($rows),
            'total_products_counted' => 0,
            'products_ready_manual_or_product_categories' => 0,
            'products_ready_accepted_auto_category' => 0,
            'products_blocked_low_confidence_category' => 0,
            'products_blocked_sanity_guard' => 0,
            'products_blocked_sonstige_guard' => 0,
            'products_blocked_expected_keyword' => 0,
            'products_needs_category_review' => 0,
            'unmapped' => 0,
            'taxonomy_api_forbidden' => 0,
            'suggestion_failed' => 0,
            'threshold' => CategoryMappingSafety::threshold($settings),
        ];
        foreach ($rows as $row) {
            $productCount = max(0, (int) ($row['product_count'] ?? 0));
            $summary['total_products_counted'] += $productCount;
            $evaluation = $this->evaluate_category_mapping_row($row, $settings);
            $finalStatus = (string) ($evaluation['final_status'] ?? 'needs_category_review');
            $fallback = $this->static_category_fallback((int) ($row['term_id'] ?? 0), $marketplaceId);
            if ($finalStatus === 'ready_manual' || $fallback['category_id'] !== '') {
                $summary['products_ready_manual_or_product_categories'] += $productCount;
                continue;
            }
            if ($finalStatus === 'ready_auto') {
                $summary['products_ready_accepted_auto_category'] += $productCount;
                continue;
            }
            if ($finalStatus === 'low_confidence_auto') {
                $summary['products_blocked_low_confidence_category'] += $productCount;
                $summary['ready'] = false;
                continue;
            }
            if ($finalStatus === 'category_sanity_failed') {
                $summary['products_blocked_sanity_guard'] += $productCount;
                $reason = (string) ($evaluation['sanity_reason'] ?? '');
                if ($reason === 'auto_mapping_to_sonstige_for_specific_woo_category') {
                    $summary['products_blocked_sonstige_guard'] += $productCount;
                } elseif ($reason === 'expected_path_keyword_missing') {
                    $summary['products_blocked_expected_keyword'] += $productCount;
                }
                $summary['ready'] = false;
                continue;
            }
            $status = in_array($finalStatus, ['taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true) ? $finalStatus : 'needs_category_review';
            if (isset($summary[$status])) {
                $summary[$status] += $productCount;
            }
            $summary['products_needs_category_review'] += $productCount;
            $summary['ready'] = false;
        }

        return $summary;
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

    public function export_product(int $product_id, ?int $variation_id = null, bool $forcePublish = false): array
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
        $aspects = $this->resolve_product_aspects($product, $product_id, $sku, $settings, $categoryId, $content);
        $conditionResolution = EbayConditionResolver::resolve($marketplaceId, $settings);
        $preflight = $this->preflight_validate($product, $product_id, $skuResolution, $content, $category, $aspects, $settings);
        update_post_meta($product_id, '_wei_ebay_export_status', $preflight['status']);
        if (!$preflight['ready']) {
            $this->logger->error('Product not ready for eBay export', $preflight);
            return ['result' => 'error', 'error' => $preflight['status'], 'message' => $preflight['message'], 'details' => $preflight];
        }

        $itemPayload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => $conditionResolution['condition'],
            'product' => [
                'title' => $content['title'],
                'description' => $this->build_ebay_de_description_template($product, $product_id, $content, $aspects, $category),
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
            'condition_resolution' => [
                'condition' => $conditionResolution['condition'],
                'source' => $conditionResolution['source'],
            ],
        ]);

        $item = $this->client->create_or_replace_inventory_item($sku, $itemPayload, [
            'stage' => 'createOrReplaceInventoryItem',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId ?? null,
            'condition_resolution' => [
                'condition' => $conditionResolution['condition'],
                'source' => $conditionResolution['source'],
            ],
        ]);
        if (is_wp_error($item)) return $this->export_error_response('createOrReplaceInventoryItem', $item, $product_id, $sku);

        $shippingPolicyResolution = EbayShippingPolicyResolver::resolve_for_product($product_id, $settings);
        $this->log_shipping_policy_resolution($product_id, $sku, $marketplaceId, $shippingPolicyResolution);
        $policyValidation = $this->validate_selected_policies($settings, [(string) ($shippingPolicyResolution['policy_id'] ?? '')]);
        if (!$policyValidation['valid']) {
            return [
                'result' => 'error',
                'error' => 'business_policy_ids_missing_or_invalid',
                'message' => 'Missing or invalid eBay Business Policy IDs. Refresh EBAY_DE policies and select fulfillmentPolicyId, paymentPolicyId and returnPolicyId before export.',
                'details' => $policyValidation,
            ];
        }

        $priceCurrency = $this->offer_currency($marketplaceId);
        $priceResolution = is_array($preflight['price_resolution'] ?? null) ? $preflight['price_resolution'] : $this->resolve_price($product, $product_id, $settings);
        $priceValue = $marketplaceId === 'EBAY_DE' ? (float) ($priceResolution['ebay_price_eur'] ?? 0) : (float) $product->get_price();

        $listingPolicies = [
            'fulfillmentPolicyId' => (string) ($shippingPolicyResolution['policy_id'] ?? EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_DEFAULT_30_EUR, $settings)),
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
                'price_resolution' => $priceResolution,
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

        $this->log_shipping_policy_change_state($offer_id, $product_id, $sku, $marketplaceId, $shippingPolicyResolution);

        $this->logger->info('eBay offer payload before updateOffer', [
            'stage' => 'updateOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'offer_id' => $offer_id,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
            'offer_payload' => $offerPayload,
            'price_resolution' => $priceResolution,
        ]);

        $updated = $this->client->update_offer($offer_id, $offerPayload, [
            'stage' => 'updateOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $categoryId,
        ]);
        if (is_wp_error($updated)) return $this->export_error_response('updateOffer', $updated, $product_id, $sku);

        $policyValidation = $this->validate_selected_policies($this->settings(), [(string) ($listingPolicies['fulfillmentPolicyId'] ?? '')]);
        if (!$policyValidation['valid']) {
            return [
                'result' => 'error',
                'error' => 'business_policy_ids_missing_or_invalid_before_publish',
                'message' => 'Cannot publish eBay offer because one or more selected EBAY_DE Business Policy IDs are missing or no longer exist in cached policies.',
                'details' => $policyValidation,
            ];
        }

        $published = [];
        $publishedNow = false;
        $metaProductId = $variation_id ?: $product_id;
        $listing_id = trim((string) ($mapping['remote_listing_id'] ?? ''));
        if ($listing_id === '') {
            $listing_id = trim((string) get_post_meta($metaProductId, '_wei_ebay_listing_id', true));
        }
        if ($listing_id === '') {
            $listing_id = trim((string) get_post_meta($metaProductId, '_wei_ebay_item_id', true));
        }
        if (empty($settings['auto_publish_enabled']) && !$forcePublish) {
            $this->logger->warning('publishOffer skipped because auto publish is disabled', [
                'stage' => 'publishOffer',
                'product_id' => $product_id,
                'sku' => $sku,
                'offer_id' => $offer_id,
                'marketplace_id' => $marketplaceId,
                'category_id' => $categoryId,
                'wrote_allegro' => false,
            ]);
        } else {
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
            $publishedNow = true;
        }

        $syncStatus = $publishedNow ? 'published' : ($offer_id !== '' ? 'offer_updated' : 'exported_inventory');
        if (!empty($skuResolution['wei_ebay_sku'])) {
            update_post_meta($metaProductId, '_wei_ebay_sku', (string) $skuResolution['wei_ebay_sku']);
        }
        update_post_meta($metaProductId, '_wei_ebay_offer_id', $offer_id);
        update_post_meta($metaProductId, '_wei_ebay_inventory_id', $sku);
        update_post_meta($metaProductId, '_wei_ebay_item_id', $listing_id);
        update_post_meta($metaProductId, '_wei_ebay_listing_id', $listing_id);
        update_post_meta($metaProductId, '_wei_ebay_public_url', $this->listing_public_url($listing_id, $marketplaceId));
        update_post_meta($metaProductId, '_wei_ebay_last_export_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($metaProductId, '_wei_ebay_last_sync_status', $syncStatus);
        delete_post_meta($metaProductId, '_wei_ebay_last_sync_error');
        if ($publishedNow) {
            update_post_meta($metaProductId, '_wei_ebay_last_publish_at', gmdate('Y-m-d H:i:s'));
            update_post_meta($metaProductId, '_wei_ebay_listing_status', 'published');
        }
        update_post_meta($product_id, '_wei_ebay_last_payload_hash', hash('sha256', wp_json_encode(['inventory' => $itemPayload, 'offer' => $offerPayload]) ?: ''));
        update_post_meta($metaProductId, '_wei_ebay_last_synced_quantity', (string) max(0, (int) $product->get_stock_quantity()));
        update_post_meta($product_id, '_wei_ebay_export_status', $syncStatus);
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

        return ['result' => 'success', 'offer_id' => $offer_id, 'listing_id' => $listing_id, 'inventory_id' => $sku, 'aspects' => $aspects, 'condition_resolution' => ['condition' => $conditionResolution['condition'], 'source' => $conditionResolution['source']], 'content_source' => $content['source'], 'sku_resolution' => $skuResolution, 'price_resolution' => $priceResolution];
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



    public function preflight_product(int $product_id, ?int $variation_id = null, bool $suppressVerboseLogs = false, bool $persistStatus = true, array $context = []): array
    {
        $previousSuppressVerboseLogs = $this->suppressVerboseLogs;
        $this->suppressVerboseLogs = $suppressVerboseLogs;
        try {
            $product = wc_get_product($variation_id ?: $product_id);
            if (!$product) {
                return ['ready' => false, 'status' => 'not_ready', 'message' => 'Product not found.'];
            }

            $settings = array_merge($this->settings(), $context);
            if (!empty($context['suppress_side_effects']) || !empty($context['audit_mode'])) {
                $settings['auto_generate_german_content_preflight'] = 0;
                $settings['regenerate_german_content_on_hash_change'] = 0;
                $settings['_wei_suppress_side_effects'] = true;
            }
            $marketplaceId = $this->marketplace_id();
            $skuResolution = $this->resolve_ebay_sku($product, $product_id, $variation_id, $settings);
            $content = $this->resolve_german_content($product, $product_id, $marketplaceId, $settings);
            $category = $this->resolve_category($product, $product_id, $skuResolution['sku'], $marketplaceId, $settings);
            $aspects = $this->resolve_product_aspects($product, $product_id, $skuResolution['sku'], $settings, $category['category_id'], $content);
            $preflight = $this->preflight_validate($product, $product_id, $skuResolution, $content, $category, $aspects, $settings);
            if ($persistStatus) {
                update_post_meta($product_id, '_wei_ebay_export_status', $preflight['status']);
            }
            return $preflight;
        } finally {
            $this->suppressVerboseLogs = $previousSuppressVerboseLogs;
        }
    }

    public function publish_product_offer_only(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return [
                'result' => 'error',
                'published' => false,
                'status' => 'not_ready',
                'message' => 'Product not found.',
                'product_id' => $product_id,
                'offer_id' => '',
                'listing_id' => '',
                'public_url' => '',
                'wrote_woo_sku' => false,
                'wrote_woo_price' => false,
                'wrote_allegro' => false,
            ];
        }

        $preflight = $this->preflight_product($product_id, $variation_id);
        $metaProductId = $variation_id ?: $product_id;
        $mapping = $this->repo->find_by_product($product_id, $variation_id);
        $offerId = trim((string) ($mapping['remote_offer_id'] ?? ''));
        if ($offerId === '') {
            $offerId = trim((string) get_post_meta($metaProductId, '_wei_ebay_offer_id', true));
        }
        $listingId = trim((string) ($mapping['remote_listing_id'] ?? ''));
        if ($listingId === '') {
            $listingId = trim((string) get_post_meta($metaProductId, '_wei_ebay_item_id', true));
        }

        $marketplaceId = $this->marketplace_id();
        $skuResolution = is_array($preflight['sku_resolution'] ?? null) ? $preflight['sku_resolution'] : [];
        $sku = (string) ($skuResolution['sku'] ?? $mapping['sku'] ?? get_post_meta($metaProductId, '_wei_ebay_sku', true));
        $baseResult = [
            'result' => 'error',
            'published' => false,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'public_url' => $this->listing_public_url($listingId, $marketplaceId),
            'marketplace_id' => $marketplaceId,
            'inventory_id' => $sku,
            'status' => (string) ($preflight['status'] ?? 'not_ready'),
            'preflight_ready' => !empty($preflight['ready']),
            'preflight' => $preflight,
            'ebay_response' => null,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ];

        if (empty($preflight['ready'])) {
            $result = array_merge($baseResult, [
                'message' => (string) ($preflight['message'] ?? 'Product not ready for eBay publish.'),
            ]);
            $this->record_manual_publish_result($product_id, $metaProductId, $result);
            $this->logger->warning('Manual publishOffer skipped after preflight', $result);
            return $result;
        }

        if ($offerId === '') {
            $result = array_merge($baseResult, [
                'status' => 'missing_offer_id',
                'message' => 'Product is ready, but no eBay offer_id exists. Run the single-product export first; this action will not create or update offers.',
            ]);
            $this->record_manual_publish_result($product_id, $metaProductId, $result);
            $this->logger->warning('Manual publishOffer skipped because offer_id is missing', $result);
            return $result;
        }

        $this->logger->info('Manual publishOffer preflight passed; publishing one offer only', [
            'stage' => 'manualPublishOfferOnly',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => $sku,
            'offer_id' => $offerId,
            'listing_id_before' => $listingId,
            'marketplace_id' => $marketplaceId,
            'preflight_ready' => true,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ]);

        $published = $this->client->publish_offer($offerId, [
            'stage' => 'manualPublishOfferOnly',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => $sku,
            'offer_id' => $offerId,
            'marketplace_id' => $marketplaceId,
        ]);

        if (is_wp_error($published)) {
            $blocked = $this->is_account_restriction_error($published);
            $errorData = $published->get_error_data();
            if ($blocked) {
                update_option('wei_ebay_global_status', 'blocked_by_ebay_account_restriction', false);
                update_option('wei_ebay_account_restriction_status', 'detected', false);
            }
            $result = array_merge($baseResult, [
                'status' => $blocked ? 'blocked_by_ebay_account_restriction' : 'publish_error',
                'message' => $published->get_error_message(),
                'error' => $published->get_error_code(),
                'error_details' => is_array($errorData) ? $errorData : [],
                'ebay_response' => is_array($errorData) && isset($errorData['response_body']) ? $errorData['response_body'] : $errorData,
                'blocked_by_ebay_account_restriction' => $blocked,
            ]);
            $this->record_manual_publish_result($product_id, $metaProductId, $result);
            $this->logger->error($blocked ? 'blocked_by_ebay_account_restriction' : 'Manual publishOffer failed', $result);
            return $result;
        }

        $listingId = trim((string) ($published['listingId'] ?? $listingId));
        $result = array_merge($baseResult, [
            'result' => 'success',
            'published' => true,
            'status' => 'published',
            'message' => 'Manual publishOffer completed for one eBay offer only.',
            'listing_id' => $listingId,
            'public_url' => $this->listing_public_url($listingId, $marketplaceId),
            'ebay_response' => $published,
        ]);
        update_post_meta($metaProductId, '_wei_ebay_offer_id', $offerId);
        update_post_meta($metaProductId, '_wei_ebay_inventory_id', (string) ($mapping['remote_inventory_id'] ?? $sku));
        update_post_meta($metaProductId, '_wei_ebay_item_id', $listingId);
        update_post_meta($metaProductId, '_wei_ebay_listing_id', $listingId);
        update_post_meta($metaProductId, '_wei_ebay_public_url', $this->listing_public_url($listingId, $marketplaceId));
        update_post_meta($metaProductId, '_wei_ebay_last_publish_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($metaProductId, '_wei_ebay_last_sync_status', 'published');
        update_post_meta($metaProductId, '_wei_ebay_listing_status', 'published');
        delete_post_meta($metaProductId, '_wei_ebay_last_sync_error');
        update_post_meta($product_id, '_wei_ebay_export_status', 'published');
        $this->repo->upsert([
            'marketplace' => 'ebay',
            'woo_product_id' => $product_id,
            'woo_variation_id' => $variation_id,
            'sku' => $sku,
            'remote_inventory_id' => (string) ($mapping['remote_inventory_id'] ?? $sku),
            'remote_offer_id' => $offerId,
            'remote_listing_id' => $listingId,
            'marketplace_id' => $marketplaceId,
            'status' => 'active',
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->record_manual_publish_result($product_id, $metaProductId, $result);
        $this->logger->info('Manual publishOffer result: published=true', $result);

        return $result;
    }




    public function update_fulfillment_policy_only(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        $metaProductId = $variation_id ?: $product_id;
        if (!$product) {
            $result = ['result' => 'error', 'error' => 'product_not_found', 'product_id' => $product_id, 'variation_id' => $variation_id];
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            return $result;
        }

        $settings = $this->settings();
        $marketplaceId = $this->marketplace_id();
        $mapping = $this->repo->find_by_product($product_id, $variation_id);
        $offerId = trim((string) ($mapping['remote_offer_id'] ?? ''));
        if ($offerId === '') {
            $offerId = trim((string) get_post_meta($metaProductId, '_wei_ebay_offer_id', true));
        }
        $listingId = trim((string) ($mapping['remote_listing_id'] ?? ''));
        if ($listingId === '') {
            $listingId = trim((string) get_post_meta($metaProductId, '_wei_ebay_listing_id', true));
        }
        if ($listingId === '') {
            $listingId = trim((string) get_post_meta($metaProductId, '_wei_ebay_item_id', true));
        }

        $sku = trim((string) ($mapping['sku'] ?? ''));
        if ($sku === '') {
            $sku = trim((string) get_post_meta($metaProductId, '_wei_ebay_sku', true));
        }
        if ($sku === '' && method_exists($product, 'get_sku')) {
            $sku = trim((string) $product->get_sku());
        }

        $baseContext = [
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => $sku,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'marketplace_id' => $marketplaceId,
            'safe_update_scope' => 'listingPolicies.fulfillmentPolicyId_only',
            'called_create_offer' => false,
            'called_publish_offer' => false,
        ];
        $this->logger->info('EBAY_SHIPPING_POLICY_SINGLE_START', $baseContext);

        if ($offerId === '') {
            $result = $baseContext + ['result' => 'error', 'error' => 'offer_id_missing', 'message' => 'Existing eBay offer_id is required; this action never creates offers.'];
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            return $result;
        }

        $offer = $this->client->get_offer($offerId, $baseContext + ['stage' => 'shippingPolicyOnlyGetOffer']);
        if (is_wp_error($offer)) {
            $result = $baseContext + ['result' => 'error', 'error' => $offer->get_error_message(), 'error_details' => $offer->get_error_data()];
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            return $result;
        }
        if (!is_array($offer)) {
            $result = $baseContext + ['result' => 'error', 'error' => 'invalid_get_offer_response'];
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            return $result;
        }

        $resolution = EbayShippingPolicyResolver::resolve_for_product($product_id, $settings);
        $this->log_shipping_policy_resolution($product_id, $sku, $marketplaceId, $resolution);
        $desiredPolicyId = (string) ($resolution['policy_id'] ?? '');
        $currentPolicies = is_array($offer['listingPolicies'] ?? null) ? $offer['listingPolicies'] : [];
        $currentPolicyId = (string) ($currentPolicies['fulfillmentPolicyId'] ?? '');
        $changeContext = $baseContext + [
            'shipping_group' => (string) ($resolution['group'] ?? ''),
            'source' => (string) ($resolution['source'] ?? ''),
            'current_fulfillment_policy_id' => $currentPolicyId,
            'desired_fulfillment_policy_id' => $desiredPolicyId,
            'preserved_payment_policy_id' => (string) ($currentPolicies['paymentPolicyId'] ?? ''),
            'preserved_return_policy_id' => (string) ($currentPolicies['returnPolicyId'] ?? ''),
        ];

        if ($desiredPolicyId === '') {
            $result = $changeContext + ['result' => 'error', 'error' => 'desired_fulfillment_policy_id_missing'];
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            return $result;
        }

        if ($currentPolicyId === $desiredPolicyId) {
            $result = $changeContext + ['result' => 'success', 'status' => 'unchanged', 'changed' => false, 'ebay_response' => null];
            $this->logger->info('EBAY_SHIPPING_POLICY_UNCHANGED', $changeContext);
            $this->logger->info('EBAY_SHIPPING_POLICY_SINGLE_DONE', $result);
            return $result;
        }

        $payload = $this->build_policy_only_offer_payload($offer, $sku, $marketplaceId);
        $payload['listingPolicies'] = $currentPolicies;
        $payload['listingPolicies']['fulfillmentPolicyId'] = $desiredPolicyId;

        foreach (['paymentPolicyId', 'returnPolicyId'] as $policyKey) {
            if (empty($payload['listingPolicies'][$policyKey])) {
                $fallbackKey = $policyKey === 'paymentPolicyId' ? 'ebay_payment_policy_id' : 'ebay_return_policy_id';
                $payload['listingPolicies'][$policyKey] = (string) ($settings[$fallbackKey] ?? '');
            }
        }
        if (empty($payload['marketplaceId'])) {
            $payload['marketplaceId'] = $marketplaceId;
        }
        if (empty($payload['merchantLocationKey'])) {
            $payload['merchantLocationKey'] = $this->merchant_location_key();
        }

        $this->logger->info('EBAY_SHIPPING_POLICY_CHANGED', $changeContext + [
            'update_payload_policy_ids' => $payload['listingPolicies'],
            'preserved_offer_fields' => array_keys($payload),
        ]);

        $updated = $this->client->update_offer($offerId, $payload, $baseContext + [
            'stage' => 'shippingPolicyOnlyUpdateOffer',
            'desired_fulfillment_policy_id' => $desiredPolicyId,
        ]);
        if (is_wp_error($updated)) {
            $result = $changeContext + ['result' => 'error', 'error' => $updated->get_error_message(), 'error_details' => $updated->get_error_data()];
            update_post_meta($metaProductId, '_wei_ebay_last_shipping_policy_error', (string) $result['error']);
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            return $result;
        }

        update_post_meta($metaProductId, '_wei_ebay_last_shipping_policy_sync_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($metaProductId, '_wei_ebay_last_fulfillment_policy_id', $desiredPolicyId);
        delete_post_meta($metaProductId, '_wei_ebay_last_shipping_policy_error');

        $result = $changeContext + ['result' => 'success', 'status' => 'changed', 'changed' => true, 'ebay_response' => is_array($updated) ? $updated : []];
        $this->logger->info('EBAY_SHIPPING_POLICY_SINGLE_DONE', $result);
        return $result;
    }

    private function build_policy_only_offer_payload(array $offer, string $fallbackSku, string $fallbackMarketplaceId): array
    {
        $allowed = [
            'sku',
            'marketplaceId',
            'format',
            'availableQuantity',
            'quantityLimitPerBuyer',
            'listingDescription',
            'listingPolicies',
            'pricingSummary',
            'categoryId',
            'secondaryCategoryId',
            'merchantLocationKey',
            'listingDuration',
            'tax',
            'storeCategoryNames',
            'lotSize',
            'charity',
            'hideBuyerDetails',
            'includeCatalogProductDetails',
        ];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $offer)) {
                $payload[$key] = $offer[$key];
            }
        }
        if (empty($payload['sku']) && $fallbackSku !== '') {
            $payload['sku'] = $fallbackSku;
        }
        if (empty($payload['marketplaceId']) && $fallbackMarketplaceId !== '') {
            $payload['marketplaceId'] = $fallbackMarketplaceId;
        }
        if (!isset($payload['listingPolicies']) || !is_array($payload['listingPolicies'])) {
            $payload['listingPolicies'] = [];
        }
        return $payload;
    }

    public function verify_api_publishing_readiness(int $product_id, ?int $variation_id = null, bool $writeDiagnosticOffer = false): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return [
                'result' => 'error',
                'status' => 'not_ready',
                'message' => 'Product not found.',
                'product_id' => $product_id,
                'manual_listing_note' => 'This diagnostic only checks API readiness; manual eBay UI listing ability is separate.',
            ];
        }

        $settings = $this->settings();
        $marketplaceId = $this->marketplace_id();
        $preflight = $this->preflight_product($product_id, $variation_id);
        $metaProductId = $variation_id ?: $product_id;
        $mapping = $this->repo->find_by_product($product_id, $variation_id);
        $offerId = trim((string) ($mapping['remote_offer_id'] ?? ''));
        if ($offerId === '') {
            $offerId = trim((string) get_post_meta($metaProductId, '_wei_ebay_offer_id', true));
        }
        $skuResolution = is_array($preflight['sku_resolution'] ?? null) ? $preflight['sku_resolution'] : $this->resolve_ebay_sku($product, $product_id, $variation_id, $settings);
        $sku = (string) ($skuResolution['sku'] ?? $mapping['sku'] ?? get_post_meta($metaProductId, '_wei_ebay_sku', true));
        $category = is_array($preflight['category'] ?? null) ? $preflight['category'] : [];
        $categoryId = (string) ($category['category_id'] ?? '');
        $aspects = is_array($preflight['aspects'] ?? null) ? $preflight['aspects'] : [];
        $content = is_array($preflight['content'] ?? null) ? $preflight['content'] : [];
        $priceResolution = is_array($preflight['price_resolution'] ?? null) ? $preflight['price_resolution'] : $this->resolve_price($product, $product_id, $settings);
        $priceValue = $marketplaceId === 'EBAY_DE' ? (float) ($priceResolution['ebay_price_eur'] ?? 0) : (float) $product->get_price();
        $conditionResolution = EbayConditionResolver::resolve($marketplaceId, $settings);
        $shippingPolicyResolution = EbayShippingPolicyResolver::resolve_for_product($product_id, $settings);
        $this->log_shipping_policy_resolution($product_id, $sku, $marketplaceId, $shippingPolicyResolution);
        $listingPolicies = [
            'fulfillmentPolicyId' => (string) ($shippingPolicyResolution['policy_id'] ?? EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_DEFAULT_30_EUR, $settings)),
            'paymentPolicyId' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'returnPolicyId' => (string) ($settings['ebay_return_policy_id'] ?? ''),
        ];
        $inventoryPayload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => $conditionResolution['condition'],
            'product' => [
                'title' => (string) ($content['title'] ?? ''),
                'description' => (string) ($content['description'] ?? ''),
                'imageUrls' => array_values(array_filter(array_map('wp_get_attachment_url', array_merge([$product->get_image_id()], $product->get_gallery_image_ids())))),
                'aspects' => $aspects,
            ],
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
            'pricingSummary' => ['price' => ['value' => (string) $priceValue, 'currency' => $this->offer_currency($marketplaceId)]],
        ];

        $result = [
            'result' => 'success',
            'status' => 'diagnostic_complete',
            'message' => 'Inventory API publishOffer readiness diagnostic completed; no publishOffer call was made.',
            'manual_listing_note' => 'Manual eBay UI listing success does not prove Inventory API publishOffer is allowed. This report separates UI listing ability from REST Inventory API publishing readiness.',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'marketplace_id' => $marketplaceId,
            'sku' => $sku,
            'offer_id' => $offerId,
            'preflight_ready' => !empty($preflight['ready']),
            'preflight' => $preflight,
            'oauth_app_publish_readiness' => $this->client->oauth_diagnostic_context(),
            'account_api_checks' => [],
            'marketplace_policy_location_checks' => [],
            'offer_before_publish' => null,
            'inventory_item_before_publish' => null,
            'publish_required_field_check' => [],
            'condition_resolution' => [
                'condition' => $conditionResolution['condition'],
                'source' => $conditionResolution['source'],
            ],
            'diagnostic_create_update' => ['attempted' => false],
            'fresh_offer_recreate_check' => ['attempted' => false, 'reason' => $writeDiagnosticOffer ? '' : 'Not requested. Enable diagnostic offer create/update to test fresh Inventory API create/update without publishing.'],
            'alternate_api_path_note' => 'Not tested by this safe action. If Inventory API publishOffer remains blocked but manual UI listing works, a separate diagnostic should compare Trading API AddFixedPriceItem or Sell Feed LMS using the same seller, marketplace, policies, tax/business setup, and SKU-equivalent data.',
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
            'called_publish_offer' => false,
        ];

        $result['account_api_checks']['getPrivileges'] = $this->api_result($this->client->get_privileges([
            'stage' => 'diagnosticGetPrivileges',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
        ]));
        $programs = $this->client->get_opted_in_programs([
            'stage' => 'diagnosticGetOptedInPrograms',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
        ]);
        $result['account_api_checks']['getOptedInPrograms'] = $this->api_result($programs);
        $result['account_api_checks']['selling_policy_management_opted_in'] = $this->program_is_opted_in($programs, 'SELLING_POLICY_MANAGEMENT');

        foreach (['fulfillment_policy', 'payment_policy', 'return_policy'] as $type) {
            $result['marketplace_policy_location_checks'][$type] = $this->api_result($this->client->get_policies($type, $marketplaceId));
        }
        $result['marketplace_policy_location_checks']['selected_policy_ids'] = $this->validate_selected_policies($settings);
        $locations = $this->client->get_locations();
        $result['marketplace_policy_location_checks']['locations'] = $this->api_result($locations);
        $locationKey = $this->merchant_location_key();
        $result['marketplace_policy_location_checks']['selected_location_key'] = $locationKey;
        $result['marketplace_policy_location_checks']['selected_location_exists_in_list'] = $this->location_key_exists($locations, $locationKey);
        $result['marketplace_policy_location_checks']['selected_location'] = $locationKey !== '' ? $this->api_result($this->client->get_location($locationKey, [
            'stage' => 'diagnosticGetLocation',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
        ])) : ['ok' => false, 'error' => 'missing_location_key'];

        if ($sku !== '') {
            $inventoryItem = $this->client->get_inventory_item($sku, [
                'stage' => 'diagnosticGetInventoryItem',
                'product_id' => $product_id,
                'sku' => $sku,
                'marketplace_id' => $marketplaceId,
            ]);
            $result['inventory_item_before_publish'] = $this->api_result($inventoryItem);
        }
        if ($offerId !== '') {
            $offer = $this->client->get_offer($offerId, [
                'stage' => 'diagnosticGetOfferBeforePublish',
                'product_id' => $product_id,
                'sku' => $sku,
                'offer_id' => $offerId,
                'marketplace_id' => $marketplaceId,
            ]);
            $result['offer_before_publish'] = $this->api_result($offer);
            $result['publish_required_field_check'] = $this->offer_publish_required_field_check(is_wp_error($offer) ? [] : (array) $offer, is_wp_error($result['inventory_item_before_publish'] ?? null) ? [] : (array) (($result['inventory_item_before_publish']['data'] ?? [])), $marketplaceId);
        } else {
            $result['publish_required_field_check'] = ['ready' => false, 'missing' => ['offer_id'], 'warnings' => ['No existing offer_id to retrieve with getOffer.']];
        }

        if ($writeDiagnosticOffer && !empty($preflight['ready'])) {
            $createOrUpdate = ['attempted' => true, 'mode' => $offerId !== '' ? 'update_existing_offer_no_publish' : 'create_offer_no_publish'];
            $inventoryWrite = $this->client->create_or_replace_inventory_item($sku, $inventoryPayload, [
                'stage' => 'diagnosticCreateOrReplaceInventoryItem',
                'product_id' => $product_id,
                'sku' => $sku,
                'marketplace_id' => $marketplaceId,
                'category_id' => $categoryId,
                'condition_resolution' => [
                    'condition' => $conditionResolution['condition'],
                    'source' => $conditionResolution['source'],
                ],
            ]);
            $createOrUpdate['inventory_write'] = $this->api_result($inventoryWrite);
            if (!is_wp_error($inventoryWrite)) {
                $offerWrite = $offerId !== ''
                    ? $this->client->update_offer($offerId, $offerPayload, ['stage' => 'diagnosticUpdateOfferNoPublish', 'product_id' => $product_id, 'sku' => $sku, 'offer_id' => $offerId, 'marketplace_id' => $marketplaceId, 'category_id' => $categoryId])
                    : $this->client->create_offer($offerPayload, ['stage' => 'diagnosticCreateOfferNoPublish', 'product_id' => $product_id, 'sku' => $sku, 'marketplace_id' => $marketplaceId, 'category_id' => $categoryId]);
                $createOrUpdate['offer_write'] = $this->api_result($offerWrite);
                $newOfferId = is_array($offerWrite) ? trim((string) ($offerWrite['offerId'] ?? $offerId)) : $offerId;
                if ($newOfferId !== '') {
                    $createOrUpdate['get_offer_after_write'] = $this->api_result($this->client->get_offer($newOfferId, ['stage' => 'diagnosticGetOfferAfterWrite', 'product_id' => $product_id, 'sku' => $sku, 'offer_id' => $newOfferId, 'marketplace_id' => $marketplaceId]));
                }
            }
            $result['diagnostic_create_update'] = $createOrUpdate;
            $result['fresh_offer_recreate_check'] = ['attempted' => true, 'same_sku_recreate_possible' => false, 'reason' => 'Inventory API permits one active offer entity for a SKU/marketplace; this diagnostic updates or creates the current offer but does not publish. Recreating from scratch with the same SKU requires deleting/retiring the existing offer outside this safe action.'];
        }

        $result['exact_missing_api_prerequisites_before_publish'] = $this->summarize_publish_prerequisites($result);
        update_option('wei_ebay_last_api_publish_readiness_result', $result, false);
        update_post_meta($product_id, '_wei_ebay_api_publish_readiness_result', wp_json_encode($result));
        $this->logger->info('eBay API publish readiness diagnostic completed', $result);

        return $result;
    }

    private function api_result($response): array
    {
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'error' => $response->get_error_code(),
                'message' => $response->get_error_message(),
                'details' => $response->get_error_data(),
            ];
        }

        return [
            'ok' => true,
            'warnings' => $this->extract_api_messages((array) $response, 'warnings'),
            'errors' => $this->extract_api_messages((array) $response, 'errors'),
            'data' => $response,
        ];
    }

    private function extract_api_messages(array $response, string $key): array
    {
        return is_array($response[$key] ?? null) ? array_values($response[$key]) : [];
    }

    private function program_is_opted_in($programsResponse, string $programType): bool
    {
        if (is_wp_error($programsResponse)) {
            return false;
        }
        foreach ((array) ($programsResponse['programs'] ?? []) as $program) {
            if (is_array($program) && (string) ($program['programType'] ?? '') === $programType) {
                return true;
            }
        }

        return false;
    }

    private function location_key_exists($locationsResponse, string $locationKey): bool
    {
        if ($locationKey === '' || is_wp_error($locationsResponse)) {
            return false;
        }
        foreach ((array) ($locationsResponse['locations'] ?? []) as $location) {
            if (is_array($location) && (string) ($location['merchantLocationKey'] ?? '') === $locationKey) {
                return true;
            }
        }

        return false;
    }

    private function offer_publish_required_field_check(array $offer, array $inventoryItem, string $marketplaceId): array
    {
        $missing = [];
        $warnings = [];
        foreach (['sku', 'marketplaceId', 'merchantLocationKey', 'categoryId', 'format', 'listingDuration'] as $field) {
            if (trim((string) ($offer[$field] ?? '')) === '') {
                $missing[] = 'offer.' . $field;
            }
        }
        foreach (['fulfillmentPolicyId', 'paymentPolicyId', 'returnPolicyId'] as $field) {
            if (trim((string) ($offer['listingPolicies'][$field] ?? '')) === '') {
                $missing[] = 'offer.listingPolicies.' . $field;
            }
        }
        if ((float) ($offer['pricingSummary']['price']['value'] ?? 0) <= 0 || trim((string) ($offer['pricingSummary']['price']['currency'] ?? '')) === '') {
            $missing[] = 'offer.pricingSummary.price';
        }
        if (!isset($offer['availableQuantity']) && !isset($offer['availability']['shipToLocationAvailability']['quantity'])) {
            $missing[] = 'offer.availableQuantity';
        }
        if ((string) ($offer['marketplaceId'] ?? '') !== '' && (string) ($offer['marketplaceId'] ?? '') !== $marketplaceId) {
            $warnings[] = 'Offer marketplaceId does not match current plugin marketplace_id.';
        }
        foreach (['condition'] as $field) {
            if (trim((string) ($inventoryItem[$field] ?? '')) === '') {
                $missing[] = 'inventory_item.' . $field;
            }
        }
        foreach (['title', 'description'] as $field) {
            if (trim((string) ($inventoryItem['product'][$field] ?? '')) === '') {
                $missing[] = 'inventory_item.product.' . $field;
            }
        }
        if (empty($inventoryItem['product']['imageUrls']) || !is_array($inventoryItem['product']['imageUrls'])) {
            $missing[] = 'inventory_item.product.imageUrls';
        }

        return ['ready' => $missing === [], 'missing' => array_values(array_unique($missing)), 'warnings' => $warnings, 'listing_status' => (string) ($offer['listing']['listingStatus'] ?? '')];
    }

    private function summarize_publish_prerequisites(array $result): array
    {
        $missing = [];
        if (empty($result['oauth_app_publish_readiness']['refresh_token_present'])) {
            $missing[] = 'OAuth refresh token missing; reconnect eBay with sell.inventory and sell.account scopes.';
        }
        if (empty($result['account_api_checks']['getPrivileges']['ok'])) {
            $missing[] = 'Account API getPrivileges failed for this OAuth token/app.';
        } elseif (empty($result['account_api_checks']['getPrivileges']['data']['sellerRegistrationCompleted'])) {
            $missing[] = 'Seller registration is not complete according to Account API getPrivileges.';
        }
        if (empty($result['account_api_checks']['selling_policy_management_opted_in'])) {
            $missing[] = 'Seller is not opted in to SELLING_POLICY_MANAGEMENT business policies according to Account API.';
        }
        if (empty($result['marketplace_policy_location_checks']['selected_policy_ids']['valid'])) {
            $missing[] = 'Selected fulfillment/payment/return policy IDs are missing, invalid, or cached for another marketplace.';
        }
        if (empty($result['marketplace_policy_location_checks']['selected_location_exists_in_list'])) {
            $missing[] = 'Selected merchantLocationKey is missing from Inventory API locations.';
        }
        foreach ((array) ($result['publish_required_field_check']['missing'] ?? []) as $field) {
            $missing[] = 'Missing required publish field: ' . $field;
        }

        if ($missing === []) {
            $missing[] = 'No local/API prerequisite gap detected before publishOffer. If publishOffer still returns errorId 25019 / BLOCK_Seller_NonBlackbird_Issue607, treat it as an Inventory API publishOffer-specific eBay account/tax/business-policy block and escalate to eBay Developer Support with the response body and correlation headers.';
        }

        return array_values(array_unique($missing));
    }

    private function record_manual_publish_result(int $product_id, int $metaProductId, array $result): void
    {
        update_post_meta($product_id, '_wei_ebay_manual_publish_result', wp_json_encode($result));
        if ($metaProductId !== $product_id) {
            update_post_meta($metaProductId, '_wei_ebay_manual_publish_result', wp_json_encode($result));
        }
        update_option('wei_ebay_last_manual_publish_result', $result, false);
    }

    private function listing_public_url(string $listingId, string $marketplaceId): string
    {
        if (trim($listingId) === '') {
            return '';
        }

        $host = match ($marketplaceId) {
            'EBAY_DE' => 'www.ebay.de',
            'EBAY_GB' => 'www.ebay.co.uk',
            'EBAY_US' => 'www.ebay.com',
            default => 'www.ebay.com',
        };

        return 'https://' . $host . '/itm/' . rawurlencode($listingId);
    }

    private function resolve_ebay_sku($product, int $product_id, ?int $variation_id, array $settings): array
    {
        $metaProductId = $variation_id ?: $product_id;
        $wooSku = trim((string) $product->get_sku());
        $existingWeiEbaySku = trim((string) get_post_meta($metaProductId, '_wei_ebay_sku', true));
        $useWooSkuSetting = !empty($settings['use_woo_sku_for_ebay']);
        $generated = false;
        $wroteWooSku = false;

        if ($existingWeiEbaySku !== '') {
            $final = $this->sanitize_ebay_sku($existingWeiEbaySku);
        } elseif (!empty($settings['_wei_suppress_side_effects'])) {
            $final = $this->generated_ebay_sku($product_id, $variation_id, $settings);
            $generated = false;
        } else {
            $skuGeneration = $this->skuGenerator
                ? $this->skuGenerator->ensure_product_ebay_sku($product_id, $variation_id, $settings)
                : ['sku' => $this->generated_ebay_sku($product_id, $variation_id, $settings), 'generated' => false, 'conflict' => false, 'wrote_woo_sku' => false];
            $final = (string) ($skuGeneration['sku'] ?? '');
            $generated = !empty($skuGeneration['generated']);
        }

        $weiEbaySku = $existingWeiEbaySku !== '' ? $existingWeiEbaySku : ($generated ? $final : '');
        $context = [
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'woo_sku' => $wooSku,
            'existing_wei_ebay_sku' => $existingWeiEbaySku,
            'wei_ebay_sku' => $weiEbaySku,
            'generated' => $generated ? 'yes' : 'no',
            'generated_bool' => $generated,
            'final_sku_used_for_ebay' => $final,
            'use_woo_sku_setting' => $useWooSkuSetting ? 'on' : 'off',
            'wrote_woo_sku' => $wroteWooSku,
        ];
        if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
            $this->logger->info('Resolved eBay SKU', $context);
        }
        return [
            'sku' => $final,
            'woo_sku' => $wooSku,
            'existing_wei_ebay_sku' => $existingWeiEbaySku,
            'wei_ebay_sku' => $weiEbaySku,
            'generated' => $generated,
            'final_sku_used_for_ebay' => $final,
            'use_woo_sku_setting' => $useWooSkuSetting,
            'wrote_woo_sku' => $wroteWooSku,
        ];
    }

    private function generated_ebay_sku(int $product_id, ?int $variation_id, array $settings): string
    {
        $prefix = $this->sanitize_ebay_sku((string) ($settings['ebay_sku_prefix'] ?? 'GPSW'));
        if ($prefix === '') {
            $prefix = 'GPSW';
        }

        $raw = $variation_id ? $prefix . '-' . $product_id . '-' . $variation_id : $prefix . '-' . $product_id;
        return $this->sanitize_ebay_sku($raw);
    }

    private function sanitize_ebay_sku(string $sku): string
    {
        $sku = trim($sku);
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?: '';
        $sku = trim($sku, '-_.');
        if (strlen($sku) > self::EBAY_SKU_MAX_LENGTH) {
            $sku = rtrim(substr($sku, 0, self::EBAY_SKU_MAX_LENGTH), '-_.');
        }

        return $sku;
    }

    public function generate_german_content_meta_only(int $product_id): array
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return ['result' => 'skipped', 'product_id' => $product_id, 'reason' => 'product_not_found'];
        }

        $settings = $this->settings();
        $settings['_wei_german_content_only_action'] = true;
        $beforeTitle = trim((string) get_post_meta($product_id, '_wei_ebay_de_title', true));
        $beforeDescription = trim((string) get_post_meta($product_id, '_wei_ebay_de_description', true));
        if ($beforeTitle !== '' && $beforeDescription !== '') {
            return [
                'result' => 'already_ready',
                'product_id' => $product_id,
                'title_length' => mb_strlen($beforeTitle),
                'description_length' => mb_strlen($beforeDescription),
                'source' => trim((string) get_post_meta($product_id, '_wei_ebay_de_content_source', true)) ?: 'custom_meta',
                'ebay_api_calls' => false,
                'published' => false,
                'offer_write_calls' => false,
                'wrote_woo_sku' => false,
                'wrote_woo_price' => false,
                'wrote_allegro' => false,
            ];
        }

        $previousSuppress = $this->suppressVerboseLogs;
        $this->suppressVerboseLogs = true;
        try {
            $content = $this->resolve_german_content($product, $product_id, 'EBAY_DE', $settings);
        } finally {
            $this->suppressVerboseLogs = $previousSuppress;
        }

        $ready = !empty($content['ready']);
        if ($ready) {
            $title = trim((string) ($content['title'] ?? ''));
            $description = trim((string) ($content['description'] ?? ''));
            if ($title !== '' && $description !== '') {
                update_post_meta($product_id, '_wei_ebay_de_title', $title);
                update_post_meta($product_id, '_wei_ebay_de_description', $description);
                update_post_meta($product_id, '_wei_ebay_de_content_source', (string) ($content['source'] ?? 'generated'));
                update_post_meta($product_id, '_wei_ebay_de_content_generated_at', gmdate('c'));
                update_post_meta($product_id, '_wei_ebay_de_content_hash', $this->german_content_source_hash($product));
            }
        }

        return [
            'result' => $ready ? 'generated' : 'failed',
            'product_id' => $product_id,
            'source' => (string) ($content['source'] ?? ''),
            'provider' => (string) ($content['provider'] ?? ''),
            'title_length' => (int) ($content['title_length'] ?? 0),
            'description_length' => (int) ($content['description_length'] ?? 0),
            'error_message' => (string) ($content['error_message'] ?? ''),
            'ebay_api_calls' => false,
            'published' => false,
            'offer_write_calls' => false,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ];
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
            if ($stale && !empty($settings['regenerate_german_content_on_hash_change']) && empty($settings['_wei_suppress_side_effects'])) {
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
            if ($metaTitle !== '' && $metaDescription !== '') {
                return $this->log_german_content($product_id, $product_id, 'translatepress_meta', $metaTitle, $metaDescription);
            }
        }

        if (!empty($settings['_wei_suppress_side_effects'])) {
            return [
                'ready' => false,
                'title' => '',
                'description' => '',
                'source' => 'missing',
                'language' => 'de-DE',
                'product_id' => $product_id,
                'source_product_id' => $product_id,
                'translated_product_id' => 0,
                'title_found' => false,
                'description_found' => false,
                'title_length' => 0,
                'description_length' => 0,
                'generated' => false,
                'side_effects_suppressed' => true,
                'error_message' => 'German content missing; generation skipped during dry category audit.',
            ];
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
            'product_id' => $sourceProductId,
            'source_product_id' => $sourceProductId,
            'translated_product_id' => $translatedProductId,
            'title_found' => $title !== '',
            'description_found' => $description !== '',
            'title_length' => mb_strlen($title),
            'description_length' => mb_strlen($description),
        ], $extra);
        if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($this->settings())) {
            $this->logger->info('Resolved German content for EBAY_DE', $result);
        }
        return $result;
    }

    private function maybe_generate_german_content($product, int $product_id, array $settings, string $reason): array
    {
        $providerKey = $this->configured_translation_provider_key($settings);
        $hash = $this->german_content_source_hash($product);
        $baseLog = [
            'product_id' => $product_id,
            'source_language' => 'pl',
            'target_language' => 'de',
            'provider' => $providerKey,
            'generated' => false,
            'content_hash' => $hash,
            'source_used' => $reason,
            'ebay_write_calls' => false,
            'message' => 'Preflight-only German content generation writes plugin meta _wei_* only; no publishOffer/createOrReplaceInventoryItem/createOffer/updateOffer calls are executed.',
        ];

        if (empty($settings['auto_generate_german_content_preflight'])) {
            $message = 'German content missing and automatic generation during preflight is disabled.';
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->warning('German content generator skipped', array_merge($baseLog, ['error_message' => $message]));
            }
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }

        $provider = $this->translation_provider($settings);
        if (!$provider || !$provider->is_configured()) {
            $message = 'German content missing and Google Translation provider is not configured.';
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->warning('German content generator unavailable', array_merge($baseLog, ['error_message' => $message]));
            }
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }

        try {
            $sourceTitle = (string) $product->get_name();
            $sourceDescription = trim((string) $product->get_description());
            $sourceShortDescription = trim((string) $product->get_short_description());
            if ($sourceShortDescription !== '' && $sourceShortDescription !== $sourceDescription) {
                $sourceDescription = trim($sourceDescription . "\n\n" . $sourceShortDescription);
            }
            $translated = $provider->translate_product_content($product, [
                'source_language' => 'pl',
                'target_language' => 'de',
                'source_title' => $sourceTitle,
                'source_description' => $sourceDescription,
                'mpn' => (string) ($this->resolve_mpn_aspect_value($product, $product_id, (string) $product->get_sku())['value'] ?? ''),
                'manufacturer' => $this->resolve_manufacturer_aspect_value($product, $product_id, '', $settings),
                'title_limit' => 80,
            ]);

            $mpn = (string) ($this->resolve_mpn_aspect_value($product, $product_id, (string) $product->get_sku())['value'] ?? '');
            $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id, '', $settings);
            $title = $this->sanitize_ebay_de_title((string) ($translated['title_de'] ?? ''), $mpn, $manufacturer);
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
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->info('German content generated and saved to plugin meta only', $extra);
            }
            return $this->log_german_content($product_id, $product_id, 'generated_' . $provider->provider_key(), $title, $description, $extra);
        } catch (\Throwable $e) {
            $message = 'German content generation failed: ' . $e->getMessage();
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->error('German content generator failed', array_merge($baseLog, ['error_message' => $message]));
            }
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }
    }




    private function build_ebay_de_description_template($product, int $productId, array $content, array $aspects, array $category): string
    {
        $descriptionText = trim(wp_strip_all_tags((string) ($content['description'] ?? '')));
        if ($descriptionText === '') {
            $descriptionText = 'Dieser Artikel wurde bereits benutzt. Der Artikel kann optische Gebrauchsspuren aufweisen, ist jedoch voll funktionsfähig und funktioniert wie vorgesehen.';
        }

        $baseDescription = [
            'Dieser Artikel wurde bereits benutzt. Der Artikel kann optische Gebrauchsspuren aufweisen, ist jedoch voll funktionsfähig und funktioniert wie vorgesehen.',
            'Bitte vergleichen Sie Ihre Teilenummer mit der von uns angegebenen Teilenummer und prüfen Sie die Fotos vor dem Kauf sorgfältig.',
            'Sie erhalten genau den Artikel, der auf den Bildern zu sehen ist.',
        ];

        $properties = $this->collect_template_properties($product, $productId, $aspects);
        $featureRows = '';
        foreach ($properties as $label => $value) {
            $featureRows .= '<tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#0b2a57;font-weight:600;">' . esc_html($label) . '</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#111827;">' . esc_html($value) . '</td></tr>';
        }

        $specRows = [];
        $specRows['Artikelzustand'] = (string) ($aspects['Artikelzustand'][0] ?? '');
        $specRows['Marke / Brand'] = (string) ($aspects['Marke'][0] ?? $aspects['Brand'][0] ?? '');
        $specRows['MPN'] = (string) ($aspects['MPN'][0] ?? '');
        $specRows['Kategorie'] = (string) ($category['category_path'] ?? '');
        $specRows['Manufacturer Part Number'] = (string) ($aspects['Herstellernummer'][0] ?? $aspects['Manufacturer Part Number'][0] ?? '');
        $specRows['Ursprungsland'] = (string) ($aspects['Ursprungsland'][0] ?? '');
        $specRows['Hinweise des Verkäufers'] = 'Gebraucht, geprüft, voll funktionsfähig';

        $specHtml = '';
        foreach ($specRows as $label => $value) {
            if (trim($value) === '') {
                continue;
            }
            $specHtml .= '<tr><td style="padding:10px;border:1px solid #d1d5db;background:#f9fafb;font-weight:600;color:#0b2a57;">' . esc_html($label) . '</td><td style="padding:10px;border:1px solid #d1d5db;color:#111827;">' . esc_html($value) . '</td></tr>';
        }

        $paragraphs = '';
        foreach ($baseDescription as $paragraph) {
            $paragraphs .= '<p style="margin:0 0 10px;color:#1f2937;line-height:1.55;">' . esc_html($paragraph) . '</p>';
        }
        if ($descriptionText !== '' && mb_stripos(implode(' ', $baseDescription), $descriptionText) === false) {
            $paragraphs .= '<p style="margin:0 0 10px;color:#1f2937;line-height:1.55;">' . esc_html($descriptionText) . '</p>';
        }

        return '<div style="max-width:980px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#111827;border:1px solid #dbe3ef;">'
            . '<div style="background:#0b2a57;color:#fff;padding:12px 16px;font-size:14px;font-weight:600;">Gebrauchtes Originalteil &nbsp;|&nbsp; Schneller Versand in Europa &nbsp;|&nbsp; Lieferzeit 2–5 Werktage</div>'
            . '<div style="padding:18px;">'
            . '<h2 style="margin:0 0 16px;color:#0b2a57;font-size:30px;line-height:1.2;">' . esc_html((string) ($content['title'] ?? $product->get_name())) . '</h2>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
            . '<td valign="top" width="52%" style="padding-right:12px;">'
            . '<h3 style="margin:0 0 10px;color:#0b2a57;font-size:22px;">Beschreibung</h3>' . $paragraphs
            . '<h3 style="margin:18px 0 10px;color:#0b2a57;font-size:20px;">Produktdetails</h3>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $featureRows . '</table></td>'
            . '<td valign="top" width="48%" style="padding-left:12px;">'
            . '<h3 style="margin:0 0 10px;color:#0b2a57;font-size:22px;">Artikelmerkmale</h3>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $specHtml . '</table></td>'
            . '</tr></table>'
            . '<div style="margin-top:20px;border:1px solid #d1d5db;background:#f8fafc;">'
            . '<div style="padding:14px 16px;color:#0b2a57;font-size:22px;font-weight:700;">Lieferung in ganz Europa</div>'
            . '<div style="padding:0 16px 14px;color:#1f2937;line-height:1.55;">Wir liefern europaweit. Die voraussichtliche Lieferzeit beträgt 2–5 Werktage. UK und benachbarte Inseln sind ebenfalls im Liefergebiet enthalten.</div>'
            . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:16px;"><tr>'
            . '<td width="50%" style="padding-right:8px;"><a href="#" style="display:block;background:#0b2a57;color:#fff;text-decoration:none;padding:12px 14px;border-radius:4px;font-weight:700;text-align:center;">Andere Teile aus diesem Fahrzeug ansehen</a></td>'
            . '<td width="50%" style="padding-left:8px;"><a href="#" style="display:block;background:#0b2a57;color:#fff;text-decoration:none;padding:12px 14px;border-radius:4px;font-weight:700;text-align:center;">Andere Artikel aus dieser Kategorie ansehen</a></td>'
            . '</tr></table>'
            . '<div style="margin-top:18px;border:1px solid #d1d5db;">'
            . '<div style="padding:10px 14px;background:#f9fafb;color:#0b2a57;font-weight:700;">FAQ</div><div style="padding:12px 14px;color:#1f2937;">Bitte vergleichen Sie die Teilenummer und Fahrzeugdaten vor dem Kauf.</div>'
            . '<div style="padding:10px 14px;background:#f9fafb;color:#0b2a57;font-weight:700;">Rückgabe</div><div style="padding:12px 14px;color:#1f2937;">Rückgabe innerhalb von 30 Tagen nach Erhalt gemäß den Angebotsbedingungen.</div>'
            . '<div style="padding:10px 14px;background:#f9fafb;color:#0b2a57;font-weight:700;">Versand</div><div style="padding:12px 14px;color:#1f2937;">Schneller und sicherer Versand. Lieferzeit in der Regel 2–5 Werktage.</div>'
            . '<div style="padding:10px 14px;background:#f9fafb;color:#0b2a57;font-weight:700;">Zahlung</div><div style="padding:12px 14px;color:#1f2937;">Zahlungsmethoden gemäß den bei eBay verfügbaren Optionen.</div>'
            . '</div>'
            . '<div style="margin-top:16px;border:1px solid #d1d5db;padding:12px 14px;background:#fff;"><strong style="color:#0b2a57;">Versanddienstleister:</strong> DHL</div>'
            . '</div></div>';
    }

    private function collect_template_properties($product, int $productId, array $aspects): array
    {
        $map = [
            'Teilenummer' => ['Teilenummer', 'Part Number', 'Herstellernummer'],
            'Fahrzeugmarke' => ['Fahrzeugmarke', 'Marke'],
            'Fahrzeugmodell' => ['Fahrzeugmodell', 'Modell'],
            'Baujahr' => ['Baujahr'],
            'Motorkennbuchstabe' => ['Motorkennbuchstabe'],
            'Motorleistung' => ['Motorleistung'],
            'Getriebe' => ['Getriebe'],
            'Farbe' => ['Farbe'],
        ];

        $rows = [];
        foreach ($map as $label => $keys) {
            $value = '';
            foreach ($keys as $key) {
                if (!empty($aspects[$key][0])) {
                    $value = (string) $aspects[$key][0];
                    break;
                }
                $meta = get_post_meta($productId, $key, true);
                if (is_string($meta) && trim($meta) !== '') {
                    $value = trim($meta);
                    break;
                }
            }
            if ($value !== '') {
                $rows[$label] = $value;
            }
        }

        return $rows;
    }
    private function sanitize_ebay_de_title(string $title, string $mpn = '', string $manufacturer = ''): string
    {
        $title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($title)) ?: '');
        $important = array_values(array_filter(array_unique(array_map('trim', [$manufacturer, $mpn])), static fn($value) => $value !== ''));

        $candidate = $title;
        if (mb_strlen($candidate) > 80) {
            $candidate = trim(mb_substr($candidate, 0, 80));
        }

        $missing = [];
        foreach ($important as $term) {
            if (mb_stripos($candidate, $term) === false) {
                $missing[] = $term;
            }
        }

        if ($missing !== []) {
            $suffix = implode(' ', $missing);
            $suffixLength = mb_strlen($suffix);
            if ($suffixLength < 80) {
                $mainLimit = 80 - $suffixLength - 1;
                $candidate = trim(mb_substr($title, 0, max(0, $mainLimit)));
                $candidate = trim($candidate . ' ' . $suffix);
            }
        }

        if (mb_strlen($candidate) > 80) {
            $candidate = trim(mb_substr($candidate, 0, 80));
        }

        return $candidate;
    }

    private function configured_translation_provider_key(array $settings): string
    {
        $provider = strtolower(trim((string) ($settings['translation_provider'] ?? 'disabled')));
        if ($provider === 'google') {
            $provider = 'google_cloud_translate';
        }
        if ($provider === '' || !in_array($provider, ['disabled', 'google_cloud_translate', 'openai'], true)) {
            return 'disabled';
        }
        return $provider;
    }

    private function translation_provider(array $settings): ?TranslationProviderInterface
    {
        $provider = $this->configured_translation_provider_key($settings);
        if ($provider === 'google_cloud_translate') {
            return new GoogleCloudTranslateProvider($settings, $this->logger);
        }
        if ($provider === 'openai') {
            // Optional/dev provider kept out of the main admin UI.
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
        $productOverride = $this->resolve_product_category_override($product_id, $settings);
        if ($productOverride['category_id'] !== '') {
            return $productOverride;
        }

        $skuOverride = $this->resolve_debug_sku_category_override($product, $sku, $settings);
        if ($skuOverride['category_id'] !== '') {
            return $skuOverride;
        }

        $autoCandidates = [];
        $reviewCandidate = null;
        $terms = wp_get_post_terms($product_id, 'product_cat');
        if (!is_wp_error($terms)) {
            foreach ((array) $terms as $term) {
                $termId = (int) $term->term_id;
                $wooPath = $this->categoryRepo->woo_category_path($termId);
                $intent = CategoryMappingSafety::detect_intent(trim($wooPath . ' ' . (string) $product->get_name()));
                $teachingRule = $this->categoryRepo->find_teaching_rule($marketplaceId, $wooPath, $intent, (string) $product->get_name());
                if (is_array($teachingRule) && trim((string) ($teachingRule['ebay_category_id'] ?? '')) !== '') {
                    $categoryId = (string) $teachingRule['ebay_category_id'];
                    $categoryPath = (string) ($teachingRule['ebay_category_path'] ?? '');
                    $safety = CategoryMappingSafety::manual_woo_category_mapping_check($wooPath, $categoryId, $categoryPath . ' ' . $categoryId);
                    if (!empty($safety['pass'])) {
                        return ['category_id' => $categoryId, 'category_path' => $categoryPath, 'status' => 'ready_manual', 'source' => 'manual_woo_category_mapping', 'mapping' => $teachingRule + ['woo_category_path' => $wooPath, 'ebay_category_id' => $categoryId, 'ebay_category_path' => $categoryPath, 'source' => 'manual_woo_category_mapping'], 'confidence' => 1.0, 'manual_teaching_rule_id' => (int) ($teachingRule['id'] ?? 0), 'sanity_check_pass' => true, 'sanity_reason' => '', 'manual_warning' => (string) ($safety['warning'] ?? ''), 'product_override_found' => false];
                    }
                    $reviewCandidate ??= ['category_id' => '', 'selected_candidate_category_id' => $categoryId, 'selected_candidate_category_path' => $categoryPath, 'selected_candidate_confidence' => 1.0, 'selected_candidate_source' => 'manual_woo_category_mapping', 'category_path' => $categoryPath, 'status' => 'category_sanity_failed', 'source' => 'manual_woo_category_mapping_safety_failed', 'mapping' => $teachingRule + ['woo_category_path' => $wooPath], 'confidence' => 1.0, 'threshold' => CategoryMappingSafety::threshold($settings), 'sanity_check_pass' => false, 'sanity_reason' => (string) ($safety['reason'] ?? 'manual_woo_category_mapping_failed_safety'), 'product_override_found' => false];
                }

                $mapping = $this->categoryRepo->find($marketplaceId, $termId);
                if (!$mapping) {
                    continue;
                }

                $status = (string) ($mapping['status'] ?? '');
                $source = (string) ($mapping['source'] ?? '');
                $confidence = (float) ($mapping['confidence'] ?? 0);
                if (in_array($status, ['mapped_manual', 'mapped_manual_teaching', 'mapped_manual_woo_category'], true) || ($status === '' && $source === 'manual') || in_array($source, ['manual_teaching_csv', 'manual_woo_category_mapping'], true)) {
                    return ['category_id' => (string) $mapping['ebay_category_id'], 'status' => 'ready_manual', 'source' => $source === 'manual_woo_category_mapping' ? 'manual_woo_category_mapping' : ($source === 'manual_teaching_csv' ? 'manual_teaching_csv' : 'woo_category_mapping_manual'), 'mapping' => $mapping, 'confidence' => $confidence, 'product_override_found' => false];
                }

                if ($status === 'mapped_auto' || $source === 'auto_taxonomy') {
                    $evaluation = $this->evaluate_category_mapping_row($mapping, $settings, (string) $product->get_name());
                    if (!empty($evaluation['accepted'])) {
                        $autoCandidates[] = ['category_id' => (string) $mapping['ebay_category_id'], 'status' => 'ready_auto', 'source' => 'woo_category_mapping_auto', 'mapping' => $mapping, 'confidence' => $confidence, 'threshold' => (float) ($evaluation['threshold'] ?? 0), 'sanity_check_pass' => !empty($evaluation['sanity_check_pass']), 'sanity_reason' => (string) ($evaluation['sanity_reason'] ?? ''), 'product_override_found' => false];
                    } else {
                        $blockedStatus = (string) ($evaluation['final_status'] ?? 'needs_category_review');
                        $reviewCandidate ??= $this->blocked_mapping_review_candidate($mapping, $blockedStatus, $blockedStatus === 'category_sanity_failed' ? 'woo_category_mapping_sanity_failed' : 'woo_category_mapping_auto_below_threshold', $confidence, $settings, !empty($evaluation['sanity_check_pass']), (string) ($evaluation['sanity_reason'] ?? ''));
                        if ($reviewCandidate !== null && isset($evaluation['threshold'])) {
                            $reviewCandidate['threshold'] = (float) $evaluation['threshold'];
                        }
                    }
                    continue;
                }

                if ($status === 'needs_category_review' || $status === 'low_confidence_auto' || $status === 'category_sanity_failed') {
                    $reviewCandidate ??= $this->blocked_mapping_review_candidate($mapping, $status, 'woo_category_mapping_' . $status, $confidence, $settings, $status !== 'category_sanity_failed', (string) ($mapping['error_reason'] ?? ''));
                    continue;
                }

                if (in_array($status, ['taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true)) {
                    $reviewCandidate ??= $this->blocked_mapping_review_candidate($mapping, $status, 'woo_category_mapping_' . $status, $confidence, $settings, $status !== 'category_sanity_failed', (string) ($mapping['error_reason'] ?? ''));
                }
            }

            foreach ($autoCandidates as $candidate) {
                return $candidate;
            }

            foreach ((array) $terms as $term) {
                $fallback = $this->static_category_fallback((int) $term->term_id, $marketplaceId);
                if ($fallback['category_id'] !== '') {
                    $fallback['product_override_found'] = false;
                    return $fallback;
                }
            }
        }

        if ($reviewCandidate !== null) {
            return $reviewCandidate;
        }

        return ['category_id' => '', 'status' => 'needs_category_review', 'source' => 'missing_category_mapping', 'confidence' => 0.0, 'product_override_found' => false];
    }

    private function evaluate_category_mapping_row(array $mapping, array $settings, string $productTitle = ''): array
    {
        $status = (string) ($mapping['status'] ?? '');
        $source = (string) ($mapping['source'] ?? '');
        $categoryId = trim((string) ($mapping['ebay_category_id'] ?? ''));
        $confidence = (float) ($mapping['confidence'] ?? 0);
        $threshold = CategoryMappingSafety::threshold($settings);

        if ($categoryId !== '' && (in_array($status, ['mapped_manual', 'mapped_manual_teaching', 'mapped_manual_woo_category'], true) || ($status === '' && $source === 'manual') || in_array($source, ['manual', 'manual_teaching_csv', 'manual_woo_category_mapping'], true))) {
            return ['accepted' => true, 'final_status' => 'ready_manual', 'ui_status' => 'accepted_manual', 'threshold' => $threshold, 'sanity_check_pass' => true, 'sanity_reason' => ''];
        }

        if ($categoryId !== '' && ($status === 'mapped_auto' || $source === 'auto_taxonomy')) {
            $mappingText = trim((string) (($mapping['ebay_category_path'] ?? '') . ' ' . ($mapping['ebay_category_name'] ?? '')));
            $safetyContext = trim((string) ($mapping['woo_category_path'] ?? '') . ' ' . $productTitle);
            $safety = CategoryMappingSafety::evaluate_auto_mapping(
                $safetyContext,
                $mappingText,
                $confidence,
                $settings
            );
            $safety['final_status'] = !empty($safety['accepted']) ? 'ready_auto' : (string) ($safety['status'] ?? 'needs_category_review');
            return $safety;
        }

        return ['accepted' => false, 'final_status' => $status !== '' ? $status : 'unmapped', 'ui_status' => 'needs_category_review', 'threshold' => $threshold, 'sanity_check_pass' => true, 'sanity_reason' => ''];
    }


    private function blocked_mapping_review_candidate(array $mapping, string $status, string $source, float $confidence, array $settings, bool $sanityPass = true, string $sanityReason = ''): array
    {
        return [
            'category_id' => '',
            'selected_candidate_category_id' => (string) ($mapping['ebay_category_id'] ?? ''),
            'selected_candidate_category_name' => (string) ($mapping['ebay_category_name'] ?? ''),
            'selected_candidate_category_path' => (string) ($mapping['ebay_category_path'] ?? ''),
            'selected_candidate_confidence' => $confidence,
            'selected_candidate_source' => (string) ($mapping['source'] ?? $source),
            'category_name' => (string) ($mapping['ebay_category_name'] ?? ''),
            'category_path' => (string) ($mapping['ebay_category_path'] ?? ''),
            'status' => $status,
            'source' => $source,
            'mapping' => $mapping,
            'confidence' => $confidence,
            'threshold' => CategoryMappingSafety::threshold($settings),
            'sanity_check_pass' => $sanityPass,
            'sanity_reason' => $sanityReason,
            'product_override_found' => false,
        ];
    }

    private function resolve_product_category_override(int $product_id, array $settings): array
    {
        $metaCategoryId = trim((string) get_post_meta($product_id, '_wei_ebay_category_id', true));
        if ($metaCategoryId !== '') {
            return [
                'category_id' => $metaCategoryId,
                'category_name' => trim((string) get_post_meta($product_id, '_wei_ebay_category_name', true)),
                'category_path' => trim((string) get_post_meta($product_id, '_wei_ebay_category_path', true)),
                'status' => 'ready_manual',
                'source' => 'product_override',
                'meta_source' => trim((string) get_post_meta($product_id, '_wei_ebay_category_source', true)) ?: 'manual_product_override',
                'confidence' => 1.0,
                'product_override_found' => true,
            ];
        }

        $settingsOverrides = $this->parse_product_category_overrides((string) ($settings['product_category_overrides'] ?? ''));
        if (isset($settingsOverrides[$product_id]) && $settingsOverrides[$product_id] !== '') {
            return [
                'category_id' => $settingsOverrides[$product_id],
                'category_name' => $this->static_category_name($settingsOverrides[$product_id]),
                'category_path' => $this->static_category_path($settingsOverrides[$product_id]),
                'status' => 'ready_manual',
                'source' => 'product_override',
                'meta_source' => 'manual_product_override',
                'override_config_source' => 'settings_dev_debug_product_category_overrides',
                'confidence' => 1.0,
                'product_override_found' => true,
            ];
        }

        return ['category_id' => '', 'product_override_found' => false];
    }

    private function resolve_debug_sku_category_override($product, string $sku, array $settings): array
    {
        $skuOverrides = $this->parse_sku_category_overrides((string) ($settings['sku_category_overrides'] ?? ''));
        if ($skuOverrides === []) {
            return ['category_id' => ''];
        }

        $candidateSkus = [$sku];
        if (is_object($product) && method_exists($product, 'get_sku')) {
            $wooSku = trim((string) $product->get_sku());
            if ($wooSku !== '') {
                $candidateSkus[] = $wooSku;
            }
        }

        foreach (array_values(array_unique(array_filter($candidateSkus))) as $candidateSku) {
            if (isset($skuOverrides[$candidateSku]) && $skuOverrides[$candidateSku] !== '') {
                return ['category_id' => $skuOverrides[$candidateSku], 'status' => 'ready_manual', 'source' => 'debug_sku_override', 'override_sku' => $candidateSku, 'confidence' => 1.0, 'product_override_found' => false];
            }
        }

        return ['category_id' => ''];
    }

    private function static_category_fallback(int $termId, string $marketplaceId): array
    {
        if ($marketplaceId !== 'EBAY_DE') {
            return ['category_id' => '', 'status' => 'needs_category_review', 'source' => 'static_fallback', 'confidence' => 0.0];
        }

        $path = mb_strtolower($this->categoryRepo->woo_category_path($termId));
        $normalized = remove_accents($path);
        if (str_contains($normalized, 'wiazki przewodow') || str_contains($path, 'wiązki przewodów')) {
            return ['category_id' => '179847', 'category_name' => $this->static_category_name('179847'), 'category_path' => $this->static_category_path('179847'), 'status' => 'ready_auto', 'source' => 'static_fallback', 'confidence' => 0.9, 'woo_term_id' => $termId, 'sanity_check_pass' => true];
        }

        if (CategoryMappingSafety::is_complete_engine_intent($path)) {
            return ['category_id' => '33615', 'category_name' => $this->static_category_name('33615'), 'category_path' => $this->static_category_path('33615'), 'status' => 'ready_auto', 'source' => 'static_fallback_complete_engine', 'confidence' => 0.95, 'woo_term_id' => $termId, 'sanity_check_pass' => true];
        }

        return ['category_id' => '', 'status' => 'needs_category_review', 'source' => 'static_fallback', 'confidence' => 0.0];
    }

    private function preflight_validate($product, int $product_id, array $skuResolution, array $content, array $category, array $aspects, array $settings): array
    {
        $errors = [];
        $status = 'ready';
        $categoryId = (string) ($category['category_id'] ?? '');
        $requiredAspects = $this->taxonomy->get_required_aspects($this->marketplace_id(), $categoryId);
        $categoryText = trim(implode(' ', array_filter([
            (string) ($category['category_path'] ?? ''),
            (string) ($category['category_name'] ?? ''),
            (string) ($category['mapping']['ebay_category_path'] ?? ''),
            (string) ($category['mapping']['ebay_category_name'] ?? ''),
        ], static fn($value): bool => trim((string) $value) !== '')));
        $sourceText = trim(implode(' ', array_filter([
            is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : '',
            (string) ($content['title'] ?? ''),
            (string) ($content['description'] ?? ''),
            (string) ($category['mapping']['woo_category_path'] ?? ''),
        ], static fn($value): bool => trim((string) $value) !== '')));
        $selectedCandidateCategoryId = $categoryId;
        $selectedCandidateCategoryName = (string) ($category['category_name'] ?? $category['selected_candidate_category_name'] ?? '');
        $selectedCandidateCategoryPath = (string) ($category['category_path'] ?? $category['selected_candidate_category_path'] ?? '');
        $selectedCandidateConfidence = (float) ($category['confidence'] ?? $category['selected_candidate_confidence'] ?? 0);
        $selectedCandidateSource = (string) ($category['source'] ?? $category['selected_candidate_source'] ?? '');
        $selectedCategorySafety = CategoryMappingSafety::selected_category_check($sourceText, $categoryId, $categoryText, $requiredAspects);
        if (empty($selectedCategorySafety['pass'])) {
            $category['status'] = 'category_sanity_failed';
            $category['sanity_check_pass'] = false;
            $category['sanity_reason'] = (string) ($selectedCategorySafety['reason'] ?? 'selected_category_sanity_failed');
            $categoryId = '';
        }
        $missingAspects = $categoryId !== '' ? array_values(array_filter($requiredAspects, static fn($name) => empty($aspects[$name]))) : [];
        $priceResolution = $this->resolve_price($product, $product_id, $settings);
        if ($this->verbose_debug_enabled($settings) && !$this->suppressVerboseLogs) {
            $this->logger->info('Resolved eBay category for preflight/export', [
                'product_id' => $product_id,
                'category_id' => $categoryId,
                'category_source' => (string) ($category['source'] ?? ''),
                'category_confidence' => (float) ($category['confidence'] ?? 0),
                'auto_category_confidence_threshold' => CategoryMappingSafety::threshold($settings),
                'category_sanity_check_pass' => array_key_exists('sanity_check_pass', $category) ? !empty($category['sanity_check_pass']) : true,
                'category_sanity_reason' => (string) ($category['sanity_reason'] ?? ''),
                'category_status' => (string) ($category['status'] ?? ''),
                'selected_candidate_category_id' => $selectedCandidateCategoryId,
                'selected_candidate_category_name' => $selectedCandidateCategoryName,
                'selected_candidate_category_path' => $selectedCandidateCategoryPath,
                'selected_candidate_confidence' => $selectedCandidateConfidence,
                'selected_candidate_source' => $selectedCandidateSource,
                'product_override_found' => !empty($category['product_override_found']) ? 'yes' : 'no',
            ]);
        }

        if ($skuResolution['sku'] === '') $errors[] = 'final eBay SKU missing';
        if (empty($content['title']) || empty($content['description'])) { $errors[] = (string) ($content['error_message'] ?? 'German title/description missing'); $status = 'not_ready_missing_german_content'; }
        if (!empty($content['title']) && mb_strlen((string) $content['title']) > 80) $errors[] = 'German title is longer than 80 characters';
        if ($categoryId === '') { $errors[] = 'Category mapping requires review'; $categoryStatus = (string) ($category['status'] ?? ''); $status = in_array($categoryStatus, ['needs_category_review', 'low_confidence_auto', 'category_sanity_failed', 'taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true) ? $categoryStatus : 'needs_category_review'; }
        if ($missingAspects !== [] && $categoryId !== '') { $errors[] = 'missing required aspect ' . implode(', ', $missingAspects); $status = 'missing_required_aspects'; }
        $shippingPolicyResolution = EbayShippingPolicyResolver::resolve_for_product($product_id, $settings);
        $policyValidation = $this->validate_selected_policies($settings, [(string) ($shippingPolicyResolution['policy_id'] ?? '')]);
        if (!$policyValidation['valid']) $errors[] = 'business policies missing or invalid';
        if (empty($priceResolution['ready'])) { $priceError = (string) ($priceResolution['error'] ?? 'invalid_price'); $errors[] = $priceError === 'missing_exchange_rate' ? 'NBP EUR exchange rate missing' : 'price invalid'; $status = $priceError === 'missing_exchange_rate' ? 'missing_exchange_rate' : 'invalid_price'; }
        if ((int) $product->get_stock_quantity() < 0) $errors[] = 'stock invalid';
        if (!$product->get_image_id()) $errors[] = 'image missing';
        if ($this->merchant_location_key() === '') $errors[] = 'inventory location missing';

        $ready = $errors === [];
        $message = $ready ? 'Product ready for eBay export.' : 'Product not ready for eBay: ' . implode('; ', $errors) . '.';
        if (!$ready && in_array('Category mapping requires review', $errors, true)) {
            $message = 'Category mapping requires review.';
        }
        if (in_array('Hersteller', $missingAspects, true)) {
            $message = 'Product not ready for eBay: missing required aspect Hersteller. Configure brand/manufacturer mapping.';
        }

        return ['ready' => $ready, 'status' => $ready ? 'ready' : $status, 'message' => $message, 'product_id' => $product_id, 'sku_resolution' => $skuResolution, 'content' => $content, 'category' => $category, 'price_resolution' => $priceResolution, 'shipping_policy_resolution' => $shippingPolicyResolution, 'policy_validation' => $policyValidation, 'required_aspects' => $requiredAspects, 'missing_aspects' => $missingAspects, 'aspects' => $aspects, 'errors' => $errors];
    }


    private function verbose_debug_enabled(array $settings): bool
    {
        return !empty($settings['verbose_debug']);
    }

    private function resolve_price($product, int $product_id, array $settings): array
    {
        if ($this->marketplace_id() !== 'EBAY_DE') {
            $price = (float) $product->get_price();
            return [
                'base_price_pln' => $price,
                'markup_percent' => 0,
                'markup_source' => 'not_applicable',
                'marked_price_pln' => $price,
                'currency_source' => get_woocommerce_currency(),
                'nbp_rate' => null,
                'nbp_effective_date' => '',
                'ebay_price_eur' => $price,
                'ready' => $price > 0,
                'error' => $price > 0 ? '' : 'invalid_price',
            ];
        }

        return $this->priceResolver ? $this->priceResolver->resolve($product, $product_id, $settings, !empty($settings['_wei_suppress_side_effects']) && empty($settings['verbose_debug'])) : [
            'ready' => false,
            'error' => 'missing_exchange_rate',
            'base_price_pln' => (float) $product->get_price(),
            'markup_percent' => null,
            'markup_source' => 'resolver_missing',
            'marked_price_pln' => null,
            'currency_source' => 'nbp_table_a',
            'nbp_rate' => null,
            'nbp_effective_date' => '',
            'ebay_price_eur' => null,
        ];
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

    private function resolve_product_aspects($product, int $product_id, string $sku, array $settings, string $categoryId = '', array $content = []): array
    {
        $aspects = [];

        $mpn = $this->resolve_mpn_aspect_value($product, $product_id, $sku, $content);
        $mpnValue = (string) ($mpn['value'] ?? '');
        if ($mpnValue !== '') {
            $aspects['MPN'] = [$mpnValue];
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

        $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id, $categoryId, $settings, $content);
        if ($manufacturer !== '') {
            $aspects['Hersteller'] = [$manufacturer];
        }

        $productOverrides = $this->parse_aspects_json((string) get_post_meta($product_id, '_wei_ebay_aspects_json', true));
        $settingsOverrides = $this->resolve_configured_aspect_overrides($sku, $settings);

        $resolved = $this->merge_aspects($aspects, $settingsOverrides, $productOverrides);
        $required = $this->taxonomy->get_required_aspects($this->marketplace_id(), $categoryId);
        $resolved = $this->apply_part_number_aspect_aliases($resolved, $required, $mpn);
        $resolved = $this->apply_safe_required_aspect_repairs($resolved, $required, $product, $product_id, $categoryId, $settings, $content);
        $cleanup = $this->cleanup_condition_aspects($resolved);
        $resolved = $cleanup['aspects'];
        if ($cleanup['removed'] !== []) {
            $this->logger->info('EBAY_CONDITION_ASPECT_CLEANUP', [
                'product_id' => $product_id,
                'sku' => $sku,
                'removed_aspects' => $cleanup['removed'],
            ]);
        }
        $missing = array_values(array_filter($required, static fn($name) => empty($resolved[$name])));
        if ($this->verbose_debug_enabled($settings) && !$this->suppressVerboseLogs) {
            $this->logger->info('Required aspects for category ' . $categoryId . ': ' . implode(', ', $required), [
                'product_id' => $product_id,
                'sku' => $sku,
                'category_id' => $categoryId,
                'required_aspects' => $required,
                'missing_aspects' => $missing,
                'final_product_aspects' => $resolved,
            ]);
        }

        return $resolved;
    }

    private function cleanup_condition_aspects(array $aspects): array
    {
        $removed = [];
        $blockedNames = ['stan', 'stanopakowania', 'uzywany', 'nowy', 'nowa', 'nowe'];
        foreach ($aspects as $name => $values) {
            $normalizedName = $this->normalize_aspect_alias_name((string) $name);
            $remove = in_array($normalizedName, $blockedNames, true);
            $reason = $remove ? 'blocked_name' : '';
            if (!$remove) {
                foreach ($this->normalize_aspect_values($values) as $value) {
                    if (preg_match('/\b(nowy|nowa|nowe|neu|new)\b/iu', (string) $value)) {
                        $remove = true;
                        $reason = 'blocked_value';
                        break;
                    }
                }
            }
            if ($remove) {
                $removed[] = ['name' => (string) $name, 'reason' => $reason, 'values' => $this->normalize_aspect_values($values)];
                unset($aspects[$name]);
            }
        }

        return ['aspects' => $aspects, 'removed' => $removed];
    }

    public function clean_condition_aspects_single(string $productOrSku): array
    {
        $identifier = trim($productOrSku);
        if ($identifier === '') return ['result' => 'error', 'error' => 'missing_input'];
        $this->logger->info('EBAY_CONDITION_CLEANUP_SINGLE_START', ['input' => $identifier]);
        $productId = ctype_digit($identifier) ? (int) $identifier : 0;
        if ($productId <= 0) {
            $found = wc_get_products(['limit' => 1, 'sku' => $identifier, 'status' => ['publish', 'draft', 'private']]);
            $productId = !empty($found[0]) ? (int) $found[0]->get_id() : 0;
        }
        if ($productId <= 0) {
            $this->logger->error('EBAY_CONDITION_CLEANUP_FAILED', ['input' => $identifier, 'reason' => 'product_not_found']);
            return ['result' => 'error', 'error' => 'product_not_found'];
        }
        $product = wc_get_product($productId);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];
        $settings = $this->settings();
        $sku = (string) $this->resolve_ebay_sku($product, $productId, null, $settings)['sku'];
        $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
        $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        if ($offerId === '' || $listingId === '' || $sku === '') return ['result' => 'error', 'error' => 'missing_offer_listing_sku'];
        $inventory = $this->client->get_inventory_item($sku, ['stage' => 'condition_cleanup_single', 'product_id' => $productId, 'sku' => $sku]);
        if (is_wp_error($inventory)) return ['result' => 'error', 'error' => $inventory->get_error_message()];
        $beforeAspects = (array) ($inventory['product']['aspects'] ?? []);
        $cleanup = $this->cleanup_condition_aspects($beforeAspects);
        $description = (string) ($inventory['product']['description'] ?? '');
        $descriptionAudit = $this->analyze_description_condition_conflict($description);
        $descriptionContainsNeu = (bool) $descriptionAudit['description_contains_neu'];
        $descriptionConditionConflict = (bool) $descriptionAudit['description_contains_new_like_words'] && (string) ($inventory['condition'] ?? '') === EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        $changed = $cleanup['removed'] !== [] || (string) ($inventory['condition'] ?? '') !== EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        $beforeCondition = (string) ($inventory['condition'] ?? '');
        $beforeCondition = (string) ($inventory['condition'] ?? '');
        $beforeCondition = (string) ($inventory['condition'] ?? '');
        $beforeCondition = (string) ($inventory['condition'] ?? '');
        $inventory['condition'] = EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        $inventory['product']['aspects'] = $cleanup['aspects'];
        $this->logger->info('EBAY_CONDITION_ASPECT_CLEANUP', [
            'product_id' => $productId,
            'sku' => $sku,
            'removed_aspects' => $cleanup['removed'],
            'description_contains_neu' => $descriptionContainsNeu,
            'description_condition_conflict' => $descriptionConditionConflict,
            'description_contains_new_like_words' => (bool) $descriptionAudit['description_contains_new_like_words'],
            'description_cleanup_safe' => (bool) $descriptionAudit['description_cleanup_safe'],
            'review_required' => (bool) $descriptionAudit['review_required'],
            'confidence' => (float) $descriptionAudit['confidence'],
            'suggested_replacements' => $descriptionAudit['suggested_replacements'],
        ]);
        if ($changed) {
            $update = $this->client->create_or_replace_inventory_item($sku, $inventory, ['stage' => 'condition_cleanup_single_update', 'product_id' => $productId, 'sku' => $sku]);
            if (is_wp_error($update)) {
                $this->logger->error('EBAY_CONDITION_CLEANUP_FAILED', ['product_id' => $productId, 'sku' => $sku, 'error' => $update->get_error_message()]);
                return ['result' => 'error', 'error' => $update->get_error_message()];
            }
            $this->logger->info('EBAY_CONDITION_CLEANUP_CHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
        } else {
            $this->logger->info('EBAY_CONDITION_CLEANUP_UNCHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
        }
        $this->logger->info('EBAY_CONDITION_CLEANUP_SINGLE_DONE', ['product_id' => $productId, 'sku' => $sku, 'changed' => $changed]);
        return [
            'result' => 'success',
            'changed' => $changed,
            'product_id' => $productId,
            'sku' => $sku,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'removed_aspects' => $cleanup['removed'],
            'description_contains_neu' => $descriptionContainsNeu,
            'description_condition_conflict' => $descriptionConditionConflict,
            'description_contains_new_like_words' => (bool) $descriptionAudit['description_contains_new_like_words'],
            'description_cleanup_safe' => (bool) $descriptionAudit['description_cleanup_safe'],
            'review_required' => (bool) $descriptionAudit['review_required'],
            'confidence' => (float) $descriptionAudit['confidence'],
            'suggested_replacements' => $descriptionAudit['suggested_replacements'],
            'suggested_description_snippet' => (string) $descriptionAudit['suggested_description_snippet'],
        ];
    }

    public function update_basic_item_specifics_single(string $productOrSku): array
    {
        $identifier = trim($productOrSku);
        if ($identifier === '') return ['result' => 'error', 'error' => 'missing_input'];
        $this->logger->info('EBAY_BASIC_SPECIFICS_SINGLE_START', ['input' => $identifier]);
        $productId = ctype_digit($identifier) ? (int) $identifier : 0;
        if ($productId <= 0) {
            $found = wc_get_products(['limit' => 1, 'sku' => $identifier, 'status' => ['publish', 'draft', 'private']]);
            $productId = !empty($found[0]) ? (int) $found[0]->get_id() : 0;
        }
        if ($productId <= 0) {
            $this->logger->error('EBAY_BASIC_SPECIFICS_FAILED', ['input' => $identifier, 'reason' => 'product_not_found']);
            return ['result' => 'error', 'error' => 'product_not_found'];
        }
        $product = wc_get_product($productId);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];
        $settings = $this->settings();
        $sku = (string) $this->resolve_ebay_sku($product, $productId, null, $settings)['sku'];
        $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
        $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        if ($offerId === '' || $listingId === '' || $sku === '') return ['result' => 'error', 'error' => 'missing_offer_listing_sku'];
        $inventory = $this->client->get_inventory_item($sku, ['stage' => 'basic_specifics_single', 'product_id' => $productId, 'sku' => $sku]);
        if (is_wp_error($inventory)) return ['result' => 'error', 'error' => $inventory->get_error_message()];

        $existingAspects = is_array($inventory['product']['aspects'] ?? null) ? $inventory['product']['aspects'] : [];
        $resolved = $this->resolve_basic_item_specifics($product, $productId, $sku, $settings, $existingAspects);
        $this->logger->info('EBAY_BASIC_SPECIFICS_RESOLVED', ['product_id' => $productId, 'sku' => $sku, 'resolved' => $resolved]);

        $inventory['condition'] = EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        $inventory['product']['aspects'] = $resolved['aspects'];
        if ($resolved['condition_description_supported']) {
            $inventory['conditionDescription'] = $resolved['condition_description'];
        }
        $beforeAspectsHash = md5(wp_json_encode($existingAspects));
        $afterAspectsHash = md5(wp_json_encode($resolved['aspects']));
        $changed = $beforeAspectsHash !== $afterAspectsHash || $beforeCondition !== EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        if ($changed) {
            $update = $this->client->create_or_replace_inventory_item($sku, $inventory, ['stage' => 'basic_specifics_single_update', 'product_id' => $productId, 'sku' => $sku]);
            if (is_wp_error($update)) {
                $this->logger->error('EBAY_BASIC_SPECIFICS_FAILED', ['product_id' => $productId, 'sku' => $sku, 'error' => $update->get_error_message()]);
                return ['result' => 'error', 'error' => $update->get_error_message()];
            }
            $inventoryAfterUpdate = $this->client->get_inventory_item($sku, ['stage' => 'basic_specifics_single_inventory_after_update', 'product_id' => $productId, 'sku' => $sku]);
            if (is_wp_error($inventoryAfterUpdate)) {
                $this->logger->error('EBAY_BASIC_SPECIFICS_FAILED', ['product_id' => $productId, 'sku' => $sku, 'error' => $inventoryAfterUpdate->get_error_message(), 'phase' => 'get_inventory_item_after_update']);
                return ['result' => 'error', 'error' => $inventoryAfterUpdate->get_error_message()];
            }
            $this->logger->info('EBAY_BASIC_SPECIFICS_INVENTORY_AFTER_UPDATE', [
                'product_id' => $productId,
                'sku' => $sku,
                'offer_id' => $offerId,
                'listing_id' => $listingId,
                'condition' => (string) ($inventoryAfterUpdate['condition'] ?? ''),
                'product_aspects' => is_array($inventoryAfterUpdate['product']['aspects'] ?? null) ? $inventoryAfterUpdate['product']['aspects'] : [],
            ]);

            $offer = $this->client->get_offer($offerId, ['stage' => 'basic_specifics_single_offer_refresh_get_offer', 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId]);
            if (is_wp_error($offer)) {
                $this->logger->error('EBAY_BASIC_SPECIFICS_FAILED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'error' => $offer->get_error_message(), 'phase' => 'get_offer_before_refresh']);
                return ['result' => 'error', 'error' => $offer->get_error_message()];
            }
            $this->logger->info('EBAY_BASIC_SPECIFICS_OFFER_REFRESH_START', [
                'product_id' => $productId,
                'sku' => $sku,
                'offer_id' => $offerId,
                'listing_id' => $listingId,
                'called_create_offer' => false,
                'called_publish_offer' => false,
                'changed_only' => 'basic_item_specifics_refresh',
                'preserved_price' => true,
                'preserved_stock' => true,
                'preserved_shipping' => true,
                'preserved_category' => true,
            ]);
            $refreshed = $this->client->update_offer($offerId, (array) $offer, ['stage' => 'basic_specifics_single_offer_refresh_update_offer', 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId]);
            if (is_wp_error($refreshed)) {
                $this->logger->error('EBAY_BASIC_SPECIFICS_FAILED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'error' => $refreshed->get_error_message(), 'phase' => 'update_offer_refresh']);
                return ['result' => 'error', 'error' => $refreshed->get_error_message()];
            }
            $this->logger->info('EBAY_BASIC_SPECIFICS_OFFER_REFRESH_DONE', [
                'product_id' => $productId,
                'sku' => $sku,
                'offer_id' => $offerId,
                'listing_id' => $listingId,
                'called_create_offer' => false,
                'called_publish_offer' => false,
                'changed_only' => 'basic_item_specifics_refresh',
                'preserved_price' => true,
                'preserved_stock' => true,
                'preserved_shipping' => true,
                'preserved_category' => true,
            ]);
            $this->logger->info('EBAY_BASIC_SPECIFICS_PUBLIC_REFRESH_HINT', [
                'product_id' => $productId,
                'sku' => $sku,
                'offer_id' => $offerId,
                'listing_id' => $listingId,
                'hint' => 'Listing-level aspects can be cached on active offers. Inventory PUT + safe updateOffer refresh was executed without createOffer/publishOffer.',
            ]);
            $this->logger->info('EBAY_BASIC_SPECIFICS_CHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
        } else {
            $this->logger->info('EBAY_BASIC_SPECIFICS_UNCHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
        }
        $this->logger->info('EBAY_BASIC_SPECIFICS_SINGLE_DONE', ['product_id' => $productId, 'sku' => $sku, 'changed' => $changed]);
        return ['result' => 'success', 'changed' => $changed, 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId] + $resolved;
    }


    public function basic_item_specifics_build_queue_light_eligibility(array $candidate): array
    {
        $productId = (int) ($candidate['product_id'] ?? 0);
        $sku = trim((string) ($candidate['sku'] ?? ''));
        $offerId = trim((string) ($candidate['offer_id'] ?? ''));
        $listingId = trim((string) ($candidate['listing_id'] ?? ''));
        $manufacturer = trim((string) ($candidate['manufacturer'] ?? ''));
        $mpn = trim((string) ($candidate['mpn'] ?? ''));
        if ($productId <= 0) return ['eligible' => false, 'reason' => 'invalid_product_id'];
        if ($offerId === '') return ['eligible' => false, 'reason' => 'missing_offer'];
        if ($listingId === '') return ['eligible' => false, 'reason' => 'missing_listing'];
        if ($sku === '') return ['eligible' => false, 'reason' => 'missing_sku'];
        if ($manufacturer === '' || $mpn === '') return ['eligible' => false, 'reason' => 'missing_basic_data'];
        return ['eligible' => true, 'reason' => 'ok', 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId];
    }

    public function basic_item_specifics_process_one_product_eligibility(int $productId): array
    {
        return $this->basic_item_specifics_queue_eligibility($productId);
    }

    public function basic_item_specifics_queue_eligibility(int $productId): array
    {
        if ($productId <= 0) return ['eligible' => false, 'reason' => 'invalid_product_id'];
        $product = wc_get_product($productId);
        if (!$product) return ['eligible' => false, 'reason' => 'product_not_found'];
        $settings = $this->settings();
        $sku = (string) $this->resolve_ebay_sku($product, $productId, null, $settings)['sku'];
        $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
        $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        if ($sku === '' || $offerId === '' || $listingId === '') {
            return ['eligible' => false, 'reason' => 'missing_offer_listing_sku'];
        }
        $resolved = $this->resolve_basic_item_specifics($product, $productId, $sku, $settings, []);
        $aspects = is_array($resolved['aspects'] ?? null) ? $resolved['aspects'] : [];
        $hasHersteller = !empty($aspects['Hersteller'][0] ?? '');
        $hasMpn = !empty($aspects['MPN'][0] ?? '');
        $hasHerstellernummer = !empty($aspects['Herstellernummer'][0] ?? '');
        if (!$hasHersteller || (!$hasMpn && !$hasHerstellernummer)) {
            return ['eligible' => false, 'reason' => 'missing_required_basic_specifics', 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId];
        }
        $reviewRequired = false;
        return [
            'eligible' => !$reviewRequired,
            'reason' => $reviewRequired ? 'review_required' : 'ok',
            'sku' => $sku,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'condition' => EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM,
            'has_hersteller' => $hasHersteller,
            'has_mpn' => $hasMpn,
            'has_herstellernummer' => $hasHerstellernummer,
            'review_required' => $reviewRequired,
            'critical_conflicts' => [],
        ];
    }

    private function resolve_basic_item_specifics($product, int $productId, string $sku, array $settings, array $existingAspects): array
    {
        $aspects = $this->cleanup_condition_aspects($existingAspects)['aspects'];
        unset($aspects['Kategorie'], $aspects['Stan'], $aspects['Stan opakowania']);
        $content = $this->resolve_german_content($product, $productId, $this->marketplace_id(), $settings);
        $manufacturer = $this->resolve_manufacturer_aspect_value($product, $productId, '', $settings, $content);
        $mpnResolved = $this->resolve_mpn_aspect_value($product, $productId, $sku, $content);
        $mpn = (string) ($mpnResolved['value'] ?? '');
        if ($manufacturer !== '') $aspects['Hersteller'] = [$manufacturer];
        if ($mpn !== '') {
            $aspects['MPN'] = [$mpn];
            $aspects['Herstellernummer'] = [$mpn];
            $aspects['Manufacturer Part Number'] = [$mpn];
            $aspects['OE/OEM Referenznummer'] = array_values(array_unique(array_filter([$mpn, (string) get_post_meta($productId, '_oem_number', true)])));
        }
        $country = strtoupper(trim((string) ($settings['default_country_of_origin'] ?? '')));
        if ($country !== '') $aspects['Ursprungsland'] = [$country];

        return [
            'aspects' => $aspects,
            'field_sources' => ['manufacturer' => $manufacturer !== '' ? 'meta_or_taxonomy' : 'none', 'part_number' => (string) ($mpnResolved['source'] ?? 'none')],
            'rejected_tokens' => (array) ($mpnResolved['rejected_tokens'] ?? []),
            'selected_part_number' => $mpn,
            'confidence' => (float) ($mpnResolved['confidence'] ?? 0.0),
            'skipped_weak_part_number' => (bool) ($mpnResolved['skipped_weak_part_number'] ?? false),
            'condition_description_supported' => true,
            'condition_description' => 'Gebrauchtes Originalteil. Zustand siehe Fotos. Bitte Teilenummer und Kompatibilität vor dem Kauf prüfen.',
        ];
    }

    public function clean_description_condition_single(string $productOrSku): array
    {
        $identifier = trim($productOrSku);
        if ($identifier === '') return ['result' => 'error', 'error' => 'missing_input'];
        $this->logger->info('EBAY_DESCRIPTION_CONDITION_CLEANUP_START', ['input' => $identifier]);
        $productId = ctype_digit($identifier) ? (int) $identifier : 0;
        if ($productId <= 0) {
            $found = wc_get_products(['limit' => 1, 'sku' => $identifier, 'status' => ['publish', 'draft', 'private']]);
            $productId = !empty($found[0]) ? (int) $found[0]->get_id() : 0;
        }
        if ($productId <= 0) {
            $this->logger->error('EBAY_DESCRIPTION_CONDITION_CLEANUP_FAILED', ['input' => $identifier, 'reason' => 'product_not_found']);
            return ['result' => 'error', 'error' => 'product_not_found'];
        }
        $product = wc_get_product($productId);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];
        $sku = (string) $this->resolve_ebay_sku($product, $productId, null, $this->settings())['sku'];
        $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
        $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        if ($offerId === '' || $listingId === '' || $sku === '') return ['result' => 'error', 'error' => 'missing_offer_listing_sku'];
        $inventory = $this->client->get_inventory_item($sku, ['stage' => 'description_condition_cleanup_single', 'product_id' => $productId, 'sku' => $sku]);
        if (is_wp_error($inventory)) return ['result' => 'error', 'error' => $inventory->get_error_message()];

        $description = (string) ($inventory['product']['description'] ?? '');
        $audit = $this->analyze_description_condition_conflict($description);
        $descriptionConflict = (bool) $audit['description_contains_new_like_words'] && (string) ($inventory['condition'] ?? '') === EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        if ($descriptionConflict) {
            $this->logger->warning('EBAY_DESCRIPTION_CONFLICT_DETECTED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId] + $audit);
        }

        $replace = $this->apply_description_condition_replacements($description);
        $currentCondition = (string) ($inventory['condition'] ?? '');
        $inventory['product']['description'] = (string) $replace['description'];
        $inventory['condition'] = EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        $changed = $replace['description'] !== $description || $currentCondition !== EbayConditionResolver::DEFAULT_EBAY_CONDITION_ENUM;
        if ($changed) {
            $update = $this->client->create_or_replace_inventory_item($sku, $inventory, ['stage' => 'description_condition_cleanup_single_update', 'product_id' => $productId, 'sku' => $sku]);
            if (is_wp_error($update)) {
                $this->logger->error('EBAY_DESCRIPTION_CONDITION_CLEANUP_FAILED', ['product_id' => $productId, 'sku' => $sku, 'error' => $update->get_error_message()]);
                return ['result' => 'error', 'error' => $update->get_error_message()];
            }
            $this->logger->info('EBAY_DESCRIPTION_CONDITION_CHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId, 'applied_replacements' => $replace['applied']]);
        } else {
            $this->logger->info('EBAY_DESCRIPTION_CONDITION_UNCHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId, 'applied_replacements' => []]);
        }
        $this->logger->info('EBAY_DESCRIPTION_CONDITION_CLEANUP_DONE', ['product_id' => $productId, 'sku' => $sku, 'changed' => $changed]);

        return ['result' => 'success', 'changed' => $changed, 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId, 'applied_replacements' => $replace['applied']] + $audit;
    }

    private function description_contains_condition_markers(string $description): bool
    {
        return $this->analyze_description_condition_conflict($description)['description_contains_new_like_words'];
    }

    private function analyze_description_condition_conflict(string $description): array
    {
        $plain = mb_strtolower(wp_strip_all_tags($description), 'UTF-8');
        if ($plain === '') {
            return [
                'description_contains_neu' => false,
                'description_contains_new_like_words' => false,
                'review_required' => false,
                'confidence' => 0.0,
                'description_cleanup_safe' => false,
                'matched_keywords' => [],
                'suggested_replacements' => [],
                'suggested_description_snippet' => '',
            ];
        }

        $keywords = ['neu', 'neue', 'neuer', 'neues', 'new', 'nowy', 'nowa', 'nowe', 'fabrikneu', 'brandneu'];
        $contains = (bool) preg_match('/\b(neu|neue|neuer|neues|new|nowy|nowa|nowe|fabrikneu|brandneu)\b/iu', $plain);
        $matched = [];
        foreach ($keywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/iu', $plain)) {
                $matched[] = $keyword;
            }
        }
        $reviewRequired = (bool) preg_match('/\b(neue?\s+version|neues?\s+modell)\b/iu', $plain);

        $suggested = [];
        $safeMap = [
            'NEUE ORIGINAL EUROPÄISCHE LAMPEN' => 'GEBRAUCHTE ORIGINALE EUROPÄISCHE LAMPEN',
            'Neue Originalteile' => 'Gebrauchte Originalteile',
            'NOWE ORYGINALNE' => 'GEBRAUCHTE ORIGINALE',
            'NEW ORIGINAL' => 'USED ORIGINAL',
        ];
        foreach ($safeMap as $from => $to) {
            if (mb_stripos($description, $from, 0, 'UTF-8') !== false) {
                $suggested[] = ['from' => $from, 'to' => $to];
            }
        }

        return [
            'description_contains_neu' => (bool) preg_match('/\b(neu|neue|neuer|neues)\b/iu', $plain),
            'description_contains_new_like_words' => $contains,
            'review_required' => $reviewRequired,
            'confidence' => $contains ? ($reviewRequired ? 0.55 : 0.96) : 0.0,
            'description_cleanup_safe' => $contains && !$reviewRequired,
            'matched_keywords' => $matched,
            'suggested_replacements' => $suggested,
            'suggested_description_snippet' => 'Gebrauchtes Originalteil. Zustand siehe Fotos. Funktionsfähig, sofern im Angebot angegeben. Bitte Teilenummer und Kompatibilität vor dem Kauf prüfen.',
        ];
    }

    private function apply_description_condition_replacements(string $description): array
    {
        $replacements = [
            'NEUE ORIGINAL EUROPÄISCHE LAMPEN' => 'GEBRAUCHTE ORIGINALE EUROPÄISCHE LAMPEN',
            'Neue Originalteile' => 'Gebrauchte Originalteile',
            'NOWE ORYGINALNE' => 'GEBRAUCHTE ORIGINALE',
            'NEW ORIGINAL' => 'USED ORIGINAL',
        ];
        $updated = $description;
        $applied = [];
        foreach ($replacements as $from => $to) {
            $next = str_replace($from, $to, $updated);
            if ($next !== $updated) {
                $applied[] = ['from' => $from, 'to' => $to];
                $updated = $next;
            }
        }

        return ['description' => $updated, 'applied' => $applied];
    }

    private function apply_safe_required_aspect_repairs(array $aspects, array $requiredAspects, $product, int $product_id, string $categoryId, array $settings, array $content = []): array
    {
        if ($this->marketplace_id() !== 'EBAY_DE' || $requiredAspects === []) {
            return $aspects;
        }

        $sourceText = $this->aspect_repair_source_text($product, $content);
        $categoryText = strtolower(trim($categoryId . ' ' . $sourceText));
        $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id, $categoryId, $settings, $content);
        foreach ($requiredAspects as $requiredAspect) {
            $aspect = (string) $requiredAspect;
            if ($aspect === '' || !empty($aspects[$aspect])) {
                continue;
            }
            $normalized = $this->normalize_aspect_alias_name($aspect);

            if (in_array($normalized, ['hersteller', 'marke'], true) && $manufacturer !== '') {
                if ($normalized === 'hersteller' || $this->looks_like_ac_condenser_or_refrigerant_case($categoryText)) {
                    $aspects[$aspect] = [$manufacturer];
                }
                continue;
            }

            if ($this->looks_like_spare_wheel_or_rim_case($categoryText)) {
                if (in_array($normalized, ['felgenbreite', 'rimwidth'], true)) {
                    $width = $this->infer_rim_width($sourceText);
                    if ($width !== '') {
                        $aspects[$aspect] = [$width];
                    }
                    continue;
                }
                if (in_array($normalized, ['zollgrosse', 'zollgroesse', 'zoll', 'durchmesser'], true)) {
                    $diameter = $this->infer_rim_diameter($sourceText);
                    if ($diameter !== '') {
                        $aspects[$aspect] = [$diameter];
                    }
                    continue;
                }
            }
        }

        return $aspects;
    }

    private function aspect_repair_source_text($product, array $content = []): string
    {
        $parts = [];
        foreach (['title', 'description'] as $key) {
            if (!empty($content[$key])) {
                $parts[] = (string) $content[$key];
            }
        }
        foreach (['get_name', 'get_description', 'get_short_description'] as $method) {
            if (is_object($product) && method_exists($product, $method)) {
                $parts[] = (string) $product->{$method}();
            }
        }
        return wp_strip_all_tags(html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function looks_like_spare_wheel_or_rim_case(string $text): bool
    {
        $text = strtolower(remove_accents($text));
        return str_contains($text, 'ersatzrad') || str_contains($text, 'notrad') || str_contains($text, 'reserverad') || str_contains($text, 'felge') || str_contains($text, 'felgen') || str_contains($text, 'kolo zapasowe') || str_contains($text, 'dojazdowe');
    }

    private function looks_like_ac_condenser_or_refrigerant_case(string $text): bool
    {
        $text = strtolower(remove_accents($text));
        return str_contains($text, 'kondensator') || str_contains($text, 'klimakondensator') || str_contains($text, 'klimaanlage') || str_contains($text, 'kaeltemittel') || str_contains($text, 'kaltemittel') || str_contains($text, 'chlodnica klimatyzacji') || str_contains($text, 'osuszacz klimatyzacji');
    }

    private function infer_rim_width(string $text): string
    {
        $plain = strtoupper(wp_strip_all_tags($text));
        if (preg_match('/(?<!\d)([3-9](?:[.,]5)?)\s*J\b/u', $plain, $matches)) {
            return str_replace(',', '.', (string) $matches[1]) . 'J';
        }
        if (preg_match('/\bFELGENBREITE\s*[:=]?\s*([3-9](?:[.,]5)?)/iu', $plain, $matches)) {
            return str_replace(',', '.', (string) $matches[1]) . 'J';
        }
        return '';
    }

    private function infer_rim_diameter(string $text): string
    {
        $plain = strtoupper(wp_strip_all_tags($text));
        foreach ([
            '/\bR\s*(1[0-9]|2[0-4])\b/u',
            '/\b(1[0-9]|2[0-4])\s*(?:ZOLL|CAL|\")\b/u',
            '/\b(?:ZOLLGRO(?:S|SS|ß)E|FELGENDURCHMESSER)\s*[:=]?\s*(1[0-9]|2[0-4])\b/iu',
            '/\b[3-9](?:[.,]5)?J\s*[Xx]\s*(1[0-9]|2[0-4])\b/u',
        ] as $pattern) {
            if (preg_match($pattern, $plain, $matches)) {
                return (string) $matches[1];
            }
        }
        return '';
    }

    private function resolve_mpn_aspect_value($product, int $product_id, string $sku, array $content = []): array
    {
        foreach (['_mpn', 'mpn', '_part_number', 'part_number', '_oem_number', 'oem_number', '_oe_number', '_catalog_number', 'catalog_number'] as $metaKey) {
            $value = $this->normalize_part_number_value((string) get_post_meta($product_id, $metaKey, true));
            if ($value !== '') {
                return ['value' => $value, 'source' => 'meta', 'rejected_tokens' => [], 'confidence' => 0.98, 'skipped_weak_part_number' => false];
            }
        }

        foreach (['MPN', 'Herstellernummer', 'Hersteller Teilenummer', 'OE/OEM Referenznummer(n)', 'Referenznummer(n) OE', 'Referenznummer(n) OEM', 'Teilenummer', 'Artikelnummer', 'OEM', 'OE', 'Numer części', 'Numer czesci', 'Numer katalogowy', 'Numer OE', 'Part Number', 'Manufacturer Part Number'] as $attributeName) {
            if (!method_exists($product, 'get_attribute')) {
                continue;
            }
            $value = $this->normalize_part_number_value((string) $product->get_attribute($attributeName));
            if ($value !== '') {
                return ['value' => $value, 'source' => 'meta', 'rejected_tokens' => [], 'confidence' => 0.95, 'skipped_weak_part_number' => false];
            }
        }

        $rejectedTokens = [];
        $texts = [];
        if (method_exists($product, 'get_name')) {
            $texts[] = (string) $product->get_name();
        }
        if (method_exists($product, 'get_description')) {
            $texts[] = (string) $product->get_description();
        }
        if (method_exists($product, 'get_short_description')) {
            $texts[] = (string) $product->get_short_description();
        }
        foreach (['title', 'description'] as $contentKey) {
            if (!empty($content[$contentKey])) {
                $texts[] = (string) $content[$contentKey];
            }
        }
        foreach ($texts as $text) {
            $value = $this->extract_part_number_from_text($text, $rejectedTokens);
            if ($value !== '') {
                return ['value' => $value, 'source' => 'title_parse', 'rejected_tokens' => array_values(array_unique($rejectedTokens)), 'confidence' => 0.72, 'skipped_weak_part_number' => !empty($rejectedTokens)];
            }
        }

        if (method_exists($product, 'get_sku')) {
            $wooSku = $this->normalize_part_number_value((string) $product->get_sku());
            if ($wooSku !== '' && !$this->is_generated_ebay_sku($wooSku)) {
                return ['value' => $wooSku, 'source' => 'inventory_cache', 'rejected_tokens' => array_values(array_unique($rejectedTokens)), 'confidence' => 0.86, 'skipped_weak_part_number' => !empty($rejectedTokens)];
            }
        }

        return ['value' => '', 'source' => 'none', 'rejected_tokens' => array_values(array_unique($rejectedTokens)), 'confidence' => 0.0, 'skipped_weak_part_number' => !empty($rejectedTokens)];
    }

    private function apply_part_number_aspect_aliases(array $aspects, array $requiredAspects, string $resolvedPartNumber): array
    {
        if ($this->marketplace_id() !== 'EBAY_DE') {
            return $aspects;
        }

        $partNumber = $this->normalize_part_number_value($resolvedPartNumber);
        foreach ($this->part_number_aspect_aliases() as $alias) {
            if ($partNumber === '' && !empty($aspects[$alias][0])) {
                $partNumber = $this->normalize_part_number_value((string) $aspects[$alias][0]);
            }
        }

        if ($partNumber === '') {
            return $aspects;
        }

        foreach ($requiredAspects as $requiredAspect) {
            if ($this->is_part_number_aspect_alias((string) $requiredAspect) && empty($aspects[$requiredAspect])) {
                $aspects[$requiredAspect] = [$partNumber];
            }
        }

        if (empty($aspects['MPN'])) {
            $aspects['MPN'] = [$partNumber];
        }

        return $aspects;
    }

    private function part_number_aspect_aliases(): array
    {
        return ['MPN', 'Herstellernummer', 'Hersteller Teilenummer', 'OE/OEM Referenznummer(n)', 'Referenznummer(n) OE', 'Referenznummer(n) OEM', 'Teilenummer', 'Artikelnummer', 'OEM', 'OE', 'Numer części', 'Numer czesci', 'Numer katalogowy', 'Numer OE', 'Part Number', 'Manufacturer Part Number'];
    }

    private function is_part_number_aspect_alias(string $name): bool
    {
        $normalized = $this->normalize_aspect_alias_name($name);
        foreach ($this->part_number_aspect_aliases() as $alias) {
            if ($normalized === $this->normalize_aspect_alias_name($alias)) {
                return true;
            }
        }
        return false;
    }

    private function normalize_aspect_alias_name(string $name): string
    {
        $name = function_exists('remove_accents') ? remove_accents($name) : (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    private function normalize_part_number_value(string $value): string
    {
        $value = strtoupper(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $value = preg_replace('/\s+/', '', $value) ?: '';
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?: '';
        $genericValues = ['ORYGINALNE', 'ORYGINALNY', 'UŻYWANY', 'UZYWANY', 'BRAK', 'NA', 'NIEDOTYCZY', 'UNIVERSAL', 'UNIWERSALNY'];
        if ($value === '' || in_array($value, $genericValues, true) || $this->is_generated_ebay_sku($value)) {
            return '';
        }
        return $value;
    }

    private function extract_part_number_from_text(string $text, array &$rejectedTokens = []): string
    {
        $text = strtoupper(wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (preg_match_all('/\b[A-Z0-9\-]{3,20}\b/u', $text, $matches)) {
            foreach ((array) ($matches[0] ?? []) as $match) {
                $value = $this->normalize_part_number_value((string) $match);
                if ($value === '') {
                    continue;
                }
                if ($this->is_weak_title_parse_part_number($value)) {
                    $rejectedTokens[] = $value;
                    continue;
                }
                if (preg_match('/^(?=.*[A-Z])(?=.*[0-9])[A-Z0-9]{5,20}$/', $value)) {
                    return $value;
                }
            }
        }
        return '';
    }

    private function is_weak_title_parse_part_number(string $value): bool
    {
        if (strlen($value) < 5) return true;
        if (!preg_match('/[A-Z]/', $value) || !preg_match('/\d/', $value)) return true;
        if (preg_match('/^(CYR|TCB|OCK|DNF|DFH|DXR|CDA|CCZ|BLS)$/', $value)) return true;
        if (preg_match('/^(8W|8P|8R|B6|B7|B8|W177|X204|F3|80A)$/', $value)) return true;
        if (preg_match('/^(19\d{2}|20[0-2]\d)$/', $value)) return true;
        if (preg_match('/^\d{2,4}PS$/', $value)) return true;
        if (preg_match('/^\d[.,]\d$/', $value)) return true;
        return false;
    }

    private function is_generated_ebay_sku(string $value): bool
    {
        return (bool) preg_match('/^GPSW[\-_]?[0-9]+(?:[\-_]?[0-9]+)?$/i', trim($value));
    }

    private function resolve_manufacturer_aspect_value($product, int $product_id, string $categoryId, array $settings, array $content = []): string
    {
        foreach (['Producent', 'Marka', 'Manufacturer'] as $attributeName) {
            if (!method_exists($product, 'get_attribute')) {
                continue;
            }

            $value = $this->normalize_manufacturer_value((string) $product->get_attribute($attributeName));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['pa_producent', 'pa_marka'] as $taxonomy) {
            $value = $this->manufacturer_from_taxonomy($product_id, $taxonomy);
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand'] as $taxonomy) {
            $value = $this->manufacturer_from_taxonomy($product_id, $taxonomy);
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['_manufacturer', '_brand'] as $metaKey) {
            $value = $this->normalize_manufacturer_value((string) get_post_meta($product_id, $metaKey, true));
            if ($value !== '') {
                return $value;
            }
        }

        $title = trim((string) ($content['title'] ?? ''));
        if (method_exists($product, 'get_name')) {
            $title = trim($title . ' ' . (string) $product->get_name());
        }
        $value = $this->detect_known_vehicle_brand($title);
        if ($value !== '') {
            return $value;
        }

        $description = trim((string) ($content['description'] ?? ''));
        if ($description === '' && method_exists($product, 'get_description')) {
            $description = (string) $product->get_description();
        }
        $value = $this->detect_manufacturer_from_labeled_description($description);
        if ($value !== '') {
            return $value;
        }

        $fallbacks = $this->parse_category_aspect_fallbacks((string) ($settings['category_aspect_fallbacks'] ?? ''));
        if ($categoryId !== '' && !empty($fallbacks[$categoryId]['Hersteller'][0])) {
            return $this->normalize_manufacturer_value((string) $fallbacks[$categoryId]['Hersteller'][0]);
        }

        return $this->normalize_manufacturer_value((string) ($settings['default_hersteller_fallback'] ?? ''));
    }

    private function manufacturer_from_taxonomy(int $product_id, string $taxonomy): string
    {
        $terms = taxonomy_exists($taxonomy) ? wp_get_post_terms($product_id, $taxonomy) : [];
        if (is_wp_error($terms) || empty($terms[0]->name)) {
            return '';
        }

        return $this->normalize_manufacturer_value((string) $terms[0]->name);
    }

    private function detect_manufacturer_from_labeled_description(string $description): string
    {
        $description = preg_replace('/<\s*(br|\/p|\/li)\s*\/?>/iu', "\n", $description) ?: $description;
        $description = wp_strip_all_tags($description);
        if (!preg_match('/(?:^|\R)\s*(?:Marka|Marke|Hersteller|Producent)\s*:\s*([^\r\n<,;]+)/iu', $description, $matches)) {
            return '';
        }

        return $this->normalize_manufacturer_value((string) $matches[1]);
    }

    private function detect_known_vehicle_brand(string $text): string
    {
        $text = wp_strip_all_tags($text);
        foreach ($this->known_vehicle_brand_aliases() as $alias => $canonical) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text)) {
                return $canonical;
            }
        }

        return '';
    }

    private function normalize_manufacturer_value(string $value): string
    {
        $value = trim(wp_strip_all_tags($value));
        $value = preg_replace('/\s+/', ' ', $value) ?: '';
        $value = trim($value, " \\t\\n\\r\\0\\x0B:,;|/-");
        if ($value === '') {
            return '';
        }

        $detected = $this->detect_known_vehicle_brand($value);
        return $detected !== '' ? $detected : $value;
    }

    private function known_vehicle_brand_aliases(): array
    {
        return [
            'Mercedes-Benz' => 'Mercedes-Benz',
            'Land Rover' => 'Land Rover',
            'Alfa Romeo' => 'Alfa Romeo',
            'Volkswagen' => 'Volkswagen',
            'Mitsubishi' => 'Mitsubishi',
            'Chevrolet' => 'Chevrolet',
            'Citroen' => 'Citroen',
            'Hyundai' => 'Hyundai',
            'Porsche' => 'Porsche',
            'Peugeot' => 'Peugeot',
            'Renault' => 'Renault',
            'Mercedes' => 'Mercedes-Benz',
            'Toyota' => 'Toyota',
            'Nissan' => 'Nissan',
            'Subaru' => 'Subaru',
            'Suzuki' => 'Suzuki',
            'Jaguar' => 'Jaguar',
            'Skoda' => 'Skoda',
            'Honda' => 'Honda',
            'Volvo' => 'Volvo',
            'Mazda' => 'Mazda',
            'Lexus' => 'Lexus',
            'Opel' => 'Opel',
            'Seat' => 'SEAT',
            'Audi' => 'Audi',
            'Fiat' => 'Fiat',
            'Ford' => 'Ford',
            'Jeep' => 'Jeep',
            'Mini' => 'Mini',
            'BMW' => 'BMW',
            'VW' => 'Volkswagen',
            'Kia' => 'Kia',
        ];
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

        $productOverrides = $this->parse_product_category_overrides((string) ($settings['product_category_overrides'] ?? ''));
        if (isset($productOverrides[$product_id]) && $productOverrides[$product_id] !== '') {
            return $productOverrides[$product_id];
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

    private function parse_product_category_overrides(string $raw): array
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

            $productId = absint($parts[0]);
            $categoryId = trim((string) $parts[1]);
            if ($productId > 0 && $categoryId !== '') {
                $overrides[$productId] = $categoryId;
            }
        }

        return $overrides;
    }

    private function static_category_name(string $categoryId): string
    {
        if ($categoryId === '179847') {
            return 'Kabel, Kabelbäume & Steckverbinder';
        }
        if ($categoryId === '33615') {
            return 'Motoren';
        }

        return '';
    }

    private function static_category_path(string $categoryId): string
    {
        if ($categoryId === '179847') {
            return 'Auto & Motorrad: Teile > Autoteile & Zubehör > Kabel, Kabelbäume & Steckverbinder';
        }
        if ($categoryId === '33615') {
            return 'Auto & Motorrad: Teile > Autoteile & Zubehör > Motoren & Motorenteile > Motoren';
        }

        return '';
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
        if ($this->is_account_restriction_error($error)) {
            update_option('wei_ebay_global_status', 'blocked_by_ebay_account_restriction', false);
            update_option('wei_ebay_account_restriction_status', 'detected', false);
            update_post_meta($product_id, '_wei_ebay_export_status', $stage === 'publishOffer' ? 'publish_blocked_account' : 'export_error');
            update_post_meta($product_id, '_wei_ebay_last_sync_status', 'error');
            update_post_meta($product_id, '_wei_ebay_last_sync_error', $message);
        }

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

    private function log_shipping_policy_resolution(int $productId, string $sku, string $marketplaceId, array $resolution): void
    {
        $context = [
            'product_id' => $productId,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
            'shipping_group' => (string) ($resolution['group'] ?? ''),
            'fulfillment_policy_id' => (string) ($resolution['policy_id'] ?? ''),
            'source' => (string) ($resolution['source'] ?? ''),
            'rate_eur' => (int) ($resolution['rate_eur'] ?? 0),
            'matched_terms' => $resolution['matched_terms'] ?? [],
            'missing_terms' => $resolution['missing_terms'] ?? [],
        ];
        $this->logger->info('EBAY_SHIPPING_POLICY_RESOLVED', $context);
        if (!empty($resolution['default_used'])) {
            $this->logger->info('EBAY_SHIPPING_POLICY_DEFAULT_USED', $context);
        }
        if (!empty($resolution['missing_terms'])) {
            $this->logger->warning('EBAY_SHIPPING_POLICY_MAPPING_MISSING', $context);
        }
    }

    private function log_shipping_policy_change_state(string $offerId, int $productId, string $sku, string $marketplaceId, array $resolution): void
    {
        if ($offerId === '') {
            return;
        }

        $desiredPolicyId = (string) ($resolution['policy_id'] ?? '');
        $currentPolicyId = '';
        $offer = $this->client->get_offer($offerId, [
            'stage' => 'shippingPolicyGetOfferBeforeUpdate',
            'product_id' => $productId,
            'sku' => $sku,
            'offer_id' => $offerId,
            'marketplace_id' => $marketplaceId,
        ]);
        if (!is_wp_error($offer) && is_array($offer)) {
            $currentPolicyId = (string) ($offer['listingPolicies']['fulfillmentPolicyId'] ?? '');
        }

        $context = [
            'product_id' => $productId,
            'sku' => $sku,
            'offer_id' => $offerId,
            'marketplace_id' => $marketplaceId,
            'shipping_group' => (string) ($resolution['group'] ?? ''),
            'current_fulfillment_policy_id' => $currentPolicyId,
            'desired_fulfillment_policy_id' => $desiredPolicyId,
            'source' => (string) ($resolution['source'] ?? ''),
        ];
        if ($currentPolicyId !== '' && $currentPolicyId === $desiredPolicyId) {
            $this->logger->info('EBAY_SHIPPING_POLICY_UNCHANGED', $context);
            return;
        }
        $this->logger->info('EBAY_SHIPPING_POLICY_CHANGED', $context);
    }

    private function validate_selected_policies(array $settings, array $fulfillmentPolicyIds = []): array
    {
        $cached = is_array($settings['wei_cached_policies'] ?? null) ? $settings['wei_cached_policies'] : [];
        $fulfillmentPolicyIds = array_values(array_unique(array_filter(array_map(static fn($id): string => trim((string) $id), $fulfillmentPolicyIds))));
        if ($fulfillmentPolicyIds === []) {
            $fulfillmentPolicyIds = [
                EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_DEFAULT_30_EUR, $settings),
                EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_PARCEL_50_EUR, $settings),
                EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_PALLET_100_EUR, $settings),
            ];
        }
        $required = [
            'paymentPolicyId' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'returnPolicyId' => (string) ($settings['ebay_return_policy_id'] ?? ''),
        ];
        foreach ($fulfillmentPolicyIds as $idx => $policyId) {
            $required['fulfillmentPolicyId_' . ($idx + 1)] = $policyId;
        }
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

            $policySetKey = str_starts_with($field, 'fulfillmentPolicyId') ? 'fulfillmentPolicyId' : $field;
            if (!$this->policy_id_exists($policySets[$policySetKey] ?? [], $id)) {
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
        if (!isset($settings['product_category_overrides'])) {
            $settings['product_category_overrides'] = '';
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
            $settings['use_woo_sku_for_ebay'] = 0;
        }
        if (!isset($settings['ebay_sku_prefix'])) {
            $settings['ebay_sku_prefix'] = 'GPSW';
        }
        $settings['write_generated_sku_to_woo'] = 0;
        if (!isset($settings['stock_sync_mode'])) {
            $settings['stock_sync_mode'] = 'set_zero';
        }
        if (!isset($settings['ebay_stock_sync_mode'])) {
            $settings['ebay_stock_sync_mode'] = 'max_one';
        }
        if (!isset($settings['ebay_order_stock_update_mode'])) {
            $settings['ebay_order_stock_update_mode'] = $settings['stock_sync_mode'];
        }
        if (!isset($settings['auto_publish_enabled'])) {
            $settings['auto_publish_enabled'] = 0;
        }
        if (!isset($settings['translation_provider'])) {
            $settings['translation_provider'] = 'disabled';
        } elseif ($settings['translation_provider'] === 'google') {
            $settings['translation_provider'] = 'google_cloud_translate';
        }
        if (!isset($settings['translation_api_key'])) {
            $settings['translation_api_key'] = '';
        }
        if (!isset($settings['auto_generate_german_content_preflight'])) {
            $settings['auto_generate_german_content_preflight'] = 1;
        }
        if (!isset($settings['verbose_debug'])) {
            $settings['verbose_debug'] = 0;
        }
        if (!isset($settings['auto_category_confidence_threshold'])) {
            $settings['auto_category_confidence_threshold'] = CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD;
        }
        if (!isset($settings['regenerate_german_content_on_hash_change'])) {
            $settings['regenerate_german_content_on_hash_change'] = 0;
        }
        if (!isset($settings['default_item_condition'])) {
            $settings['default_item_condition'] = EbayConditionResolver::DEFAULT_ITEM_CONDITION;
        }
        if (!isset($settings['ebay_default_markup_percent'])) {
            $settings['ebay_default_markup_percent'] = 25;
        }
        if (!isset($settings['ebay_special_category_markup_percent'])) {
            $settings['ebay_special_category_markup_percent'] = 30;
        }
        if (!isset($settings['nbp_rate_cache_ttl_hours'])) {
            $settings['nbp_rate_cache_ttl_hours'] = 12;
        }
        $settings = $this->with_shipping_policy_defaults($settings);
        return $settings;
    }

    private function with_shipping_policy_defaults(array $settings): array
    {
        $legacyFulfillmentId = trim((string) ($settings['ebay_fulfillment_policy_id'] ?? ''));
        if (empty($settings['fulfillment_policy_id_30_eur'])) {
            $settings['fulfillment_policy_id_30_eur'] = $legacyFulfillmentId !== '' ? $legacyFulfillmentId : EbayShippingPolicyResolver::POLICY_30_EUR;
        }
        if (empty($settings['ebay_fulfillment_policy_id'])) {
            $settings['ebay_fulfillment_policy_id'] = (string) $settings['fulfillment_policy_id_30_eur'];
        }
        if (empty($settings['fulfillment_policy_id_50_eur'])) {
            $settings['fulfillment_policy_id_50_eur'] = EbayShippingPolicyResolver::POLICY_50_EUR;
        }
        if (empty($settings['fulfillment_policy_id_100_eur'])) {
            $settings['fulfillment_policy_id_100_eur'] = EbayShippingPolicyResolver::POLICY_100_EUR;
        }
        if (!isset($settings['shipping_category_ids_50_eur'])) {
            $settings['shipping_category_ids_50_eur'] = '';
        }
        if (!isset($settings['shipping_category_ids_100_eur'])) {
            $settings['shipping_category_ids_100_eur'] = '';
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
        $settings = $this->settings();
        $skuResolution = $this->resolve_ebay_sku($product, $product_id, $variation_id, $settings);
        $sku = $skuResolution['sku'];
        $map = $this->repo->find_by_sku($sku);
        if (!$map) return ['result' => 'skipped', 'reason' => 'mapping_not_found', 'sku_resolution' => $skuResolution];
        if (empty($map['remote_offer_id'])) return ['result' => 'skipped', 'reason' => 'offer_id_missing', 'sku_resolution' => $skuResolution];

        $metaProductId = $variation_id ?: $product_id;
        $wooStock = max(0, (int) $product->get_stock_quantity());
        $wooStockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
        if ((string) ($settings['ebay_stock_sync_mode'] ?? 'max_one') === 'set_zero_only' && $wooStock > 0 && $wooStockStatus !== 'outofstock') {
            update_post_meta($metaProductId, '_wei_ebay_stock_sync_pending', '0');
            return ['result' => 'skipped', 'reason' => 'set_zero_only_positive_stock', 'quantity' => null, 'sku_resolution' => $skuResolution];
        }
        $quantity = $this->compute_ebay_stock_quantity($product, $settings);
        $hash = hash('sha256', wp_json_encode(['offer_id' => (string) $map['remote_offer_id'], 'quantity' => $quantity]) ?: '');
        $lastHash = (string) get_post_meta($metaProductId, '_wei_ebay_last_stock_sync_hash', true);
        $lastQty = get_post_meta($metaProductId, '_wei_ebay_last_stock_sync_qty', true);
        if ($lastHash === $hash || ((string) $lastQty !== '' && (int) $lastQty === $quantity)) {
            update_post_meta($metaProductId, '_wei_ebay_stock_sync_pending', '0');
            return ['result' => 'skipped', 'reason' => 'unchanged', 'quantity' => $quantity, 'sku_resolution' => $skuResolution];
        }

        $res = $this->client->bulk_update_price_quantity([[
            'offerId' => $map['remote_offer_id'],
            'shipToLocationAvailability' => ['quantity' => $quantity],
        ]]);

        if (is_wp_error($res)) {
            if ($this->is_account_restriction_error($res)) {
                update_option('wei_ebay_account_restriction_status', 'stock_update_failed', false);
                $this->logger->error('account_restriction_stock_update_failed', ['product_id' => $product_id, 'sku' => $sku, 'error' => $res->get_error_data(), 'wrote_allegro' => false]);
            }
            return ['result' => 'error', 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data(), 'quantity' => $quantity];
        }

        update_post_meta($metaProductId, '_wei_ebay_last_stock_sync_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($metaProductId, '_wei_ebay_last_stock_sync_qty', (string) $quantity);
        update_post_meta($metaProductId, '_wei_ebay_last_stock_sync_hash', $hash);
        update_post_meta($metaProductId, '_wei_ebay_last_synced_quantity', (string) $quantity);
        delete_post_meta($metaProductId, '_wei_ebay_last_stock_sync_error');
        $this->repo->upsert(array_merge($map, ['last_sync_at' => gmdate('Y-m-d H:i:s')]));
        return ['result' => 'success', 'quantity' => $quantity, 'sku' => $sku, 'mode' => (string) ($settings['ebay_stock_sync_mode'] ?? 'max_one')];
    }


    private function compute_ebay_stock_quantity($product, array $settings): int
    {
        $stock = max(0, (int) $product->get_stock_quantity());
        $stockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
        if ($stockStatus === 'outofstock') {
            $stock = 0;
        }
        $mode = (string) ($settings['ebay_stock_sync_mode'] ?? 'max_one');
        if ($mode === 'set_zero_only') {
            return 0;
        }
        if ($mode === 'exact_stock') {
            return $stock;
        }
        return $stock > 0 ? 1 : 0;
    }

    private function is_account_restriction_error(\WP_Error $error): bool
    {
        $haystack = strtolower($error->get_error_message() . ' ' . (wp_json_encode($error->get_error_data()) ?: ''));
        return str_contains($haystack, 'german tax rules') || str_contains($haystack, 'violation of our policy');
    }

    public function import_orders(array $query = []): array
    {
        return $this->client->get_orders($query);
    }
}
