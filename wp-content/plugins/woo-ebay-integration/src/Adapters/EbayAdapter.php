<?php

namespace WEI\Adapters;

use WEI\Plugin;

use WEI\Interfaces\MarketplaceAdapterInterface;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayClient;
use WEI\Services\Logger;

class EbayAdapter implements MarketplaceAdapterInterface
{
    public function __construct(private EbayClient $client, private MappingRepository $repo, private Logger $logger)
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

        $sku = (string) $product->get_sku();
        if ($sku === '') return ['result' => 'error', 'error' => 'missing_sku'];

        $marketplaceId = $this->marketplace_id();
        $settings = $this->settings();
        $aspects = $this->resolve_product_aspects($product, $product_id, $sku, $settings);
        $itemPayload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => 'NEW',
            'product' => [
                'title' => $product->get_name(),
                'description' => (string) $product->get_description(),
                'imageUrls' => array_values(array_filter(array_map('wp_get_attachment_url', array_merge([$product->get_image_id()], $product->get_gallery_image_ids())))),
            ],
        ];
        if ($aspects !== []) {
            $itemPayload['product']['aspects'] = $aspects;
        }
        $categoryId = $this->resolve_category_id($product_id, $sku, $settings);
        if ($categoryId === '') {
            return ['result' => 'error', 'message' => 'Missing eBay category ID'];
        }

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

        return ['result' => 'success', 'offer_id' => $offer_id, 'listing_id' => $listing_id, 'inventory_id' => $sku];
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


    private function resolve_product_aspects($product, int $product_id, string $sku, array $settings): array
    {
        $aspects = [];

        $partNumber = trim((string) get_post_meta($product_id, '_part_number', true));
        if ($partNumber !== '') {
            $aspects['MPN'] = [$partNumber];
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

        $manufacturer = $this->resolve_manufacturer_aspect_value($product, $product_id);
        if ($manufacturer !== '') {
            $aspects['Hersteller'] = [$manufacturer];
        }

        $productOverrides = $this->parse_aspects_json((string) get_post_meta($product_id, '_wei_ebay_aspects_json', true));
        $settingsOverrides = $this->resolve_configured_aspect_overrides($sku, $settings);

        return $this->merge_aspects($aspects, $productOverrides, $settingsOverrides);
    }

    private function resolve_manufacturer_aspect_value($product, int $product_id): string
    {
        foreach (['_wei_ebay_hersteller', '_ebay_hersteller', '_manufacturer', 'manufacturer', '_brand', 'brand'] as $metaKey) {
            $value = trim((string) get_post_meta($product_id, $metaKey, true));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['Hersteller', 'Manufacturer', 'Producent', 'Marke', 'Brand'] as $attributeName) {
            if (!method_exists($product, 'get_attribute')) {
                continue;
            }

            $value = trim(wp_strip_all_tags((string) $product->get_attribute($attributeName)));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
            $settings['sku_category_overrides'] = "CFM-001=33665";
        }
        if (!isset($settings['sku_aspect_overrides'])) {
            $settings['sku_aspect_overrides'] = wp_json_encode([
                'CFM-001' => [
                    'Hersteller' => ['SEAT'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
        $sku = (string) $product->get_sku();
        $map = $this->repo->find_by_sku($sku);
        if (!$map) return ['result' => 'skipped', 'reason' => 'mapping_not_found'];

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
