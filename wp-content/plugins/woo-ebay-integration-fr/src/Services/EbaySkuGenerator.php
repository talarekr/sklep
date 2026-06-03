<?php

namespace WEI_FR\Services;

use WEI_FR\Plugin;

class EbaySkuGenerator
{
    private const BATCH_SIZE = 200;
    private const RUN_OPTION = 'wei_fr_ebay_sku_generation_run';
    private const LAST_RUN_OPTION = 'wei_fr_ebay_sku_generation_last_run';

    public function __construct(private Logger $logger)
    {
    }

    public function generate_missing_batch(?string $runId = null, int $batchSize = self::BATCH_SIZE): array
    {
        $batchSize = max(1, min(500, $batchSize));
        $run = $this->current_run($runId);
        if (!$run) {
            $run = $this->new_run();
        }

        $runId = (string) $run['run_id'];
        $ids = $this->eligible_product_ids_missing_ebay_sku($batchSize);
        $summary = [
            'run_id' => $runId,
            'processed' => 0,
            'generated' => 0,
            'skipped_existing' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'wrote_woo_sku' => false,
        ];

        foreach ($ids as $productId) {
            $result = $this->generate_for_product($productId, $runId);
            $summary['processed']++;
            $summary[$result] = (int) ($summary[$result] ?? 0) + 1;
            if ($result === 'conflicts') {
                $summary['generated']++;
            }
        }

        $remaining = $this->count_eligible_missing_ebay_sku();
        $totals = $this->merge_run_totals((array) ($run['totals'] ?? []), $summary);
        $run = array_merge($run, [
            'last_batch_at' => gmdate('Y-m-d H:i:s'),
            'remaining_missing' => $remaining,
            'has_more' => $remaining > 0,
            'totals' => $totals,
        ]);

        if ($remaining > 0) {
            update_option(self::RUN_OPTION, $run, false);
        } else {
            $run['finished_at'] = gmdate('Y-m-d H:i:s');
            update_option(self::LAST_RUN_OPTION, $run, false);
            delete_option(self::RUN_OPTION);
        }

        $this->logger->info('Generate missing eBay-only SKUs batch finished', [
            'run_id' => $runId,
            'processed' => $summary['processed'],
            'generated' => $summary['generated'],
            'skipped_existing' => $summary['skipped_existing'],
            'conflicts' => $summary['conflicts'],
            'errors' => $summary['errors'],
            'remaining_missing' => $remaining,
            'wrote_woo_sku' => false,
        ]);

        return array_merge($summary, ['remaining_missing' => $remaining, 'has_more' => $remaining > 0, 'totals' => $totals]);
    }

    public function current_status(): array
    {
        $active = get_option(self::RUN_OPTION, []);
        $last = get_option(self::LAST_RUN_OPTION, []);

        return [
            'active_run' => is_array($active) ? $active : [],
            'last_run' => is_array($last) ? $last : [],
        ];
    }

    public function status_counts(): array
    {
        $eligibleTotal = $this->count_eligible_products();
        $withSku = $this->count_eligible_with_ebay_sku();
        $generatedTotal = $this->count_eligible_generated_ebay_sku();
        $lastRun = $this->current_status()['last_run'];
        $lastTotals = is_array($lastRun['totals'] ?? null) ? $lastRun['totals'] : [];

        return [
            'products_total_eligible' => $eligibleTotal,
            'products_with_wei_fr_ebay_sku' => $withSku,
            'products_missing_wei_fr_ebay_sku' => max(0, $eligibleTotal - $withSku),
            'products_with_generated_ebay_sku' => $generatedTotal,
            'generated_in_last_run' => (int) ($lastTotals['generated'] ?? 0),
            'skipped_existing_in_last_run' => (int) ($lastTotals['skipped_existing'] ?? 0),
            'conflicts_in_last_run' => (int) ($lastTotals['conflicts'] ?? 0),
            'errors_in_last_run' => (int) ($lastTotals['errors'] ?? 0),
        ];
    }

