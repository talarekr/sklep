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
    private $diagnosticResolver;
    private $ktypeRepository;
    private $tecdoc;

    public function __construct(MarketplaceRegistry $registry, WeiMarketplaceMappingAdapter $adapter, FitmentSyncRepository $repository, OemPartNumberResolver $resolver, ProductDiagnosticContextResolver $diagnosticResolver, KTypeRepository $ktypeRepository, TecDocLookupServiceInterface $tecdoc)
    {
        $this->registry = $registry;
        $this->adapter = $adapter;
        $this->repository = $repository;
        $this->resolver = $resolver;
        $this->diagnosticResolver = $diagnosticResolver;
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
        $partNumber = $this->resolver->resolve($productId);
        $diagnosticContext = $this->diagnosticResolver->resolve($productId, $partNumber);
        $ktype = $this->ktypeRepository->read_for_product($productId);
        $rows = [];

        foreach ($contexts as $context) {
            try {
                $status = $this->determine_status($context, $partNumber, $ktype);
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
                    'oem_value' => (string) ($partNumber['primary_part_number'] ?? ''),
                    'oem_source' => (string) ($partNumber['primary_part_number_source'] ?? ''),
                    'ktype_count' => (int) ($ktype['ktype_count'] ?? 0),
                    'request_hash' => $hash,
                    'last_lookup_at' => $ktype['last_checked_at'] ?: null,
                    'last_checked_at' => current_time('mysql'),
                    'last_error' => (string) ($ktype['last_error'] ?? ''),
                    'raw_response_excerpt' => wp_json_encode([
                        'primary_part_number' => (string) ($partNumber['primary_part_number'] ?? ''),
                        'primary_part_number_source' => (string) ($partNumber['primary_part_number_source'] ?? ''),
                        'primary_part_number_confidence' => (string) ($partNumber['primary_part_number_confidence'] ?? ''),
                        'part_number_candidates' => $partNumber['part_number_candidates'] ?? [],
                        'diagnostic_context' => $diagnosticContext,
                        'ktype_status' => $ktype['status'] ?? '',
                        'ktype_confidence' => $ktype['confidence'] ?? '',
                        'tecdoc_service' => [
                            'status' => 'stubbed_not_called_for_live_api',
                            'future_lookup_input' => ['primary_part_number'],
                            'diagnostic_context_used_as_lookup_input' => false,
                            'planned_diagnostic_statuses' => ['ready', 'needs_review', 'context_mismatch', 'too_many_matches', 'no_tecdoc_match'],
                        ],
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

    private function determine_status(ListingContext $context, array $partNumber, array $ktype): string
    {
        $config = $this->registry->get((string) $context->marketplace);
        if (!$config || empty($config['enabled'])) {
            return 'skipped';
        }
        if (empty($partNumber['found'])) {
            return 'missing_part_number';
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
