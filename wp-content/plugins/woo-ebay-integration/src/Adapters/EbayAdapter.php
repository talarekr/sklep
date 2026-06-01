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
use WEI\Services\EbayGermanContentTranslator;
use WEI\Services\CategoryMappingSafety;
use WEI\Services\EbayCategorySuggestionReportService;
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
        $rows = $this->categoryRepo->list_manual_mapping_categories($marketplaceId, ['limit' => 200]);
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
            'invalid_current_mappings' => 0,
            'non_leaf_current_mappings' => 0,
            'invalid_category_examples' => [],
            'threshold' => CategoryMappingSafety::threshold($settings),
        ];
        foreach ($rows as $row) {
            $productCount = max(0, (int) ($row['product_count'] ?? 0));
            $summary['total_products_counted'] += $productCount;
            $evaluation = $this->evaluate_category_mapping_row($row, $settings);
            $currentValidation = $this->known_category_validation((string) ($row['ebay_category_id'] ?? ''), (int) ($row['term_id'] ?? 0));
            if (!empty($currentValidation) && (($currentValidation['validation_status'] ?? '') === 'invalid_ebay_category_id' || ($currentValidation['validation_status'] ?? '') === 'non_leaf_ebay_category_id' || empty($currentValidation['valid']) || empty($currentValidation['leaf']))) {
                $summary['ready'] = false;
                if (!empty($currentValidation['valid']) && empty($currentValidation['leaf'])) {
                    $summary['non_leaf_current_mappings'] += $productCount;
                } else {
                    $summary['invalid_current_mappings'] += $productCount;
                }
                $summary['products_needs_category_review'] += $productCount;
                if (count($summary['invalid_category_examples']) < 10) {
                    $summary['invalid_category_examples'][] = [
                        'woo_term_id' => (int) ($row['term_id'] ?? 0),
                        'woo_category_path' => (string) ($row['woo_category_path'] ?? ''),
                        'current_ebay_category_id' => (string) ($row['ebay_category_id'] ?? ''),
                        'validation_status' => (string) ($currentValidation['validation_status'] ?? 'invalid_ebay_category_id'),
                    ];
                }
                continue;
            }
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


    private function known_category_validation(string $categoryId, int $wooTermId = 0): array
    {
        $categoryId = trim($categoryId);
        $validation = get_option(EbayCategorySuggestionReportService::VALIDATION_OPTION, []);
        if (!is_array($validation)) {
            return [];
        }
        $byTerm = is_array($validation['by_woo_term_id'] ?? null) ? $validation['by_woo_term_id'] : [];
        if ($wooTermId > 0 && is_array($byTerm[(string) $wooTermId] ?? null)) {
            return $byTerm[(string) $wooTermId];
        }
        $byCategory = is_array($validation['by_category_id'] ?? null) ? $validation['by_category_id'] : [];
        if ($categoryId !== '' && is_array($byCategory[$categoryId] ?? null)) {
            return $byCategory[$categoryId];
        }
        return [];
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

        $descriptionResolution = $this->resolve_inventory_item_description($product, $product_id, $content, $aspects, $category, $settings, $marketplaceId);
        $listingDescriptionResolution = $this->resolve_offer_listing_description($product, $product_id, $content, $aspects, $category, $settings, $marketplaceId);
        $itemPayload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => $conditionResolution['condition'],
            'product' => [
                'title' => $content['title'],
                'description' => (string) ($descriptionResolution['description'] ?? ''),
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
        if ((string) ($listingDescriptionResolution['description'] ?? '') !== '') {
            $offerPayload['listingDescription'] = (string) $listingDescriptionResolution['description'];
        }
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


    public function refresh_listing_state(int $limit = 100): array
    {
        global $wpdb;

        $limit = max(1, min(500, $limit));
        $marketplaceId = $this->marketplace_id();
        $now = gmdate('Y-m-d H:i:s');
        $summary = [
            'refreshed_at' => $now,
            'marketplace_id' => $marketplaceId,
            'checked_products' => 0,
            'historical_published_count' => 0,
            'current_active_listing_count' => 0,
            'current_offer_count' => 0,
            'offers_without_active_listing_count' => 0,
            'needs_reexport_count' => 0,
            'ended_listing_count' => 0,
            'missing_remote_offer_count' => 0,
            'errors' => 0,
            'sample' => [],
        ];

        $remoteOffers = $this->client->get_offers([
            'marketplace_id' => $marketplaceId,
            'limit' => min(200, $limit),
            'offset' => 0,
        ], [
            'stage' => 'refresh_listing_state_get_offers',
            'marketplace_id' => $marketplaceId,
        ]);
        if (!is_wp_error($remoteOffers) && is_array($remoteOffers)) {
            $summary['current_offer_count'] = (int) ($remoteOffers['total'] ?? count((array) ($remoteOffers['offers'] ?? [])));
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS product_id,
                    offer.meta_value AS offer_id,
                    listing.meta_value AS listing_id,
                    item.meta_value AS item_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} offer
                ON offer.post_id = p.ID AND offer.meta_key = '_wei_ebay_offer_id' AND offer.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} listing
                ON listing.post_id = p.ID AND listing.meta_key = '_wei_ebay_listing_id' AND listing.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} item
                ON item.post_id = p.ID AND item.meta_key = '_wei_ebay_item_id' AND item.meta_value <> ''
             WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND (offer.post_id IS NOT NULL OR listing.post_id IS NOT NULL OR item.post_id IS NOT NULL)
             GROUP BY p.ID
             ORDER BY p.ID ASC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $offerId = trim((string) ($row['offer_id'] ?? ''));
            $listingId = trim((string) ($row['listing_id'] ?? ''));
            if ($listingId === '') {
                $listingId = trim((string) ($row['item_id'] ?? ''));
            }

            $summary['checked_products']++;
            $summary['historical_published_count']++;
            $offerExists = false;
            $listingActive = false;
            $state = 'unknown';
            $error = '';

            if ($offerId !== '') {
                $offer = $this->client->get_offer($offerId, [
                    'stage' => 'refresh_listing_state_get_offer',
                    'product_id' => $productId,
                    'offer_id' => $offerId,
                    'marketplace_id' => $marketplaceId,
                ]);
                if (is_wp_error($offer)) {
                    $error = $offer->get_error_message();
                } else {
                    $offerExists = true;
                    $state = 'offer_exists';
                    if ($listingId === '' && is_array($offer)) {
                        $listingId = trim((string) ($offer['listingId'] ?? $offer['listing']['listingId'] ?? ''));
                    }
                }
            }

            if ($listingId !== '') {
                $item = $this->client->browse_get_item_by_legacy_id($listingId, $marketplaceId);
                if (!is_wp_error($item) && is_array($item)) {
                    $listingActive = true;
                    $state = 'active';
                } elseif ($error === '') {
                    $error = is_wp_error($item) ? $item->get_error_message() : '';
                }
            }

            if ($listingActive) {
                $summary['current_active_listing_count']++;
                update_post_meta($productId, '_wei_ebay_current_listing_state', 'active');
                update_post_meta($productId, '_wei_ebay_listing_status', 'published');
                update_post_meta($productId, '_wei_ebay_export_status', 'published');
                update_post_meta($productId, '_wei_ebay_last_sync_status', 'published');
                delete_post_meta($productId, '_wei_ebay_last_sync_error');
            } else {
                if ($offerExists) {
                    $summary['offers_without_active_listing_count']++;
                    $state = 'offer_without_active_listing';
                } elseif ($offerId !== '') {
                    $summary['missing_remote_offer_count']++;
                    $state = 'remote_offer_missing';
                } else {
                    $state = 'listing_not_active';
                }
                $summary['ended_listing_count']++;
                $summary['needs_reexport_count']++;
                update_post_meta($productId, '_wei_ebay_current_listing_state', $state);
                update_post_meta($productId, '_wei_ebay_listing_status', 'ended');
                update_post_meta($productId, '_wei_ebay_export_status', 'needs_reexport');
                update_post_meta($productId, '_wei_ebay_last_sync_status', 'needs_reexport');
                update_post_meta($productId, '_wei_ebay_last_sync_error', $error !== '' ? $error : 'Listing is not active on eBay; recreate offer, update offer, or publish existing offer in the next publish run.');
            }

            update_post_meta($productId, '_wei_ebay_listing_state_checked_at', $now);
            if (count($summary['sample']) < 25) {
                $summary['sample'][] = [
                    'product_id' => $productId,
                    'offer_id' => $offerId,
                    'listing_id' => $listingId,
                    'state' => $state,
                    'offer_exists' => $offerExists,
                    'listing_active' => $listingActive,
                ];
            }
        }

        $this->logger->info('EBAY_LISTING_STATE_REFRESH_DONE', $summary);
        return $summary;
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
            $content = !empty($settings['lightweight'])
                ? $this->resolve_german_content_lightweight($product, $product_id, $marketplaceId, $settings)
                : $this->resolve_german_content($product, $product_id, $marketplaceId, $settings);
            $category = $this->resolve_category($product, $product_id, $skuResolution['sku'], $marketplaceId, $settings);
            $aspects = $this->resolve_product_aspects($product, $product_id, $skuResolution['sku'], $settings, $category['category_id'], $content);
            $preflight = $this->preflight_validate($product, $product_id, $skuResolution, $content, $category, $aspects, $settings);
            if (!empty($settings['lightweight'])) {
                unset($preflight['aspects']);
            }
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
        $publishContext = $this->manual_publish_context($product, $product_id, $sku, $marketplaceId, $preflight);
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
            'category_id' => $publishContext['category_id'],
            'business_policy_ids' => $publishContext['business_policy_ids'],
            'price' => $publishContext['price'],
            'condition' => $publishContext['condition'],
            'content' => $publishContext['content'],
            'status' => (string) ($preflight['status'] ?? 'not_ready'),
            'preflight_ready' => !empty($preflight['ready']),
            'preflight' => $preflight,
            'inspect_offer_before_publish' => null,
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

        $offerInspection = $this->inspect_offer_before_publish($product_id, $variation_id, $offerId, $sku, $marketplaceId);
        $baseResult['inspect_offer_before_publish'] = $offerInspection;

        $published = $this->client->publish_offer($offerId, [
            'stage' => 'manualPublishOfferOnly',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => $sku,
            'inventory_id' => $sku,
            'offer_id' => $offerId,
            'request_offer_id' => $offerId,
            'request_inventory_id' => $sku,
            'marketplace_id' => $marketplaceId,
            'category_id' => $publishContext['category_id'],
            'business_policy_ids' => $publishContext['business_policy_ids'],
            'price' => $publishContext['price'],
            'condition' => $publishContext['condition'],
            'content' => $publishContext['content'],
        ]);

        if (is_wp_error($published)) {
            $blocked = $this->is_account_restriction_error($published);
            $errorData = $published->get_error_data();
            if ($blocked) {
                update_option('wei_ebay_global_status', 'blocked_by_ebay_account_restriction', false);
                update_option('wei_ebay_account_restriction_status', 'detected', false);
            }
            $diagnostics = $this->publish_error_diagnostics($published, $offerId, $sku);
            $result = array_merge($baseResult, [
                'status' => $blocked ? 'blocked_by_ebay_account_restriction' : 'publish_error',
                'message' => $published->get_error_message(),
                'error' => $published->get_error_code(),
                'http_status' => $diagnostics['http_status'],
                'endpoint' => $diagnostics['endpoint'],
                'method' => $diagnostics['method'],
                'request_offer_id' => $diagnostics['request_offer_id'],
                'request_inventory_id' => $diagnostics['request_inventory_id'],
                'ebay_error_id' => $diagnostics['ebay_error_id'],
                'ebay_errors' => $diagnostics['ebay_errors'],
                'error_details' => $diagnostics['error_details'],
                'ebay_raw_response' => $diagnostics['ebay_raw_response'],
                'x-ebay-c-request-id' => $diagnostics['x-ebay-c-request-id'],
                'correlation_headers' => $diagnostics['correlation_headers'],
                'ebay_response' => $diagnostics['ebay_response'],
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


    public function inspect_offer_before_publish_action(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return [
                'result' => 'error',
                'status' => 'product_not_found',
                'message' => 'Product not found.',
                'product_id' => $product_id,
                'called_publish_offer' => false,
            ];
        }

        $metaProductId = $variation_id ?: $product_id;
        $mapping = $this->repo->find_by_product($product_id, $variation_id);
        $offerId = trim((string) ($mapping['remote_offer_id'] ?? ''));
        if ($offerId === '') {
            $offerId = trim((string) get_post_meta($metaProductId, '_wei_ebay_offer_id', true));
        }
        $preflight = $this->preflight_product($product_id, $variation_id, true, false, ['suppress_side_effects' => true, 'audit_mode' => true]);
        $skuResolution = is_array($preflight['sku_resolution'] ?? null) ? $preflight['sku_resolution'] : [];
        $sku = (string) ($skuResolution['sku'] ?? $mapping['sku'] ?? get_post_meta($metaProductId, '_wei_ebay_sku', true));
        $marketplaceId = $this->marketplace_id();
        $publishContext = $this->manual_publish_context($product, $product_id, $sku, $marketplaceId, $preflight);

        $result = [
            'result' => $offerId !== '' ? 'success' : 'error',
            'status' => $offerId !== '' ? 'inspected' : 'missing_offer_id',
            'message' => $offerId !== '' ? 'Inspect offer before publish completed; no publishOffer call was made.' : 'No eBay offer_id exists for this product.',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'offer_id' => $offerId,
            'inventory_id' => $sku,
            'marketplace_id' => $marketplaceId,
            'preflight_ready' => !empty($preflight['ready']),
            'content' => $publishContext['content'],
            'category_id' => $publishContext['category_id'],
            'business_policy_ids' => $publishContext['business_policy_ids'],
            'price' => $publishContext['price'],
            'condition' => $publishContext['condition'],
            'called_publish_offer' => false,
            'inspect_offer_before_publish' => $offerId !== '' ? $this->inspect_offer_before_publish($product_id, $variation_id, $offerId, $sku, $marketplaceId) : null,
        ];

        update_option('wei_ebay_last_inspect_offer_before_publish_result', $result, false);
        update_post_meta($product_id, '_wei_ebay_inspect_offer_before_publish_result', wp_json_encode($result));
        $this->logger->info('Inspect offer before publish completed', $result);

        return $result;
    }

    private function manual_publish_context($product, int $product_id, string $sku, string $marketplaceId, array $preflight): array
    {
        $settings = $this->settings();
        $shippingPolicyResolution = is_array($preflight['shipping_policy_resolution'] ?? null) ? $preflight['shipping_policy_resolution'] : EbayShippingPolicyResolver::resolve_for_product($product_id, $settings);
        $priceResolution = is_array($preflight['price_resolution'] ?? null) ? $preflight['price_resolution'] : $this->resolve_price($product, $product_id, $settings);
        $conditionResolution = EbayConditionResolver::resolve($marketplaceId, $settings);
        $priceValue = $marketplaceId === 'EBAY_DE' ? (float) ($priceResolution['ebay_price_eur'] ?? 0) : (float) (is_object($product) && method_exists($product, 'get_price') ? $product->get_price() : 0);
        $category = is_array($preflight['category'] ?? null) ? $preflight['category'] : [];
        $content = is_array($preflight['content'] ?? null) ? $preflight['content'] : [];

        return [
            'sku' => $sku,
            'category_id' => (string) ($category['category_id'] ?? ''),
            'business_policy_ids' => [
                'fulfillmentPolicyId' => (string) ($shippingPolicyResolution['policy_id'] ?? EbayShippingPolicyResolver::policy_id_for_group(EbayShippingPolicyResolver::GROUP_DEFAULT_30_EUR, $settings)),
                'paymentPolicyId' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
                'returnPolicyId' => (string) ($settings['ebay_return_policy_id'] ?? ''),
            ],
            'price' => [
                'value' => $priceValue,
                'currency' => $this->offer_currency($marketplaceId),
                'resolution' => $priceResolution,
            ],
            'condition' => $conditionResolution,
            'content' => [
                'stale' => !empty($content['stale']),
                'source' => (string) ($content['source'] ?? ''),
                'title_present' => trim((string) ($content['title'] ?? '')) !== '',
                'description_present' => trim((string) ($content['description'] ?? '')) !== '',
            ],
        ];
    }

    private function inspect_offer_before_publish(int $product_id, ?int $variation_id, string $offerId, string $sku, string $marketplaceId): array
    {
        $offerResponse = $this->client->get_offer($offerId, [
            'stage' => 'inspectOfferBeforePublish.getOffer',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => $sku,
            'inventory_id' => $sku,
            'offer_id' => $offerId,
            'marketplace_id' => $marketplaceId,
        ]);
        $inventoryResponse = $sku !== '' ? $this->client->get_inventory_item($sku, [
            'stage' => 'inspectOfferBeforePublish.getInventoryItem',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => $sku,
            'inventory_id' => $sku,
            'offer_id' => $offerId,
            'marketplace_id' => $marketplaceId,
        ]) : new \WP_Error('wei_inventory_id_missing', 'inventory_id is missing');

        $offer = is_wp_error($offerResponse) ? [] : (array) $offerResponse;
        $listingDescription = (string) ($offer['listingDescription'] ?? '');

        return [
            'called_publish_offer' => false,
            'offer_ok' => !is_wp_error($offerResponse),
            'offer_error' => is_wp_error($offerResponse) ? $this->api_result($offerResponse) : null,
            'inventory_item_exists' => !is_wp_error($inventoryResponse),
            'inventory_item_error' => is_wp_error($inventoryResponse) ? $this->api_result($inventoryResponse) : null,
            'offer_id' => $offerId,
            'inventory_id' => $sku,
            'marketplace' => (string) ($offer['marketplaceId'] ?? $marketplaceId),
            'categoryId' => (string) ($offer['categoryId'] ?? ''),
            'listingPolicies' => is_array($offer['listingPolicies'] ?? null) ? $offer['listingPolicies'] : [],
            'merchantLocationKey' => (string) ($offer['merchantLocationKey'] ?? ''),
            'pricingSummary' => is_array($offer['pricingSummary'] ?? null) ? $offer['pricingSummary'] : [],
            'format' => (string) ($offer['format'] ?? ''),
            'availableQuantity' => $offer['availableQuantity'] ?? ($offer['availability']['shipToLocationAvailability']['quantity'] ?? null),
            'listingDescription' => [
                'present' => $listingDescription !== '',
                'length' => mb_strlen($listingDescription),
            ],
            'offer' => $offer,
        ];
    }

    private function publish_error_diagnostics(\WP_Error $error, string $offerId, string $sku): array
    {
        $data = $error->get_error_data();
        $details = is_array($data) ? $data : [];
        $responseBody = $details['response_body'] ?? ($details['error_details']['response_body'] ?? null);
        $errors = is_array($details['ebay_errors'] ?? null) ? $details['ebay_errors'] : [];
        if ($errors === [] && is_array($responseBody) && is_array($responseBody['errors'] ?? null)) {
            $errors = array_values(array_filter($responseBody['errors'], 'is_array'));
        }

        return [
            'http_status' => $details['http_status'] ?? $details['status'] ?? null,
            'endpoint' => (string) ($details['endpoint'] ?? ''),
            'method' => (string) ($details['method'] ?? ''),
            'request_offer_id' => (string) ($details['request_offer_id'] ?? $offerId),
            'request_inventory_id' => (string) ($details['request_inventory_id'] ?? $sku),
            'ebay_error_id' => $details['ebay_error_id'] ?? ($errors[0]['errorId'] ?? null),
            'ebay_errors' => $errors,
            'error_details' => $details,
            'ebay_raw_response' => (string) ($details['ebay_raw_response'] ?? ''),
            'x-ebay-c-request-id' => (string) ($details['x-ebay-c-request-id'] ?? ''),
            'correlation_headers' => is_array($details['correlation_headers'] ?? null) ? $details['correlation_headers'] : [],
            'ebay_response' => $responseBody ?? $data,
        ];
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

    public function generate_german_content_meta_only(int $product_id, bool $forceRefresh = false): array
    {
        $safety = [
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'created_ebay_listing' => false,
            'modified_woo_product' => false,
            'ebay_api_calls' => false,
            'published' => false,
            'offer_write_calls' => false,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ];

        $product = wc_get_product($product_id);
        if (!$product) {
            return array_merge([
                'result' => 'error',
                'product_id' => $product_id,
                'reason' => 'product_not_found',
                'source_hash' => '',
                'stored_hash' => '',
                'stale_before' => false,
                'stale_after' => false,
                'translation_source' => '',
                'google_api_called' => false,
                'translated_title' => '',
                'translated_description_preview' => '',
                'translated_fields_count' => 0,
                'translated_item_specifics_count' => 0,
                'untranslated_fields' => [],
            ], $safety);
        }

        $settings = $this->settings();
        $settings['_wei_german_content_only_action'] = true;
        $source = $this->ebay_german_content_source($product, $product_id);
        $translator = $this->ebay_german_content_translator();
        $cachedBefore = $translator->cached($product_id, $source);
        $sourceHash = (string) ($cachedBefore['source_hash'] ?? $translator->source_hash($source));
        $staleBefore = !empty($cachedBefore['stale']);

        if (!$forceRefresh) {
            $beforeTitle = trim((string) ($cachedBefore['title'] ?? get_post_meta($product_id, '_wei_ebay_de_title', true)));
            $beforeDescription = trim((string) ($cachedBefore['description'] ?? get_post_meta($product_id, '_wei_ebay_de_description', true)));
            if ($beforeTitle !== '' && $beforeDescription !== '' && !$staleBefore) {
                return array_merge([
                    'result' => 'already_ready',
                    'product_id' => $product_id,
                    'title_length' => mb_strlen($beforeTitle),
                    'description_length' => mb_strlen($beforeDescription),
                    'source' => (string) ($cachedBefore['translation_source'] ?? (trim((string) get_post_meta($product_id, '_wei_ebay_de_content_source', true)) ?: 'custom_meta')),
                    'source_hash' => $sourceHash,
                    'stored_hash' => (string) ($cachedBefore['cached_translation_hash'] ?? ''),
                    'cached_translation_hash' => (string) ($cachedBefore['cached_translation_hash'] ?? ''),
                    'stale' => false,
                    'stale_before' => false,
                    'stale_after' => false,
                    'translation_source' => (string) ($cachedBefore['translation_source'] ?? 'cache'),
                    'google_api_called' => false,
                    'translated_title' => $beforeTitle,
                    'translated_description_preview' => mb_substr(wp_strip_all_tags($beforeDescription), 0, 240),
                    'translated_fields_count' => count((array) ($cachedBefore['fields'] ?? [])),
                    'translated_item_specifics_count' => count((array) ($cachedBefore['aspects'] ?? [])),
                    'untranslated_fields' => $translator->untranslated_fields($cachedBefore),
                ], $safety);
            }

            $previousSuppress = $this->suppressVerboseLogs;
            $this->suppressVerboseLogs = true;
            try {
                $content = $this->resolve_german_content($product, $product_id, 'EBAY_DE', $settings);
            } finally {
                $this->suppressVerboseLogs = $previousSuppress;
            }
        } else {
            $provider = $this->translation_provider($settings);
            if (!$provider || !$provider->is_configured()) {
                return array_merge([
                    'result' => 'error',
                    'product_id' => $product_id,
                    'reason' => 'translation_provider_not_configured',
                    'source_hash' => $sourceHash,
                    'stored_hash' => (string) ($cachedBefore['cached_translation_hash'] ?? ''),
                    'cached_translation_hash' => (string) ($cachedBefore['cached_translation_hash'] ?? ''),
                    'stale_before' => $staleBefore,
                    'stale_after' => $staleBefore,
                    'translation_source' => $this->configured_translation_provider_key($settings),
                    'google_api_called' => false,
                    'translated_title' => '',
                    'translated_description_preview' => '',
                    'translated_fields_count' => 0,
                    'translated_item_specifics_count' => 0,
                    'untranslated_fields' => [],
                    'error_message' => 'Google Translation provider is not configured.',
                ], $safety);
            }

            try {
                $payload = $translator->refresh($product_id, $source, $provider);
                $mpn = (string) ($this->resolve_mpn_aspect_value($product, $product_id, (string) $product->get_sku())['value'] ?? '');
                $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id, '', $settings);
                $title = $this->sanitize_ebay_de_title((string) ($payload['title'] ?? ''), $mpn, $manufacturer);
                if ($title !== (string) ($payload['title'] ?? '')) {
                    $payload['title'] = $title;
                    update_post_meta($product_id, EbayGermanContentTranslator::META_TITLE, $title);
                    update_post_meta($product_id, EbayGermanContentTranslator::META_PAYLOAD, $payload);
                }
                $description = trim(wp_kses_post((string) ($payload['description'] ?? '')));
                if ($title === '' || $description === '') {
                    throw new \RuntimeException('Google Translation provider returned empty German title or description.');
                }
                $content = $this->log_german_content($product_id, $product_id, (string) ($payload['translation_source'] ?? ('generated_' . $provider->provider_key())), $title, $description, [
                    'provider' => $provider->provider_key(),
                    'generated' => true,
                    'stale' => false,
                    'source_hash' => $sourceHash,
                    'content_hash' => $sourceHash,
                    'cached_translation_hash' => $sourceHash,
                    'current_content_hash' => $sourceHash,
                    'source_description' => (string) ($source['description'] ?? ''),
                    'description_source' => (string) ($source['description_source'] ?? 'post_content'),
                    'fields' => (array) ($payload['fields'] ?? []),
                    'translated_fields' => (array) ($payload['translated_fields'] ?? []),
                    'untranslated_fields' => $translator->untranslated_fields($payload),
                    'aspects' => (array) ($payload['aspects'] ?? []),
                    'google_api_called' => !empty($payload['google_api_called']),
                ]);
            } catch (\Throwable $e) {
                return array_merge([
                    'result' => 'error',
                    'product_id' => $product_id,
                    'reason' => 'generation_failed',
                    'source_hash' => $sourceHash,
                    'stored_hash' => (string) ($cachedBefore['cached_translation_hash'] ?? ''),
                    'cached_translation_hash' => (string) ($cachedBefore['cached_translation_hash'] ?? ''),
                    'stale_before' => $staleBefore,
                    'stale_after' => $staleBefore,
                    'translation_source' => $this->configured_translation_provider_key($settings),
                    'google_api_called' => false,
                    'translated_title' => '',
                    'translated_description_preview' => '',
                    'translated_fields_count' => 0,
                    'translated_item_specifics_count' => 0,
                    'untranslated_fields' => [],
                    'error_message' => 'German eBay content generation failed: ' . $e->getMessage(),
                ], $safety);
            }
        }

        $cachedAfter = $translator->cached($product_id, $source);
        $ready = !empty($content['ready']);
        $title = trim((string) ($content['title'] ?? ''));
        $description = trim((string) ($content['description'] ?? ''));

        if ($ready && $title !== '' && $description !== '') {
            update_post_meta($product_id, '_wei_ebay_de_title', $title);
            update_post_meta($product_id, '_wei_ebay_de_description', $description);
            update_post_meta($product_id, '_wei_ebay_de_content_source', (string) ($content['source'] ?? 'generated'));
            update_post_meta($product_id, '_wei_ebay_de_content_generated_at', gmdate('c'));
            update_post_meta($product_id, '_wei_ebay_de_content_hash', $sourceHash);
            $cachedAfter = $translator->cached($product_id, $source);
        }

        return array_merge([
            'result' => $ready ? 'success' : 'error',
            'product_id' => $product_id,
            'source' => (string) ($content['source'] ?? ''),
            'provider' => (string) ($content['provider'] ?? ''),
            'title_length' => (int) ($content['title_length'] ?? 0),
            'description_length' => (int) ($content['description_length'] ?? 0),
            'source_hash' => $sourceHash,
            'stored_hash' => (string) get_post_meta($product_id, '_wei_ebay_de_content_hash', true),
            'cached_translation_hash' => (string) ($cachedAfter['cached_translation_hash'] ?? ''),
            'stale' => !empty($cachedAfter['stale']),
            'stale_before' => $staleBefore,
            'stale_after' => !empty($cachedAfter['stale']),
            'translation_source' => (string) ($content['source'] ?? $cachedAfter['translation_source'] ?? ''),
            'google_api_called' => !empty($content['google_api_called']),
            'translated_title' => $title,
            'translated_description_preview' => mb_substr(wp_strip_all_tags($description), 0, 240),
            'translated_fields_count' => count((array) ($content['fields'] ?? $cachedAfter['fields'] ?? [])),
            'translated_item_specifics_count' => count((array) ($content['aspects'] ?? $cachedAfter['aspects'] ?? [])),
            'untranslated_fields' => (array) ($content['untranslated_fields'] ?? $translator->untranslated_fields($cachedAfter)),
            'error_message' => (string) ($content['error_message'] ?? ''),
        ], $safety);
    }

    private function resolve_german_content_lightweight($product, int $product_id, string $marketplaceId, array $settings): array
    {
        if ($marketplaceId !== 'EBAY_DE') {
            $title = is_object($product) && method_exists($product, 'get_name') ? trim(wp_strip_all_tags((string) $product->get_name())) : '';
            $hasDescription = $title !== '';
            return [
                'ready' => $title !== '' && $hasDescription,
                'title' => $title,
                'description' => $hasDescription ? '__present__' : '',
                'source' => 'lightweight_product',
                'language' => '',
                'title_length' => mb_strlen($title),
                'description_length' => 0,
                'lightweight' => true,
            ];
        }

        $title = trim(wp_strip_all_tags((string) get_post_meta($product_id, EbayGermanContentTranslator::META_TITLE, true)));
        $descriptionPresent = function_exists('metadata_exists')
            ? metadata_exists('post', $product_id, EbayGermanContentTranslator::META_DESCRIPTION)
            : get_post_meta($product_id, EbayGermanContentTranslator::META_DESCRIPTION, true) !== '';
        $source = trim((string) get_post_meta($product_id, EbayGermanContentTranslator::META_SOURCE, true)) ?: 'lightweight_meta';

        return [
            'ready' => $title !== '' && $descriptionPresent,
            'title' => $title,
            'description' => $descriptionPresent ? '__present__' : '',
            'source' => $descriptionPresent ? $source : 'missing',
            'language' => 'de-DE',
            'product_id' => $product_id,
            'source_product_id' => $product_id,
            'translated_product_id' => 0,
            'title_found' => $title !== '',
            'description_found' => $descriptionPresent,
            'title_length' => mb_strlen($title),
            'description_length' => $descriptionPresent ? 1 : 0,
            'generated' => false,
            'stale' => false,
            'lightweight' => true,
            'error_message' => $descriptionPresent ? '' : 'German eBay content missing.',
        ];
    }

    private function resolve_german_content($product, int $product_id, string $marketplaceId, array $settings): array
    {
        if ($marketplaceId !== 'EBAY_DE') {
            return ['ready' => true, 'title' => (string) $product->get_name(), 'description' => (string) $product->get_description(), 'source' => 'default', 'language' => ''];
        }

        $source = $this->ebay_german_content_source($product, $product_id);
        $translator = $this->ebay_german_content_translator();
        $cached = $translator->cached($product_id, $source);
        if (!empty($cached['ready'])) {
            if (!empty($cached['stale']) && !empty($settings['regenerate_german_content_on_hash_change']) && empty($settings['_wei_suppress_side_effects'])) {
                return $this->maybe_generate_german_content($product, $product_id, $settings, 'stale_meta_hash_changed');
            }
            return $this->log_german_content($product_id, $product_id, (string) ($cached['translation_source'] ?? 'ebay_german_content_cache'), (string) ($cached['title'] ?? ''), (string) ($cached['description'] ?? ''), [
                'generated' => false,
                'stale' => !empty($cached['stale']),
                'source_hash' => (string) ($cached['source_hash'] ?? ''),
                'content_hash' => (string) ($cached['cached_translation_hash'] ?? ''),
                'cached_translation_hash' => (string) ($cached['cached_translation_hash'] ?? ''),
                'current_content_hash' => (string) ($cached['source_hash'] ?? ''),
                'source_description' => (string) ($source['description'] ?? ''),
                'description_source' => (string) ($source['description_source'] ?? 'post_content'),
                'fields' => (array) ($cached['fields'] ?? []),
                'translated_fields' => (array) ($cached['translated_fields'] ?? []),
                'untranslated_fields' => $translator->untranslated_fields($cached),
                'aspects' => (array) ($cached['aspects'] ?? []),
                'google_api_called' => false,
            ]);
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
                'stale' => false,
                'source_hash' => (string) ($cached['source_hash'] ?? $translator->source_hash($source)),
                'cached_translation_hash' => (string) ($cached['cached_translation_hash'] ?? ''),
                'source_description' => (string) ($source['description'] ?? ''),
                'description_source' => (string) ($source['description_source'] ?? 'post_content'),
                'side_effects_suppressed' => true,
                'error_message' => 'German eBay content missing; preview does not call Google Translate and did not use old Allegro/WPML/Polylang cache.',
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
        $source = $this->ebay_german_content_source($product, $product_id);
        $translator = $this->ebay_german_content_translator();
        $hash = $translator->source_hash($source);
        $baseLog = [
            'product_id' => $product_id,
            'source_language' => 'pl',
            'target_language' => 'de',
            'provider' => $providerKey,
            'generated' => false,
            'content_hash' => $hash,
            'source_hash' => $hash,
            'source_used' => $reason,
            'source_description' => (string) ($source['description'] ?? ''),
            'description_source' => (string) ($source['description_source'] ?? 'post_content'),
            'ebay_write_calls' => false,
            'message' => 'German eBay content regeneration writes plugin meta _wei_ebay_de_* only; no publishOffer/createOrReplaceInventoryItem/createOffer/updateOffer calls are executed.',
        ];

        if (empty($settings['auto_generate_german_content_preflight']) && empty($settings['_wei_german_content_only_action'])) {
            $message = 'German eBay content missing or stale and automatic generation during preflight is disabled.';
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->warning('German eBay content generator skipped', array_merge($baseLog, ['error_message' => $message]));
            }
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }

        $provider = $this->translation_provider($settings);
        if (!$provider || !$provider->is_configured()) {
            $message = 'German eBay content missing or stale and Google Translation provider is not configured.';
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->warning('German eBay content generator unavailable', array_merge($baseLog, ['error_message' => $message]));
            }
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }

        try {
            $payload = $translator->refresh($product_id, $source, $provider);
            $mpn = (string) ($this->resolve_mpn_aspect_value($product, $product_id, (string) $product->get_sku())['value'] ?? '');
            $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id, '', $settings);
            $title = $this->sanitize_ebay_de_title((string) ($payload['title'] ?? ''), $mpn, $manufacturer);
            if ($title !== (string) ($payload['title'] ?? '')) {
                update_post_meta($product_id, EbayGermanContentTranslator::META_TITLE, $title);
                $payload['title'] = $title;
                update_post_meta($product_id, EbayGermanContentTranslator::META_PAYLOAD, $payload);
            }
            $description = trim(wp_kses_post((string) ($payload['description'] ?? '')));
            if ($title === '' || $description === '') {
                throw new \RuntimeException('Google Translation provider returned empty German title or description.');
            }

            $extra = array_merge($baseLog, [
                'provider' => $provider->provider_key(),
                'generated' => true,
                'source_used' => 'generated_' . $provider->provider_key(),
                'translation_source' => 'generated_' . $provider->provider_key(),
                'title_length' => mb_strlen($title),
                'description_length' => mb_strlen($description),
                'cached_translation_hash' => $hash,
                'stale' => false,
                'fields' => (array) ($payload['fields'] ?? []),
                'translated_fields' => (array) ($payload['translated_fields'] ?? []),
                'untranslated_fields' => $translator->untranslated_fields($payload),
                'aspects' => (array) ($payload['aspects'] ?? []),
                'google_api_called' => !empty($payload['google_api_called']),
            ]);
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->info('German eBay content generated and saved to plugin meta only', $extra);
            }
            return $this->log_german_content($product_id, $product_id, 'generated_' . $provider->provider_key(), $title, $description, $extra);
        } catch (\Throwable $e) {
            $message = 'German eBay content generation failed: ' . $e->getMessage();
            if (!$this->suppressVerboseLogs || $this->verbose_debug_enabled($settings)) {
                $this->logger->error('German eBay content generator failed', array_merge($baseLog, ['error_message' => $message]));
            }
            return $this->log_german_content($product_id, 0, 'missing', '', '', array_merge($baseLog, ['error_message' => $message]));
        }
    }





    private function build_ebay_de_description_template($product, int $productId, array $content, array $aspects, array $category): string
    {
        $preview = $this->build_ebay_de_description_preview_data($product, $productId, $content);
        return (string) ($preview['html'] ?? '');
    }

    private function build_ebay_de_description_preview_data($product, int $productId, array $germanContent = []): array
    {
        $preferredTitle = (string) ($germanContent['title'] ?? '');
        $title = trim(wp_strip_all_tags($preferredTitle));
        if ($title === '' && method_exists($product, 'get_name')) {
            $title = trim(wp_strip_all_tags((string) $product->get_name()));
        }

        $source = $this->ebay_german_content_source($product, $productId);
        $sourceDescription = (string) ($source['description'] ?? '');
        $translatedDescription = trim((string) ($germanContent['description'] ?? ''));
        $hasTranslatedDescription = $translatedDescription !== '' && !empty($germanContent['ready']) && empty($germanContent['stale']) && (string) ($germanContent['source'] ?? '') !== 'missing';
        $rawDescription = $hasTranslatedDescription ? $translatedDescription : $sourceDescription;
        $descriptionSource = $hasTranslatedDescription ? (string) ($germanContent['source'] ?? 'german_content') : (string) ($source['description_source'] ?? 'post_content');
        $translationSource = [
            'title' => $preferredTitle !== '' ? ((string) ($germanContent['source'] ?? 'german_content')) : 'product_name',
            'description' => $descriptionSource,
            'attributes' => !empty($germanContent['fields']) ? 'cached_google_german_content_fields' : 'source_fields_no_preview_google_call',
            'values' => !empty($germanContent['fields']) ? 'cached_google_german_content_values' : 'source_values_no_preview_google_call',
            'preview_called_google_api' => false,
            'regeneration_called_google_api' => !empty($germanContent['google_api_called']),
            'target_language' => 'de',
            'translated_raw_html' => false,
            'html_css_protected' => true,
        ];
        $descriptionHtml = $this->sanitize_ebay_template_description_html($rawDescription);

        $details = !empty($germanContent['fields']) && empty($germanContent['stale'])
            ? ['fields' => (array) $germanContent['fields'], 'used_fields' => (array) $germanContent['fields'], 'missing_fields' => [], 'field_mapping' => [], 'warnings' => []]
            : $this->collect_woo_product_details_for_ebay_template($product, $productId);
        $detailsByPolishLabel = [];
        foreach ($details['fields'] as $field) {
            $detailsByPolishLabel[(string) $field['polish_label']] = (string) $field['value'];
        }

        $fitment = $this->build_template_fitment_value($detailsByPolishLabel);
        $untranslatedFields = $this->detect_likely_polish_template_fields($details['fields']);
        $sameVehicle = $this->resolve_ebay_same_vehicle_url_for_product($productId);
        $sameVehicleUrl = (string) ($sameVehicle['url'] ?? '');
        $sameVehicleToken = (string) ($sameVehicle['token'] ?? '');
        $sameVehicleCta = (array) ($sameVehicle['metadata'] ?? []);
        $warnings = array_merge($details['warnings'], (array) ($sameVehicle['warnings'] ?? []));

        $specRows = $this->render_ebay_template_specification_rows($details['fields']);

        $buttonHtml = $sameVehicleUrl !== '' && $sameVehicleToken !== ''
            ? '<div style="border:1px solid #dbe3ef;background:#f8fbff;margin:18px 0 24px;border-radius:8px;padding:18px 20px;text-align:center;"><div style="color:#06275d;font-size:18px;font-weight:900;margin-bottom:8px;">Weitere Teile aus diesem Fahrzeug ansehen</div><p style="margin:0 0 12px;color:#1f2937;line-height:1.6;">Entdecken Sie weitere verfügbare Teile aus demselben Fahrzeug.</p><p style="margin:0 0 14px;color:#4b5563;font-size:13px;line-height:1.5;">Fahrzeugreferenz: ' . esc_html($sameVehicleToken) . '</p><a href="' . esc_url($sameVehicleUrl) . '" style="display:inline-block;background:#0057d9;color:#ffffff;text-decoration:none;padding:16px 28px;border-radius:6px;font-size:14px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;box-shadow:0 8px 18px rgba(0,87,217,.18);">Weitere Teile ansehen</a></div>'
            : '';

        $descriptionBlock = $descriptionHtml !== ''
            ? $descriptionHtml
            : '<p style="margin:0;color:#4b5563;line-height:1.7;">Nicht angegeben</p>';

        $html = '<div style="max-width:1080px;margin:0 auto;background:#ffffff;color:#111827;font-family:Arial,Helvetica,sans-serif;border:1px solid #dbe3ef;border-radius:10px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.08);">'
            . '<div style="background:#f8fbff;border-bottom:1px solid #dbe3ef;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr>'
            . '<td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;border-right:1px solid #dbe3ef;"><img src="https://gpswiss.pl/wp-content/uploads/ebay-template/icon-shipping.png" alt="Schneller Versand" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />Schneller weltweiter Versand</td>'
            . '<td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;border-right:1px solid #dbe3ef;"><img src="https://gpswiss.pl/wp-content/uploads/ebay-template/icon-returns.png" alt="30 Tage Rückgabe" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />30 Tage Rückgabe</td>'
            . '<td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;border-right:1px solid #dbe3ef;"><img src="https://gpswiss.pl/wp-content/uploads/ebay-template/icon-packaging.png" alt="Sichere Verpackung" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />Sichere Verpackung</td>'
            . '<td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;"><img src="https://gpswiss.pl/wp-content/uploads/ebay-template/icon-original.png" alt="100% Originalteil" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />100% Originalteil</td>'
            . '</tr></table></div>'
            . '<div style="padding:32px 30px 28px;">'
            . '<h1 style="margin:0;color:#06275d;font-size:38px;line-height:1.16;font-weight:900;letter-spacing:.3px;text-transform:uppercase;text-align:center;">' . esc_html($title) . '</h1>'
            . '<div style="width:92px;height:4px;background:#0057d9;margin:14px auto 22px;border-radius:2px;"></div>'
            . '<div style="border:1px solid #dbe3ef;border-radius:8px;overflow:hidden;background:#ffffff;margin:0 0 22px;"><div style="background:#06275d;color:#ffffff;padding:15px 17px;font-size:18px;font-weight:900;letter-spacing:.2px;text-align:center;">Beschreibung</div><div style="padding:20px 22px;text-align:center;">' . $descriptionBlock . '</div></div>'
            . '<div style="border:1px solid #dbe3ef;border-radius:8px;overflow:hidden;background:#ffffff;margin:0 0 22px;"><div style="background:#06275d;color:#ffffff;padding:15px 17px;font-size:18px;font-weight:900;letter-spacing:.2px;text-align:center;">Spezifikationen</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;text-align:center;">' . $specRows . '</table></div>'
            . '<div style="border:1px solid #dbe3ef;background:#ffffff;margin:2px 0 0;border-radius:8px;overflow:hidden;">'
            . '<div style="background:#06275d;color:#ffffff;padding:15px 17px;font-size:18px;font-weight:900;letter-spacing:.2px;text-align:center;">Kompatibilität / Passgenauigkeit</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;text-align:center;"><tr>'
            . '<td width="50%" valign="top" align="center" style="padding:20px 22px;border-right:1px solid #dbe3ef;text-align:center;"><div style="color:#06275d;font-size:16px;font-weight:900;margin-bottom:9px;text-align:center;">Passend für</div><p style="margin:0;color:#1f2937;line-height:1.7;text-align:center;">' . esc_html($fitment !== '' ? $fitment : 'Bitte anhand der Teilenummer prüfen.') . '</p></td>'
            . '<td width="50%" valign="top" align="center" style="padding:20px 22px;background:#f8fbff;text-align:center;"><div style="color:#06275d;font-size:16px;font-weight:900;margin-bottom:9px;text-align:center;">Wichtige Hinweise</div><p style="margin:0;color:#1f2937;line-height:1.7;text-align:center;">Bitte vergleichen Sie die Teilenummer und die Fotos vor dem Kauf.</p></td>'
            . '</tr></table></div>'
            . $buttonHtml
            . '<div style="border:1px solid #dbe3ef;background:#ffffff;margin:0 0 20px;border-radius:8px;overflow:hidden;box-shadow:0 8px 20px rgba(15,23,42,.05);">'
            . '<div style="padding:30px 28px 24px;text-align:center;background:#ffffff;">'
            . '<h2 style="margin:0 0 10px;color:#06275d;font-size:26px;line-height:1.2;font-weight:900;text-align:center;">Wir liefern in ganz Europa</h2>'
            . '<p style="margin:0 0 16px;color:#1f2937;font-size:16px;line-height:1.7;text-align:center;">Wir versenden in alle europäischen Länder – schnell, zuverlässig und sicher.</p>'
            . '<div style="display:inline-block;background:#eaf2ff;border:1px solid #c9dcf8;color:#06275d;border-radius:6px;padding:12px 16px;font-size:16px;font-weight:900;">Lieferzeit 2–5 Tage</div>'
            . '</div>'
            . '<img src="https://gpswiss.pl/wp-content/uploads/ebay-template/europe-map.png" alt="Lieferung in Europa" style="width:100%;height:auto;border:0;display:block;margin:0;border-radius:0 0 8px 8px;" />'
            . '</div>'
            . '<div style="border:1px solid #dbe3ef;background:#f8fbff;margin:0 0 22px;border-radius:8px;padding:18px 20px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr>'
            . '<td width="50%" align="center" style="padding:0 10px 0 0;"><div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:8px;padding:18px 12px;"><img src="https://gpswiss.pl/wp-content/uploads/ebay-template/dhl-logo.png" alt="DHL" style="max-width:160px;height:auto;border:0;display:block;margin:0 auto;" /></div></td>'
            . '<td width="50%" align="center" style="padding:0 0 0 10px;"><div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:8px;padding:18px 12px;"><img src="https://gpswiss.pl/wp-content/uploads/ebay-template/dpd-logo.png" alt="DPD" style="max-width:160px;height:auto;border:0;display:block;margin:0 auto;" /></div></td>'
            . '</tr></table></div>'
            . '</div>'
            . '<div style="background:#06275d;color:#ffffff;text-align:center;padding:24px 24px;border-top:4px solid #0057d9;">'
            . '<div style="font-size:23px;font-weight:900;margin-bottom:7px;letter-spacing:.3px;">Kaufen Sie mit Vertrauen</div>'
            . '<div style="font-size:15px;font-weight:700;color:#dbeafe;">Geprüfte gebrauchte Teile | Sorgfältig kontrolliert | Professionell verpackt</div>'
            . '</div></div>';

        $htmlValidation = self::validate_ebay_de_rendered_html($html);

        return [
            'html' => $html,
            'title' => $title,
            'source_description' => $rawDescription,
            'description_source' => $descriptionSource,
            'translation_source' => $translationSource,
            'target_language' => 'de',
            'translated_raw_html' => false,
            'html_css_protected' => true,
            'html_validation' => $htmlValidation,
            'translated_description' => $translatedDescription,
            'source_hash' => (string) ($germanContent['source_hash'] ?? $this->ebay_german_content_translator()->source_hash($source)),
            'cached_translation_hash' => (string) ($germanContent['cached_translation_hash'] ?? $germanContent['content_hash'] ?? ''),
            'stale' => !empty($germanContent['stale']),
            'google_api_called_during_regeneration' => !empty($germanContent['google_api_called']),
            'preview_called_google_api' => false,
            'translated_fields' => (array) ($germanContent['translated_fields'] ?? []),
            'translated_text_nodes' => (array) ($germanContent['translated_text_nodes'] ?? $germanContent['translated_fields'] ?? []),
            'protected_technical_values' => array_values(array_unique(array_merge((array) ($germanContent['protected_technical_values'] ?? []), $sameVehicleToken !== '' ? [$sameVehicleToken] : []))),
            'untranslated_fields' => array_merge($untranslatedFields, (array) ($germanContent['untranslated_fields'] ?? [])),
            'used_fields' => $details['used_fields'],
            'missing_fields' => $details['missing_fields'],
            'field_mapping' => $details['field_mapping'],
            'same_vehicle_url' => $sameVehicleUrl,
            'same_vehicle_cta' => $sameVehicleCta,
            'warnings' => array_merge($warnings, empty($htmlValidation['valid']) ? [['code' => (string) ($htmlValidation['error'] ?? 'invalid_translated_html_css'), 'matches' => (array) ($htmlValidation['matches'] ?? [])]] : []),
        ];
    }

    public static function validate_ebay_de_rendered_html(string $html): array
    {
        $forbidden = [
            'szerokość',
            'wysokość',
            'tło',
            'waga czcionki',
            'wyrównanie tekstu',
            'promień obramowania',
            'kolor:#',
            'wypełnienie',
            'margines',
        ];
        $matches = [];
        $lower = mb_strtolower($html);
        foreach ($forbidden as $term) {
            if (str_contains($lower, mb_strtolower($term))) {
                $matches[] = $term;
            }
        }
        return $matches === []
            ? ['valid' => true, 'error' => '']
            : ['valid' => false, 'error' => 'invalid_translated_html_css', 'matches' => array_values(array_unique($matches))];
    }

    private function resolve_ebay_same_vehicle_url_for_product(int $productId): array
    {
        $warnings = [];
        foreach (['wei_get_ebay_same_vehicle_url_for_product', 'gp_get_ebay_same_vehicle_url_for_product', 'gp_get_ebay_vehicle_parts_url_for_product'] as $helper) {
            if (!function_exists($helper)) {
                continue;
            }
            $url = trim((string) $helper($productId));
            if ($url !== '') {
                $warnings[] = [
                    'code' => $this->is_ebay_public_url($url) ? 'legacy_ebay_same_vehicle_url_ignored' : 'legacy_woo_same_vehicle_url_ignored',
                    'source' => $helper,
                    'message' => 'Legacy same-vehicle URL helper is intentionally ignored; eBay.de token search URL is used instead when possible.',
                ];
            }
        }

        foreach (['_wei_ebay_same_vehicle_url', '_wei_ebay_vehicle_parts_url', '_ebay_same_vehicle_url'] as $metaKey) {
            $url = trim((string) get_post_meta($productId, $metaKey, true));
            if ($url !== '') {
                $warnings[] = [
                    'code' => $this->is_ebay_public_url($url) ? 'legacy_ebay_same_vehicle_url_meta_ignored' : 'legacy_same_vehicle_url_meta_ignored',
                    'source' => $metaKey,
                    'message' => 'Legacy same-vehicle URL meta is intentionally ignored; eBay.de token search URL is used instead when possible.',
                ];
            }
        }

        $ovokoCarId = $this->resolve_ovoko_car_id($productId);
        $settings = $this->settings();
        $sellerUsername = $this->resolve_ebay_seller_username($settings);
        $strategy = 'ebay_search_title_description_token';
        $metadata = [
            'same_vehicle_cta_visible' => false,
            'reason' => '',
            'ovoko_car_id' => $ovokoCarId,
            'seller_username' => $sellerUsername,
            'checked_url_strategy' => $strategy,
        ];

        if ($ovokoCarId === '') {
            $metadata['reason'] = 'missing_ovoko_car_id';
            return ['url' => '', 'source' => '', 'token' => '', 'warnings' => $warnings, 'metadata' => $metadata];
        }
        if ($sellerUsername === '') {
            $metadata['reason'] = 'missing_ebay_seller_username_for_same_vehicle_url';
            $warnings[] = ['code' => 'missing_ebay_seller_username_for_same_vehicle_url', 'message' => 'Cannot build eBay.de same-vehicle CTA URL without seller username in plugin settings.'];
            return ['url' => '', 'source' => '', 'token' => 'GPSWCarID:' . $ovokoCarId, 'warnings' => $warnings, 'metadata' => $metadata];
        }

        $token = 'GPSWCarID:' . $ovokoCarId;
        $url = 'https://www.ebay.de/sch/i.html?_ssn=' . rawurlencode($sellerUsername) . '&LH_TitleDesc=1&_nkw=' . rawurlencode($token);
        if (!str_starts_with($url, 'https://www.ebay.de/')) {
            $metadata['reason'] = 'invalid_same_vehicle_ebay_url';
            return ['url' => '', 'source' => '', 'token' => $token, 'warnings' => $warnings, 'metadata' => $metadata];
        }

        $metadata = [
            'same_vehicle_cta_visible' => true,
            'ovoko_car_id' => $ovokoCarId,
            'same_vehicle_token' => $token,
            'same_vehicle_ebay_url' => $url,
            'url_strategy' => $strategy,
            'seller_username' => $sellerUsername,
            'checked_url_strategy' => $strategy,
        ];

        return ['url' => esc_url_raw($url), 'source' => $strategy, 'token' => $token, 'warnings' => $warnings, 'metadata' => $metadata];
    }

    private function resolve_ovoko_car_id(int $productId): string
    {
        foreach (['_ovoko_car_id', 'ovoko_car_id'] as $metaKey) {
            $value = trim((string) get_post_meta($productId, $metaKey, true));
            if ($value !== '') {
                return preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?: '';
            }
        }
        return '';
    }

    private function resolve_ebay_seller_username(array $settings): string
    {
        foreach (['ebay_seller_username', 'seller_username', 'ebay_username', 'ebay_store_username'] as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function is_ebay_public_url(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        return $host === 'ebay.com'
            || str_ends_with($host, '.ebay.com')
            || preg_match('/(^|\.)ebay\.[a-z.]+$/', $host) === 1;
    }

    private function collect_woo_product_details_for_ebay_template($product, int $productId): array
    {
        $rawRows = function_exists('gp_get_product_details_rows') ? (array) gp_get_product_details_rows($productId) : $this->fallback_product_details_rows($productId);
        $mapping = $this->ebay_de_template_field_mapping();
        $fields = [];
        $used = [];
        $missing = [];
        $fullMapping = [];

        foreach ($mapping as $polishLabel => $config) {
            $value = $this->value_from_labeled_rows($rawRows, (array) $config['aliases']);
            $row = [
                'polish_label' => $polishLabel,
                'german_label' => (string) $config['german_label'],
                'source' => 'WooCommerce product attributes displayed by gp_get_product_details_rows()',
                'meta_key_or_function' => '_product_attributes / gp_get_product_details_rows()',
                'example_value' => $value,
                'required' => (bool) $config['required'],
                'fallback' => 'empty row hidden',
            ];
            $fullMapping[] = $row;
            if ($value !== '') {
                $fields[] = $row + ['value' => $value];
                $used[] = $row + ['value' => $value];
            } else {
                $missing[] = $row;
            }
        }

        return [
            'fields' => $fields,
            'used_fields' => $used,
            'missing_fields' => $missing,
            'field_mapping' => $fullMapping,
            'warnings' => $rawRows === [] ? ['No visible product detail rows found in _product_attributes.'] : [],
        ];
    }

    private function ebay_de_template_field_mapping(): array
    {
        return [
            'Kod koloru' => ['german_label' => 'Farbcode', 'aliases' => ['Kod koloru'], 'required' => false],
            'Kod silnika' => ['german_label' => 'Motorcode', 'aliases' => ['Kod silnika'], 'required' => false],
            'Kolor' => ['german_label' => 'Farbe', 'aliases' => ['Kolor'], 'required' => false],
            'Koła napędowe' => ['german_label' => 'Antrieb', 'aliases' => ['Koła napędowe', 'Kola napedowe'], 'required' => false],
            'Moc silnika' => ['german_label' => 'Motorleistung', 'aliases' => ['Moc silnika'], 'required' => false],
            'Model' => ['german_label' => 'Modell', 'aliases' => ['Model'], 'required' => false],
            'Modyfikacja' => ['german_label' => 'Variante / Ausführung', 'aliases' => ['Modyfikacja'], 'required' => false],
            'Numer części' => ['german_label' => 'Teilenummer', 'aliases' => ['Numer części', 'Numer czesci'], 'required' => true],
            'Okres' => ['german_label' => 'Bauzeitraum', 'aliases' => ['Okres'], 'required' => false],
            'Pojemność silnika' => ['german_label' => 'Hubraum', 'aliases' => ['Pojemność silnika', 'Pojemnosc silnika'], 'required' => false],
            'Pozycja kierownicy' => ['german_label' => 'Lenkradposition', 'aliases' => ['Pozycja kierownicy'], 'required' => false],
            'Producent' => ['german_label' => 'Hersteller', 'aliases' => ['Producent'], 'required' => true],
            'Przebieg' => ['german_label' => 'Laufleistung', 'aliases' => ['Przebieg'], 'required' => false],
            'Rodzaj paliwa' => ['german_label' => 'Kraftstoffart', 'aliases' => ['Rodzaj paliwa'], 'required' => false],
            'Rok produkcji samochodu' => ['german_label' => 'Baujahr des Fahrzeugs', 'aliases' => ['Rok produkcji samochodu'], 'required' => false],
            'Stan' => ['german_label' => 'Zustand', 'aliases' => ['Stan'], 'required' => false],
            'Stan opakowania' => ['german_label' => 'Verpackungszustand', 'aliases' => ['Stan opakowania'], 'required' => false],
            'Typ skrzyni biegów' => ['german_label' => 'Getriebeart', 'aliases' => ['Typ skrzyni biegów', 'Typ skrzyni biegow'], 'required' => false],
        ];
    }

    private function detect_likely_polish_template_fields(array $fields): array
    {
        $warnings = [];
        $polishPattern = '/[ąćęłńóśźż]|\b(lewy|prawy|przód|przod|tył|tyl|manualna|automatyczna|benzyna|diesel|używany|uzywany|czarny|biały|bialy|srebrny|szary|niebieski|czerwony|zielony|żółty|zolty)\b/iu';
        foreach ($fields as $field) {
            $value = trim((string) ($field['value'] ?? ''));
            if ($value === '' || !preg_match($polishPattern, $value)) {
                continue;
            }
            $warnings[] = [
                'label' => (string) ($field['german_label'] ?? $field['polish_label'] ?? ''),
                'polish_label' => (string) ($field['polish_label'] ?? ''),
                'value' => $value,
                'message' => 'Value appears to remain in Polish; preview does not call Google Translate or write translated meta.',
            ];
        }
        return $warnings;
    }

    private function fallback_product_details_rows(int $productId): array
    {
        $rows = [];
        $attributes = (array) get_post_meta($productId, '_product_attributes', true);
        foreach ($attributes as $attribute) {
            $name = trim((string) ($attribute['name'] ?? ''));
            $value = $this->clean_template_value((string) ($attribute['value'] ?? ''));
            if ($name !== '' && $value !== '' && !empty($attribute['is_visible'])) {
                $rows[$name] = $value;
            }
        }
        return $rows;
    }

    private function value_from_labeled_rows(array $rows, array $labels): string
    {
        foreach ($labels as $label) {
            foreach ($rows as $rowLabel => $value) {
                if ($this->normalize_template_label((string) $rowLabel) === $this->normalize_template_label((string) $label)) {
                    return $this->clean_template_value((string) $value);
                }
            }
        }
        return '';
    }

    private function normalize_template_label(string $label): string
    {
        $label = remove_accents($label);
        $label = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
        return preg_replace('/[^a-z0-9]+/u', '', $label) ?: '';
    }

    private function clean_template_value(string $value): string
    {
        $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($value === '' || $value === '-') {
            return '';
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return in_array($lower, ['brak', 'null', 'n/a', 'none'], true) ? '' : $value;
    }

    private function first_non_empty(array $values): string
    {
        foreach ($values as $value) {
            $clean = $this->clean_template_value((string) $value);
            if ($clean !== '') {
                return $clean;
            }
        }
        return '';
    }

    private function build_template_fitment_value(array $detailsByPolishLabel): string
    {
        $parts = array_filter([
            $detailsByPolishLabel['Producent'] ?? '',
            $detailsByPolishLabel['Model'] ?? '',
            $detailsByPolishLabel['Modyfikacja'] ?? '',
            $detailsByPolishLabel['Rok produkcji samochodu'] ?? '',
        ], static fn ($value): bool => trim((string) $value) !== '');
        return implode(' ', $parts);
    }

    private function render_ebay_template_rows(array $rows): string
    {
        $html = '';
        foreach ($rows as $label => $value) {
            $html .= $this->render_ebay_template_row((string) $label, (string) $value);
        }
        return $html !== '' ? $html : '<tr><td align="center" style="padding:10px 12px;color:#4b5563;text-align:center;">Nicht angegeben</td></tr>';
    }

    private function render_ebay_template_specification_rows(array $fields): string
    {
        $html = '';
        $seenLabels = [];
        foreach ($fields as $field) {
            $label = (string) ($field['german_label'] ?? '');
            $value = (string) ($field['value'] ?? '');
            $normalizedLabel = $this->normalize_template_label($label);
            if ($normalizedLabel === '' || $normalizedLabel === 'zustand' || isset($seenLabels[$normalizedLabel])) {
                continue;
            }
            $row = $this->render_ebay_template_row($label, $value);
            if ($row === '') {
                continue;
            }
            $seenLabels[$normalizedLabel] = true;
            $html .= $row;
        }
        return $html !== '' ? $html : '<tr><td align="center" style="padding:10px 12px;color:#4b5563;text-align:center;">Nicht angegeben</td></tr>';
    }

    private function render_ebay_template_row(string $label, string $value): string
    {
        $value = $this->clean_template_value($value);
        if ($value === '') {
            return '';
        }
        return '<tr><td align="center" style="width:42%;padding:13px 15px;border-top:1px solid #e5e7eb;background:#f8fafc;color:#06275d;font-weight:800;line-height:1.45;text-align:center;">' . esc_html($label) . '</td><td align="center" style="padding:13px 15px;border-top:1px solid #e5e7eb;color:#111827;line-height:1.45;text-align:center;">' . esc_html($value) . '</td></tr>';
    }

    private function sanitize_ebay_template_description_html(string $rawDescription): string
    {
        $allowed = wp_kses_post($rawDescription);
        $plain = trim(wp_strip_all_tags($allowed));
        if ($plain === '') {
            return '';
        }
        $html = wpautop($allowed);
        $html = preg_replace('/<\/?(script|style|iframe|object|embed|form|input|button|canvas|svg|img)[^>]*>/iu', '', (string) $html) ?: '';
        return '<div style="color:#1f2937;line-height:1.6;">' . $html . '</div>';
    }

    private function resolve_inventory_item_description($product, int $productId, array $content, array $aspects, array $category, array $settings, string $marketplaceId): array
    {
        if ($this->should_use_ebay_de_description_template($settings, $marketplaceId)) {
            return [
                'description' => $this->build_short_inventory_product_description((string) ($content['description'] ?? '')),
                'source' => 'short_resolved_german_content_for_inventory_item',
                'template_enabled' => true,
            ];
        }

        return [
            'description' => (string) ($content['description'] ?? ''),
            'source' => 'resolved_german_content',
            'template_enabled' => !empty($settings['enable_ebay_de_description_template']),
        ];
    }

    private function resolve_offer_listing_description($product, int $productId, array $content, array $aspects, array $category, array $settings, string $marketplaceId): array
    {
        if ($this->should_use_ebay_de_description_template($settings, $marketplaceId)) {
            return [
                'description' => $this->build_ebay_de_description_template($product, $productId, $content, $aspects, $category),
                'source' => 'approved_ebay_de_template',
                'template_enabled' => true,
            ];
        }

        return [
            'description' => '',
            'source' => 'not_applicable',
            'template_enabled' => !empty($settings['enable_ebay_de_description_template']),
        ];
    }

    private function build_short_inventory_product_description(string $description): string
    {
        $plain = trim(wp_strip_all_tags(wp_kses_post($description)));
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: '';
        if ($plain === '') {
            $plain = 'Gebrauchtes Autoteil. Details, Spezifikationen und Lieferinformationen stehen in der Angebotsbeschreibung.';
        }
        if (mb_strlen($plain) > 1000) {
            $plain = rtrim(mb_substr($plain, 0, 997)) . '...';
        }
        return $plain;
    }

    private function should_use_ebay_de_description_template(array $settings, string $marketplaceId): bool
    {
        return $marketplaceId === 'EBAY_DE' && !empty($settings['enable_ebay_de_description_template']);
    }

    private function resolve_product_by_id_or_sku(string $identifier): array
    {
        $productId = ctype_digit($identifier) ? (int) $identifier : 0;
        if ($productId <= 0) {
            $found = wc_get_products(['limit' => 1, 'sku' => $identifier, 'status' => ['publish', 'draft', 'private']]);
            $productId = !empty($found[0]) ? (int) $found[0]->get_id() : 0;
        }
        if ($productId <= 0) {
            return ['product_id' => 0, 'product' => null];
        }
        $product = wc_get_product($productId);
        return ['product_id' => $productId, 'product' => $product ?: null];
    }

    public function dry_run_ebay_de_publish_description_payload(string $productOrSku): array
    {
        $identifier = trim($productOrSku);
        if ($identifier === '') return ['result' => 'error', 'error' => 'missing_input'];
        $this->logger->info('EBAY_DE_PUBLISH_DESCRIPTION_DRY_RUN_START', ['input' => $identifier, 'safety' => 'local_payload_dry_run_no_ebay_api_no_write_to_woo_or_ovoko']);
        try {
            $resolved = $this->resolve_product_by_id_or_sku($identifier);
            $productId = (int) ($resolved['product_id'] ?? 0);
            $product = $resolved['product'];
            if ($productId <= 0 || !$product) return ['result' => 'error', 'error' => 'product_not_found'];

            $settings = $this->settings();
            $settings['_wei_suppress_side_effects'] = true;
            $settings['auto_generate_german_content_preflight'] = 0;
            $settings['regenerate_german_content_on_hash_change'] = 0;
            $marketplaceId = $this->marketplace_id();
            $content = $this->resolve_german_content($product, $productId, $marketplaceId, $settings);
            $category = ['category_id' => '', 'source' => 'dry_run_not_resolved'];
            $aspects = [];
            $productDescriptionResolution = $this->resolve_inventory_item_description($product, $productId, $content, $aspects, $category, $settings, $marketplaceId);
            $listingDescriptionResolution = $this->resolve_offer_listing_description($product, $productId, $content, $aspects, $category, $settings, $marketplaceId);
            $productDescription = (string) ($productDescriptionResolution['description'] ?? '');
            $listingDescription = (string) ($listingDescriptionResolution['description'] ?? '');
            $productImageUrls = array_values(array_filter(array_map('wp_get_attachment_url', array_merge([$product->get_image_id()], $product->get_gallery_image_ids()))));
            $productImagesInListingDescription = [];
            foreach ($productImageUrls as $url) {
                $url = (string) $url;
                if ($url !== '' && str_contains($listingDescription, $url)) {
                    $productImagesInListingDescription[] = $url;
                }
            }
            $templateImageUrls = [
                'https://gpswiss.pl/wp-content/uploads/ebay-template/icon-shipping.png',
                'https://gpswiss.pl/wp-content/uploads/ebay-template/icon-returns.png',
                'https://gpswiss.pl/wp-content/uploads/ebay-template/icon-packaging.png',
                'https://gpswiss.pl/wp-content/uploads/ebay-template/icon-original.png',
                'https://gpswiss.pl/wp-content/uploads/ebay-template/europe-map.png',
                'https://gpswiss.pl/wp-content/uploads/ebay-template/dhl-logo.png',
                'https://gpswiss.pl/wp-content/uploads/ebay-template/dpd-logo.png',
            ];
            $presentTemplateImageUrls = [];
            $missingTemplateImageUrls = [];
            foreach ($templateImageUrls as $url) {
                if (str_contains($listingDescription, $url)) {
                    $presentTemplateImageUrls[] = $url;
                } else {
                    $missingTemplateImageUrls[] = $url;
                }
            }
            $listingContainsTemplate = str_contains($listingDescription, 'gpswiss.pl/wp-content/uploads/ebay-template/') && str_contains($listingDescription, 'Schneller weltweiter Versand');
            $productContainsTemplate = str_contains($productDescription, 'gpswiss.pl/wp-content/uploads/ebay-template/') || str_contains($productDescription, 'Schneller weltweiter Versand');

            return [
                'result' => 'success',
                'product_id' => $productId,
                'sku' => (string) $product->get_sku(),
                'marketplace_id' => $marketplaceId,
                'template_setting_enabled' => !empty($settings['enable_ebay_de_description_template']),
                'inventory_item_product_description_source' => (string) ($productDescriptionResolution['source'] ?? ''),
                'offer_listing_description_source' => (string) ($listingDescriptionResolution['source'] ?? ''),
                'product_description_length' => mb_strlen($productDescription),
                'product_description_within_4000' => mb_strlen($productDescription) > 0 && mb_strlen($productDescription) < 4000,
                'product_description_contains_new_template' => $productContainsTemplate,
                'listing_description_length' => mb_strlen($listingDescription),
                'listing_description_contains_new_template' => $listingContainsTemplate,
                'listing_description_contains_template_image_urls' => $presentTemplateImageUrls !== [] && $missingTemplateImageUrls === [],
                'listing_description_contains_product_images' => $productImagesInListingDescription !== [],
                'product_image_urls_found_in_listing_description' => $productImagesInListingDescription,
                'template_image_urls_present' => $presentTemplateImageUrls,
                'template_image_urls_missing' => $missingTemplateImageUrls,
                'template_attached_to' => 'offer.listingDescription',
                'where_template_is_attached' => 'offer.listingDescription',
                'safety_flags' => [
                    'no_ebay_api' => true,
                    'no_listing_created' => true,
                    'no_listing_updated' => true,
                    'no_woo_changes' => true,
                    'no_ovoko_api' => true,
                    'inventory_item_product_description_has_full_template' => $productContainsTemplate,
                    'offer_listing_description_has_full_template' => $listingContainsTemplate,
                    'offer_listing_description_has_template_image_urls' => $presentTemplateImageUrls !== [] && $missingTemplateImageUrls === [],
                    'offer_listing_description_has_product_images' => $productImagesInListingDescription !== [],
                ],
                'payload_excerpt' => [
                    'inventory_item' => [
                        'product' => [
                            'description' => mb_substr($productDescription, 0, 1200),
                        ],
                    ],
                    'offer' => [
                        'listingDescription' => mb_substr($listingDescription, 0, 1200),
                    ],
                ],
                'safety' => [
                    'created_ebay_listing' => false,
                    'called_ebay_api' => false,
                    'modified_woo_product' => false,
                    'called_ovoko_api' => false,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('EBAY_DE_PUBLISH_DESCRIPTION_DRY_RUN_FAILED', ['input' => $identifier, 'error' => $e->getMessage()]);
            return ['result' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function preview_ebay_de_description_template(string $productOrSku): array
    {
        $identifier = trim($productOrSku);
        if ($identifier === '') return ['result' => 'error', 'error' => 'missing_input'];
        $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_PREVIEW_START', ['input' => $identifier, 'safety' => 'local_preview_no_ebay_api_no_write_to_woo_or_ovoko']);
        try {
            $resolved = $this->resolve_product_by_id_or_sku($identifier);
            $productId = (int) ($resolved['product_id'] ?? 0);
            $product = $resolved['product'];
            if ($productId <= 0 || !$product) return ['result' => 'error', 'error' => 'product_not_found'];
            $settings = $this->settings();
            $settings['_wei_suppress_side_effects'] = true;
            $settings['auto_generate_german_content_preflight'] = 0;
            $settings['regenerate_german_content_on_hash_change'] = 0;
            $content = $this->resolve_german_content($product, $productId, 'EBAY_DE', $settings);
            $preview = $this->build_ebay_de_description_preview_data($product, $productId, $content);
            $html = (string) ($preview['html'] ?? '');
            $htmlValidation = (array) ($preview['html_validation'] ?? self::validate_ebay_de_rendered_html($html));
            if (empty($htmlValidation['valid'])) {
                return [
                    'result' => 'error',
                    'error' => (string) ($htmlValidation['error'] ?? 'invalid_translated_html_css'),
                    'matches' => (array) ($htmlValidation['matches'] ?? []),
                    'product_id' => $productId,
                    'sku' => (string) $product->get_sku(),
                    'html' => $html,
                    'warnings' => (array) ($preview['warnings'] ?? []),
                    'safety' => [
                        'called_ebay_api' => false,
                        'called_ovoko_api' => false,
                        'updated_ebay_listing' => false,
                        'created_ebay_listing' => false,
                        'modified_woo_product' => false,
                    ],
                ];
            }
            $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_PREVIEW_DONE', ['product_id' => $productId, 'sku' => (string) $product->get_sku(), 'html_length' => mb_strlen($html), 'safety' => 'local_preview_no_ebay_api_no_write_to_woo_or_ovoko']);
            return [
                'result' => 'success',
                'product_id' => $productId,
                'sku' => (string) $product->get_sku(),
                'title' => (string) ($preview['title'] ?? ''),
                'source_description' => (string) ($preview['source_description'] ?? ''),
                'translated_description' => (string) ($preview['translated_description'] ?? ''),
                'description_source' => (string) ($preview['description_source'] ?? 'post_content'),
                'source_hash' => (string) ($preview['source_hash'] ?? ''),
                'cached_translation_hash' => (string) ($preview['cached_translation_hash'] ?? ''),
                'stale' => !empty($preview['stale']),
                'translation_source' => (array) ($preview['translation_source'] ?? []),
                'target_language' => (string) ($preview['target_language'] ?? 'de'),
                'translated_raw_html' => false,
                'html_css_protected' => true,
                'translated_text_nodes' => (array) ($preview['translated_text_nodes'] ?? []),
                'protected_technical_values' => (array) ($preview['protected_technical_values'] ?? []),
                'translated_fields' => (array) ($preview['translated_fields'] ?? []),
                'untranslated_fields' => (array) ($preview['untranslated_fields'] ?? []),
                'google_api_called_during_regeneration' => !empty($preview['google_api_called_during_regeneration']),
                'preview_called_google_api' => false,
                'used_fields' => (array) ($preview['used_fields'] ?? []),
                'missing_fields' => (array) ($preview['missing_fields'] ?? []),
                'field_mapping' => (array) ($preview['field_mapping'] ?? []),
                'same_vehicle_url' => (string) ($preview['same_vehicle_url'] ?? ''),
                'same_vehicle_cta' => (array) ($preview['same_vehicle_cta'] ?? []),
                'same_vehicle_cta_visible' => !empty($preview['same_vehicle_cta']['same_vehicle_cta_visible']),
                'same_vehicle_token' => (string) ($preview['same_vehicle_cta']['same_vehicle_token'] ?? ''),
                'same_vehicle_ebay_url' => (string) ($preview['same_vehicle_cta']['same_vehicle_ebay_url'] ?? ''),
                'warnings' => (array) ($preview['warnings'] ?? []),
                'safety' => [
                    'called_ebay_api' => false,
                    'called_ovoko_api' => false,
                    'updated_ebay_listing' => false,
                    'created_ebay_listing' => false,
                    'modified_woo_product' => false,
                ],
                'html' => $html,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('EBAY_DESCRIPTION_TEMPLATE_PREVIEW_FAILED', ['input' => $identifier, 'error' => $e->getMessage()]);
            return ['result' => 'error', 'error' => $e->getMessage()];
        }
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

    private function ebay_german_content_translator(): EbayGermanContentTranslator
    {
        return new EbayGermanContentTranslator($this->logger);
    }

    private function ebay_german_content_source($product, int $productId, array $aspectsSource = []): array
    {
        $postContent = (string) get_post_field('post_content', $productId);
        $description = trim((string) $postContent);
        $descriptionSource = 'post_content';
        if (trim(wp_strip_all_tags($description)) === '' && method_exists($product, 'get_description')) {
            $description = trim((string) $product->get_description());
            $descriptionSource = 'product_get_description';
        }
        $details = $this->collect_woo_product_details_for_ebay_template($product, $productId);
        if ($aspectsSource === [] && method_exists($product, 'get_attributes')) {
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
                    $aspectsSource[$name] = $values;
                }
            }
        }
        return [
            'product_id' => $productId,
            'title' => method_exists($product, 'get_name') ? (string) $product->get_name() : '',
            'description' => $description,
            'description_source' => $descriptionSource,
            'short_description' => method_exists($product, 'get_short_description') ? (string) $product->get_short_description() : '',
            'fields' => (array) ($details['fields'] ?? []),
            'aspects_source' => $aspectsSource,
        ];
    }

    private function german_content_source_hash($product): string
    {
        $productId = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        return $this->ebay_german_content_translator()->source_hash($this->ebay_german_content_source($product, $productId));
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

                $mapping = $this->categoryRepo->resolveProductionCategoryMapping($termId, $marketplaceId);
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
        if (!empty($content['stale'])) { $errors[] = 'German eBay content is stale; regenerate/refresh eBay German content before publish.'; $status = 'stale_german_content'; }
        if (empty($content['title']) || empty($content['description'])) { $errors[] = (string) ($content['error_message'] ?? 'German title/description missing'); $status = 'not_ready_missing_german_content'; }
        if (!empty($content['title']) && mb_strlen((string) $content['title']) > 80) $errors[] = 'German title is longer than 80 characters';
        if ($categoryId === '') { $errors[] = 'Category mapping requires review'; $categoryStatus = (string) ($category['status'] ?? ''); $status = in_array($categoryStatus, ['needs_category_review', 'low_confidence_auto', 'category_sanity_failed', 'taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true) ? $categoryStatus : 'needs_category_review'; }
        $knownCategoryValidation = $this->known_category_validation($categoryId, (int) ($category['woo_term_id'] ?? $category['mapping']['woo_term_id'] ?? 0));
        if ($categoryId !== '' && !empty($knownCategoryValidation) && (($knownCategoryValidation['validation_status'] ?? '') === 'invalid_ebay_category_id' || ($knownCategoryValidation['validation_status'] ?? '') === 'non_leaf_ebay_category_id' || empty($knownCategoryValidation['valid']) || empty($knownCategoryValidation['leaf']))) {
            $errors[] = 'Known invalid/non-leaf eBay category ID for Woo category';
            $status = (string) ($knownCategoryValidation['validation_status'] ?? 'invalid_ebay_category_id');
            if ($status === 'non_leaf_ebay_category_id') {
                $status = 'needs_category_review';
            }
            $category['validation_status'] = (string) ($knownCategoryValidation['validation_status'] ?? 'invalid_ebay_category_id');
            $category['current_ebay_category_id'] = $categoryId;
            $category['woo_term_id'] = (int) ($knownCategoryValidation['woo_term_id'] ?? $category['woo_term_id'] ?? 0);
            $category['woo_category_path'] = (string) ($knownCategoryValidation['woo_category_path'] ?? $category['woo_category_path'] ?? '');
        }
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

        return ['ready' => $ready, 'status' => $ready ? 'ready' : $status, 'message' => $message, 'product_id' => $product_id, 'sku_resolution' => $skuResolution, 'content' => $content, 'category' => $category, 'price_resolution' => $priceResolution, 'shipping_policy_resolution' => $shippingPolicyResolution, 'policy_validation' => $policyValidation, 'required_aspects' => $requiredAspects, 'missing_aspects' => $missingAspects, 'aspects' => $aspects, 'errors' => $errors, 'category_validation' => $knownCategoryValidation ?? []];
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
        $resolved = $this->apply_part_number_aspect_aliases($resolved, $required, $mpnValue);
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
        if ($this->marketplace_id() === 'EBAY_DE' && empty($settings['lightweight'])) {
            $resolved = $this->ebay_german_content_translator()->translate_aspects_from_cache(
                $product_id,
                $this->ebay_german_content_source($product, $product_id),
                $resolved
            );
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
            $partNumbers = $this->normalize_part_numbers([$mpn, get_post_meta($productId, '_oem_number', true)]);
            $primaryPartNumber = (string) ($partNumbers[0] ?? $mpn);
            $aspects['MPN'] = [$primaryPartNumber];
            $aspects['Herstellernummer'] = [$primaryPartNumber];
            $aspects['Manufacturer Part Number'] = [$primaryPartNumber];
            $aspects['OE/OEM Referenznummer'] = $partNumbers !== [] ? $partNumbers : [$primaryPartNumber];
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

    public function update_description_template_single(string $productOrSku): array
    {
        $identifier = trim($productOrSku);
        if ($identifier === '') return ['result' => 'error', 'error' => 'missing_input'];
        $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_SINGLE_START', ['input' => $identifier]);
        try {
            $resolved = $this->resolve_product_by_id_or_sku($identifier);
            $productId = (int) ($resolved['product_id'] ?? 0);
            $product = $resolved['product'];
            if ($productId <= 0 || !$product) return ['result' => 'error', 'error' => 'product_not_found'];
            $settings = $this->settings();
            $sku = (string) $this->resolve_ebay_sku($product, $productId, null, $settings)['sku'];
            $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
            $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
            if ($offerId === '' || $listingId === '' || $sku === '') return ['result' => 'error', 'error' => 'missing_offer_listing_sku'];
            $content = $this->resolve_german_content($product, $productId, $this->marketplace_id(), $settings);
            $category = $this->resolve_category($product, $productId, $sku, $this->marketplace_id(), $settings);
            $aspects = $this->resolve_product_aspects($product, $productId, $sku, $settings, (string) ($category['category_id'] ?? ''), $content);
            $html = $this->build_ebay_de_description_template($product, $productId, $content, $aspects, $category);
            $htmlValidation = self::validate_ebay_de_rendered_html($html);
            if (empty($htmlValidation['valid'])) {
                return ['result' => 'error', 'error' => (string) ($htmlValidation['error'] ?? 'invalid_translated_html_css'), 'matches' => (array) ($htmlValidation['matches'] ?? [])];
            }
            $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_RENDERED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
            $inventory = $this->client->get_inventory_item($sku, ['stage' => 'description_template_single', 'product_id' => $productId, 'sku' => $sku]);
            if (is_wp_error($inventory)) return ['result' => 'error', 'error' => $inventory->get_error_message()];
            $before = (string) ($inventory['product']['description'] ?? '');
            $inventory['product']['description'] = $html;
            $changed = $before !== $html;
            if ($changed) {
                $update = $this->client->create_or_replace_inventory_item($sku, $inventory, ['stage' => 'description_template_single_update', 'product_id' => $productId, 'sku' => $sku]);
                if (is_wp_error($update)) return ['result' => 'error', 'error' => $update->get_error_message()];
                $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_CHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
                $offer = $this->client->get_offer($offerId, ['stage' => 'description_template_single_offer_refresh_get_offer', 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId]);
                if (is_wp_error($offer)) return ['result' => 'error', 'error' => $offer->get_error_message()];
                $security = ['called_create_offer' => false, 'called_publish_offer' => false, 'preserved_title' => true, 'preserved_price' => true, 'preserved_stock' => true, 'preserved_shipping' => true, 'preserved_category' => true, 'preserved_aspects' => true, 'changed_only' => 'description_template'];
                $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_OFFER_REFRESH_START', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId] + $security);
                $refresh = $this->client->update_offer($offerId, (array) $offer, ['stage' => 'description_template_single_offer_refresh_update_offer', 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId]);
                if (is_wp_error($refresh)) return ['result' => 'error', 'error' => $refresh->get_error_message()];
                $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_OFFER_REFRESH_DONE', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId] + $security);
            } else {
                $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_UNCHANGED', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId]);
            }
            $this->logger->info('EBAY_DESCRIPTION_TEMPLATE_SINGLE_DONE', ['product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId, 'changed' => $changed]);
            return ['result' => 'success', 'product_id' => $productId, 'sku' => $sku, 'offer_id' => $offerId, 'listing_id' => $listingId, 'changed' => $changed, 'html' => $html];
        } catch (\Throwable $e) {
            $this->logger->error('EBAY_DESCRIPTION_TEMPLATE_FAILED', ['input' => $identifier, 'error' => $e->getMessage()]);
            return ['result' => 'error', 'error' => $e->getMessage()];
        }
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

    private function apply_part_number_aspect_aliases(array $aspects, array $requiredAspects, $resolvedPartNumber): array
    {
        if ($this->marketplace_id() !== 'EBAY_DE') {
            return $aspects;
        }

        $partNumbers = $this->normalize_part_numbers($resolvedPartNumber);
        $partNumber = (string) ($partNumbers[0] ?? '');
        foreach ($this->part_number_aspect_aliases() as $alias) {
            if ($partNumber === '' && !empty($aspects[$alias][0])) {
                $partNumber = (string) ($this->normalize_part_numbers($aspects[$alias])[0] ?? '');
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

    private function normalize_part_numbers($value): array
    {
        $source = is_array($value) ? $value : [$value];
        $queue = $source;
        $normalized = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (is_array($current)) {
                foreach ($current as $nested) {
                    $queue[] = $nested;
                }
                continue;
            }
            $candidate = $this->normalize_part_number_value(trim((string) $current));
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }
            $normalized[] = $candidate;
        }
        return $normalized;
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

        $normalizedFallback = strtolower($fallback);
        if ($errorId === '25005' || str_contains($normalizedFallback, 'invalid category') || str_contains($normalizedFallback, 'kategorie ist nicht gültig')) {
            return 'Invalid category ID. Update Woo → eBay category mapping for this Woo category and rerun export.';
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
        if (!isset($settings['ebay_seller_username'])) {
            $settings['ebay_seller_username'] = '';
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