    public function ensure_product_ebay_sku(int $productId, ?int $variationId = null, array $settings = []): array
    {
        $metaProductId = $variationId ?: $productId;
        $existing = trim((string) get_post_meta($metaProductId, '_wei_fr_ebay_sku', true));
        if ($existing !== '') {
            return ['sku' => $this->sanitize_ebay_sku($existing), 'generated' => false, 'conflict' => false, 'wrote_woo_sku' => false];
        }

        $runId = 'on_demand_' . gmdate('YmdHis');
        $base = $this->generated_ebay_sku($productId, $variationId, $settings);
        $candidate = $this->unique_generated_sku($productId, $variationId, $settings, $runId);
        $saved = update_post_meta($metaProductId, '_wei_fr_ebay_sku', $candidate);
        update_post_meta($metaProductId, '_wei_fr_ebay_sku_generated', 1);
        update_post_meta($metaProductId, '_wei_fr_ebay_sku_generated_at', gmdate('Y-m-d H:i:s'));

        return ['sku' => $candidate, 'generated' => $saved !== false, 'conflict' => $candidate !== $base, 'wrote_woo_sku' => false];
    }

    private function generate_for_product(int $productId, string $runId): string
    {
        try {
            $existing = trim((string) get_post_meta($productId, '_wei_fr_ebay_sku', true));
            if ($existing !== '') {
                return 'skipped_existing';
            }

            $product = wc_get_product($productId);
            if (!$product || !$this->is_eligible_product($product)) {
                return 'errors';
            }

            $settings = $this->settings();
            $variationId = (string) $product->get_type() === 'variation' ? $productId : null;
            $parentId = $variationId && method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $baseProductId = $parentId > 0 ? $parentId : $productId;
            $sku = $this->unique_generated_sku($baseProductId, $variationId, $settings, $runId);
            $base = $this->generated_ebay_sku($baseProductId, $variationId, $settings);
            update_post_meta($productId, '_wei_fr_ebay_sku', $sku);
            update_post_meta($productId, '_wei_fr_ebay_sku_generated', 1);
            update_post_meta($productId, '_wei_fr_ebay_sku_generated_at', gmdate('Y-m-d H:i:s'));

            return $sku !== $base ? 'conflicts' : 'generated';
        } catch (\Throwable $e) {
            $this->logger->error('Generate eBay-only SKU failed', ['run_id' => $runId, 'product_id' => $productId, 'error' => $e->getMessage(), 'wrote_woo_sku' => false]);
            return 'errors';
        }
    }

    private function unique_generated_sku(int $productId, ?int $variationId, array $settings, string $runId): string
    {
        $metaProductId = $variationId ?: $productId;
        $base = $this->generated_ebay_sku($productId, $variationId, $settings);
        $conflictProductId = $this->find_product_id_by_ebay_sku($base, $metaProductId);
        if ($conflictProductId <= 0) {
            return $base;
        }

        $hash = $this->short_hash($metaProductId);
        $safe = $this->sanitize_ebay_sku($base . '-' . $hash);
        $attempt = 2;
        while ($this->find_product_id_by_ebay_sku($safe, $metaProductId) > 0 && $attempt <= 10) {
            $safe = $this->sanitize_ebay_sku($base . '-' . $hash . '-' . $attempt);
            $attempt++;
        }
        $this->logger->warning('Generated eBay-only SKU conflict detected; using safe variant', [
            'run_id' => $runId,
            'product_id' => $productId,
            'variation_id' => $variationId,
            'base_sku' => $base,
            'safe_sku' => $safe,
            'conflict_product_id' => $conflictProductId,
            'wrote_woo_sku' => false,
        ]);

        return $safe;
    }

