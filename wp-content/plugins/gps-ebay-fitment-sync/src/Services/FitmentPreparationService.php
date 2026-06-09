<?php

namespace GPSEbayFitmentSync\Services;

use GPSEbayFitmentSync\Adapters\WeiMarketplaceMappingAdapter;
use GPSEbayFitmentSync\DTO\ListingContext;
use GPSEbayFitmentSync\Interfaces\TecDocLookupServiceInterface;
use GPSEbayFitmentSync\Repositories\FitmentSyncRepository;
use GPSEbayFitmentSync\Repositories\KTypeRepository;

final class FitmentPreparationService
{
    private $registry;
    private $adapter;
    private $repository;
    private $resolver;
    private $ktypeRepository;
    private $tecdoc;

    public function __construct(MarketplaceRegistry $registry, WeiMarketplaceMappingAdapter $adapter, FitmentSyncRepository $repository, OemPartNumberResolver $resolver, KTypeRepository $ktypeRepository, TecDocLookupServiceInterface $tecdoc)
    {
        $this->registry = $registry;
        $this->adapter = $adapter;
        $this->repository = $repository;
        $this->resolver = $resolver;
        $this->ktypeRepository = $ktypeRepository;
        $this->tecdoc = $tecdoc;
    }

    public function prepare_batch(array $args = []): array
    {
        $productIds = $this->adapter->discover_product_ids($args);
        $summary = ['scanned_products' => count($productIds), 'rows_prepared' => 0, 'dry_run' => !empty($args['dry_run']), 'rows' => []];
        foreach ($productIds as $productId) {
            foreach ($this->prepare_product((int) $productId, $args) as $row) {
                $summary['rows'][] = $row;
                $summary['rows_prepared']++;
            }
        }
        return $summary;
    }

    public function prepare_product(int $productId, array $args = []): array
    {
        $marketplace = (string) ($args['marketplace'] ?? 'all');
        $dryRun = !empty($args['dry_run']);
        $contexts = $this->adapter->contexts_for_product($productId, $marketplace);
        $oem = $this->resolver->resolve($productId);
        $ktype = $this->ktypeRepository->read_for_product($productId);
        $rows = [];

        foreach ($contexts as $context) {
            try {
                $status = $this->determine_status($context, $oem, $ktype);
                $hash = '';
                if ((int) ($ktype['ktype_count'] ?? 0) > 0 && trim((string) $context->inventory_item_sku) !== '') {
                    $hash = hash('sha256', wp_json_encode([$context->marketplace, $context->inventory_item_sku, $ktype['ktype_list']]));
                }
                $row = [
                    'product_id' => $productId,
                    'marketplace' => (string) $context->marketplace,
                    'plugin_key' => (string) $context->plugin_key,
                    'mapping_source' => (string) $context->mapping_source,
                    'listing_id' => (string) $context->listing_id,
                    'offer_id' => (string) $context->offer_id,
                    'inventory_item_sku' => (string) $context->inventory_item_sku,
                    'ebay_category_id' => (string) $context->ebay_category_id,
                    'compatibility_mode' => 'ktype',
                    'fitment_status' => $status,
                    'oem_value' => (string) ($oem['value'] ?: ($ktype['oem_value'] ?? '')),
                    'oem_source' => (string) ($oem['source'] ?: ($ktype['oem_source'] ?? '')),
                    'ktype_count' => (int) ($ktype['ktype_count'] ?? 0),
                    'request_hash' => $hash,
                    'last_lookup_at' => $ktype['last_checked_at'] ?: null,
                    'last_checked_at' => current_time('mysql'),
                    'last_error' => (string) ($ktype['last_error'] ?? ''),
                    'raw_response_excerpt' => wp_json_encode([
                        'listing_context' => $context->raw,
                        'oem_candidates' => $oem['candidates'] ?? [],
                        'ktype_status' => $ktype['status'] ?? '',
                        'ktype_confidence' => $ktype['confidence'] ?? '',
                        'tecdoc_service' => 'stubbed_not_called_for_live_api',
                    ]),
                ];
                if (!$dryRun) {
                    $row['id'] = $this->repository->upsert($row);
                } else {
                    $row['id'] = 0;
                }
                $rows[] = $row;
            } catch (\Throwable $e) {
                $row = [
                    'product_id' => $productId,
                    'marketplace' => (string) $context->marketplace,
                    'plugin_key' => (string) $context->plugin_key,
                    'fitment_status' => 'error',
                    'last_error' => $e->getMessage(),
                    'last_checked_at' => current_time('mysql'),
                ];
                if (!$dryRun) {
                    $row['id'] = $this->repository->upsert($row);
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function determine_status(ListingContext $context, array $oem, array $ktype): string
    {
        $config = $this->registry->get((string) $context->marketplace);
        if (!$config || empty($config['enabled'])) {
            return 'skipped';
        }
        if (empty($oem['found']) && trim((string) ($ktype['oem_value'] ?? '')) === '') {
            return 'missing_oem';
        }
        $count = (int) ($ktype['ktype_count'] ?? 0);
        $confidence = (string) ($ktype['confidence'] ?? '');
        $ktypeStatus = (string) ($ktype['status'] ?? '');
        if ($count <= 0) {
            return 'missing_ktype';
        }
        if (in_array($ktypeStatus, ['needs_review', 'too_many_matches', 'error'], true) || $confidence === 'low') {
            return 'needs_review';
        }
        if (!$context->has_listing_identifier()) {
            return 'missing_listing';
        }
        if (trim((string) $context->ebay_category_id) === '') {
            return 'missing_category';
        }
        return 'ready';
    }
}
