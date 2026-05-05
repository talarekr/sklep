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

        $itemPayload = [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => 'NEW',
            'product' => [
                'title' => $product->get_name(),
                'description' => (string) $product->get_description(),
                'aspects' => ['MPN' => [(string) get_post_meta($product_id, '_part_number', true)]],
                'imageUrls' => array_values(array_filter(array_map('wp_get_attachment_url', array_merge([$product->get_image_id()], $product->get_gallery_image_ids())))),
            ],
        ];
        $marketplaceId = $this->marketplace_id();

        $item = $this->client->create_or_replace_inventory_item($sku, $itemPayload, [
            'stage' => 'createOrReplaceInventoryItem',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
        ]);
        if (is_wp_error($item)) return $this->export_error_response('createOrReplaceInventoryItem', $item, $product_id, $sku);

        $settings = $this->settings();
        $defaultCategoryId = trim((string) ($settings['default_category_id'] ?? ''));
        if ($defaultCategoryId === '') {
            return ['result' => 'error', 'message' => 'Missing eBay category ID'];
        }

        $priceValue = (float) $product->get_price();
        $priceCurrency = $this->offer_currency($marketplaceId);
        if ($marketplaceId === 'EBAY_DE') {
            // TODO: replace fixed 4.25 with dynamic NBP exchange rate
            $priceValue = round($priceValue / 4.25, 2);
        }

        $offerPayload = [
            'sku' => $sku,
            'marketplaceId' => $marketplaceId,
            'merchantLocationKey' => $this->merchant_location_key(),
            'categoryId' => $defaultCategoryId,
            'fulfillmentPolicyId' => (string) ($settings['ebay_fulfillment_policy_id'] ?? ''),
            'paymentPolicyId' => (string) ($settings['ebay_payment_policy_id'] ?? ''),
            'returnPolicyId' => (string) ($settings['ebay_return_policy_id'] ?? ''),
            'format' => 'FIXED_PRICE',
            'availableQuantity' => max(0, (int) $product->get_stock_quantity()),
            'listingDuration' => 'GTC',
            'pricingSummary' => ['price' => ['value' => (string) $priceValue, 'currency' => $priceCurrency]],
        ];
        $mapping = $this->repo->find_by_product($product_id, $variation_id);
        $offer_id = trim((string) ($mapping['remote_offer_id'] ?? ''));

        if ($offer_id === '') {
            $offer = $this->client->create_offer($offerPayload, [
                'stage' => 'createOffer',
                'product_id' => $product_id,
                'sku' => $sku,
                'marketplace_id' => $marketplaceId,
            ]);

            if (is_wp_error($offer)) {
                $error_data = $offer->get_error_data();
                $response_body = is_array($error_data) && isset($error_data['response_body']) && is_array($error_data['response_body'])
                    ? $error_data['response_body']
                    : [];

                $existing_offer_id = trim((string) ($response_body['offerId'] ?? ''));
                $error_message = strtolower((string) ($response_body['message'] ?? ''));
                $is_offer_exists = $existing_offer_id !== '' && str_contains($error_message, 'offer entity already exists');

                if (!$is_offer_exists) {
                    return $this->export_error_response('createOffer', $offer, $product_id, $sku);
                }

                $offer_id = $existing_offer_id;
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

        $updated = $this->client->update_offer($offer_id, $offerPayload, [
            'stage' => 'updateOffer',
            'product_id' => $product_id,
            'sku' => $sku,
            'marketplace_id' => $marketplaceId,
        ]);
        if (is_wp_error($updated)) return $this->export_error_response('updateOffer', $updated, $product_id, $sku);

        $published = $this->client->publish_offer($offer_id, [
            'stage' => 'publishOffer',
            'product_id' => $product_id,
            'sku' => $sku,
        ]);
        if (is_wp_error($published)) return $this->export_error_response('publishOffer', $published, $product_id, $sku);

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

    public function upsert_business_policies(): array
    {
        $settings = $this->settings();
        $marketplaceId = $this->marketplace_id();

        $result = [];
        $result['ebay_fulfillment_policy_id'] = $this->upsert_policy_by_name('fulfillment_policy', (string) ($settings['fulfillment_policy_name'] ?? ''), $marketplaceId);
        $result['ebay_payment_policy_id'] = $this->upsert_policy_by_name('payment_policy', (string) ($settings['payment_policy_name'] ?? ''), $marketplaceId);
        $result['ebay_return_policy_id'] = $this->upsert_policy_by_name('return_policy', (string) ($settings['return_policy_name'] ?? ''), $marketplaceId);

        foreach ($result as $key => $value) {
            if (is_wp_error($value)) {
                return ['result' => 'error', 'error' => $value->get_error_message(), 'error_details' => $value->get_error_data()];
            }
        }

        $settings['ebay_fulfillment_policy_id'] = (string) $result['ebay_fulfillment_policy_id'];
        $settings['ebay_payment_policy_id'] = (string) $result['ebay_payment_policy_id'];
        $settings['ebay_return_policy_id'] = (string) $result['ebay_return_policy_id'];
        update_option(Plugin::OPTION_KEY, $settings, false);

        return ['result' => 'success'] + $result;
    }

    private function export_error_response(string $stage, \WP_Error $error, int $product_id, string $sku): array
    {
        $error_data = $error->get_error_data();
        $context = is_array($error_data) ? $error_data : ['raw_error_data' => $error_data];
        $context['stage'] = $context['stage'] ?? $stage;
        $context['product_id'] = $context['product_id'] ?? $product_id;
        $context['sku'] = $context['sku'] ?? $sku;

        $this->logger->error('Product export failed', $context + ['error' => $error->get_error_message()]);

        return [
            'result' => 'error',
            'error' => $error->get_error_message(),
            'error_details' => $context,
        ];
    }

    private function upsert_policy_by_name(string $type, string $policyName, string $marketplaceId)
    {
        if ($policyName === '') {
            return new \WP_Error('wei_policy_name_missing', 'Policy name is required', ['type' => $type]);
        }

        $existingRes = $this->client->get_policies($type, $marketplaceId);
        if (is_wp_error($existingRes)) {
            return $existingRes;
        }

        $collectionMap = [
            'fulfillment_policy' => 'fulfillmentPolicies',
            'payment_policy' => 'paymentPolicies',
            'return_policy' => 'returnPolicies',
        ];
        $idFieldMap = [
            'fulfillment_policy' => 'fulfillmentPolicyId',
            'payment_policy' => 'paymentPolicyId',
            'return_policy' => 'returnPolicyId',
        ];

        $policies = is_array($existingRes[$collectionMap[$type]] ?? null) ? $existingRes[$collectionMap[$type]] : [];
        $existingId = '';
        foreach ($policies as $policy) {
            if ((string) ($policy['name'] ?? '') === $policyName) {
                $existingId = (string) ($policy[$idFieldMap[$type]] ?? '');
                break;
            }
        }

        $payload = ['name' => $policyName, 'description' => $policyName, 'marketplaceId' => $marketplaceId];
        if ($type === 'fulfillment_policy') {
            $payload += [
                'categoryTypes' => [['name' => 'ALL_EXCLUDING_MOTORS_VEHICLES']],
                'handlingTime' => ['unit' => 'DAY', 'value' => 1],
                'globalShipping' => false,
                'shippingOptions' => [[
                    'optionType' => 'DOMESTIC',
                    'costType' => 'FLAT_RATE',
                    'shippingServices' => [[
                        'sortOrder' => 1,
                        'shippingCarrierCode' => 'DHL',
                        'shippingServiceCode' => 'DE_DHLPaket',
                        'shippingCost' => ['currency' => 'EUR', 'value' => '9.99'],
                    ]],
                ]],
            ];
        } elseif ($type === 'payment_policy') {
            // eBay Managed Payments: do not set payment methods manually.
            $payload = [
                'name' => $policyName,
                'marketplaceId' => $marketplaceId,
                'categoryTypes' => [['name' => 'ALL_EXCLUDING_MOTORS_VEHICLES']],
                'immediatePay' => true,
            ];
        } else {
            $payload += ['categoryTypes' => [['name' => 'ALL_EXCLUDING_MOTORS_VEHICLES']], 'returnsAccepted' => true, 'returnPeriod' => ['unit' => 'DAY', 'value' => 30], 'returnMethod' => 'REPLACEMENT', 'returnShippingCostPayer' => 'BUYER'];
        }

        if ($existingId !== '') {
            $updated = $this->upsert_policy_request($type, $payload, $marketplaceId, $existingId);
            if (is_wp_error($updated)) return $updated;
            return $existingId;
        }

        $created = $this->upsert_policy_request($type, $payload, $marketplaceId);
        if (is_wp_error($created)) return $created;
        return (string) ($created[$idFieldMap[$type]] ?? '');
    }


    private function upsert_policy_request(string $type, array $payload, string $marketplaceId, string $existingId = '')
    {
        $method = $existingId !== '' ? 'update_' . $type : 'create_' . $type;
        $response = $existingId !== ''
            ? $this->client->{$method}($existingId, $payload)
            : $this->client->{$method}($payload);

        if (!is_wp_error($response) || $type !== 'fulfillment_policy' || $marketplaceId !== 'EBAY_DE') {
            return $response;
        }

        $fallbackPayload = $payload;
        if (isset($fallbackPayload['shippingOptions'][0]['shippingServices'][0])) {
            $fallbackPayload['shippingOptions'][0]['shippingServices'][0]['shippingCarrierCode'] = 'OTHER';
            $fallbackPayload['shippingOptions'][0]['shippingServices'][0]['shippingServiceCode'] = 'DE_StandardDispatch';
        }

        $this->logger->warning('Primary fulfillment shipping service failed, retrying EBAY_DE fallback', [
            'marketplaceId' => $marketplaceId,
            'error' => $response->get_error_message(),
            'error_data' => $response->get_error_data(),
            'payload' => $payload,
            'fallback_payload' => $fallbackPayload,
        ]);

        return $existingId !== ''
            ? $this->client->{$method}($existingId, $fallbackPayload)
            : $this->client->{$method}($fallbackPayload);
    }

    private function settings(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        return is_array($settings) ? $settings : [];
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