    private function eligible_product_ids_missing_ebay_sku(int $limit): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ('publish', 'private')
               AND (m.meta_id IS NULL OR m.meta_value = '')
             ORDER BY p.ID ASC
             ",
            '_wei_fr_ebay_sku'
        ));

        $eligible = [];
        foreach (array_map('intval', (array) $ids) as $id) {
            $product = wc_get_product($id);
            if ($product && $this->is_eligible_product($product)) {
                $eligible[] = $id;
                if (count($eligible) >= $limit) {
                    break;
                }
            }
        }

        return $eligible;
    }

    private function is_eligible_product($product): bool
    {
        if (!$product || !method_exists($product, 'get_type')) {
            return false;
        }

        if (!in_array((string) $product->get_type(), ['simple', 'variation'], true)) {
            return false;
        }

        return (float) $product->get_price() > 0 && (int) $product->get_stock_quantity() >= 0;
    }

    private function count_eligible_products(): int
    {
        return $this->count_eligible(false, false);
    }

    private function count_eligible_with_ebay_sku(): int
    {
        return $this->count_eligible(true, false);
    }

    private function count_eligible_generated_ebay_sku(): int
    {
        return $this->count_eligible(false, true);
    }

    private function count_eligible_missing_ebay_sku(): int
    {
        return max(0, $this->count_eligible_products() - $this->count_eligible_with_ebay_sku());
    }

    private function count_eligible(bool $mustHaveSku, bool $mustBeGenerated): int
    {
        global $wpdb;

        $joinSku = $mustHaveSku ? "INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_wei_fr_ebay_sku' AND sku.meta_value <> ''" : '';
        $joinGenerated = $mustBeGenerated ? "INNER JOIN {$wpdb->postmeta} gen ON gen.post_id = p.ID AND gen.meta_key = '_wei_fr_ebay_sku_generated' AND gen.meta_value = '1'" : '';
        $ids = $wpdb->get_col(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             {$joinSku}
             {$joinGenerated}
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ('publish', 'private')"
        );

        $count = 0;
        foreach (array_map('intval', (array) $ids) as $id) {
            $product = wc_get_product($id);
            if ($product && $this->is_eligible_product($product)) {
                $count++;
            }
        }

        return $count;
    }

    private function find_product_id_by_ebay_sku(string $sku, int $excludeProductId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE m.meta_key = %s
               AND m.meta_value = %s
               AND p.ID <> %d
               AND p.post_type IN ('product', 'product_variation')
             LIMIT 1",
            '_wei_fr_ebay_sku',
            $sku,
            $excludeProductId
        ));
    }

    private function generated_ebay_sku(int $productId, ?int $variationId, array $settings): string
    {
        $prefix = $this->sanitize_ebay_sku((string) ($settings['ebay_sku_prefix'] ?? 'GPSW'));
        if ($prefix === '') {
            $prefix = 'GPSW';
        }

        return $variationId ? $this->sanitize_ebay_sku($prefix . '-' . $productId . '-' . $variationId) : $this->sanitize_ebay_sku($prefix . '-' . $productId);
    }

    private function sanitize_ebay_sku(string $sku): string
    {
        $sku = trim($sku);
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $sku) ?: '';
        $sku = trim($sku, '-_.');
        if (strlen($sku) > 50) {
            $sku = rtrim(substr($sku, 0, 50), '-_.');
        }

        return $sku;
    }

    private function short_hash(int $productId): string
    {
        return substr(hash('sha256', (string) $productId), 0, 8);
    }

    private function settings(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        return is_array($settings) ? $settings : [];
    }

    private function new_run(): array
    {
        return [
            'run_id' => 'wei_fr_sku_' . gmdate('YmdHis') . '_' . wp_generate_password(6, false, false),
            'started_at' => gmdate('Y-m-d H:i:s'),
            'totals' => ['processed' => 0, 'generated' => 0, 'skipped_existing' => 0, 'conflicts' => 0, 'errors' => 0, 'wrote_woo_sku' => false],
        ];
    }

    private function current_run(?string $runId): ?array
    {
        $run = get_option(self::RUN_OPTION, []);
        if (!is_array($run) || $run === []) {
            return null;
        }

        if ($runId !== null && (string) ($run['run_id'] ?? '') !== $runId) {
            return null;
        }

        return $run;
    }

    private function merge_run_totals(array $totals, array $summary): array
    {
        foreach (['processed', 'generated', 'skipped_existing', 'conflicts', 'errors'] as $key) {
            $totals[$key] = (int) ($totals[$key] ?? 0) + (int) ($summary[$key] ?? 0);
        }
        $totals['wrote_woo_sku'] = false;

        return $totals;
    }
}
