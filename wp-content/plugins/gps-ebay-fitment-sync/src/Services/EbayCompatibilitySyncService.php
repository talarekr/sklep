<?php

namespace GPSEbayFitmentSync\Services;

final class EbayCompatibilitySyncService
{
    private $registry;

    public function __construct(MarketplaceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function build_payload(array $ktypeList): array
    {
        $compatible = [];
        foreach ($ktypeList as $ktype) {
            $ktype = trim((string) $ktype);
            if ($ktype !== '' && ctype_digit($ktype)) {
                $compatible[] = ['productIdentifier' => ['ktype' => $ktype]];
            }
        }
        return ['compatibleProducts' => $compatible];
    }

    public function compute_request_hash(string $marketplace, string $inventorySku, array $ktypeList): string
    {
        $payload = $this->build_payload($ktypeList);
        return hash('sha256', wp_json_encode([$marketplace, $inventorySku, $payload]));
    }

    public function create_or_replace_product_compatibility_dry_run(string $marketplace, string $inventorySku, array $ktypeList): array
    {
        return [
            'dry_run' => true,
            'http_method' => 'PUT',
            'endpoint' => '/sell/inventory/v1/inventory_item/' . rawurlencode($inventorySku) . '/product_compatibility',
            'marketplace' => $marketplace,
            'request_hash' => $this->compute_request_hash($marketplace, $inventorySku, $ktypeList),
            'payload' => $this->build_payload($ktypeList),
            'message' => 'No HTTP request is sent by this phase.',
        ];
    }
}
