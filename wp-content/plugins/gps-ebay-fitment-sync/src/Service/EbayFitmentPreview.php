<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class EbayFitmentPreview
{
    public const DE_LISTING_META = '_wei_ebay_listing_id';
    public const DE_ITEM_META = '_wei_ebay_item_id';
    public const DE_STATUS_META = '_wei_ebay_listing_status';
    public const DE_OFFER_META = '_wei_ebay_offer_id';
    public const DE_INVENTORY_ITEM_META = '_wei_ebay_inventory_item_id';
    public const DE_INVENTORY_ID_META = '_wei_ebay_inventory_id';
    public const FR_LISTING_META = '_wei_fr_ebay_listing_id';
    public const FR_ITEM_META = '_wei_fr_ebay_item_id';
    public const FR_STATUS_META = '_wei_fr_ebay_listing_status';
    public const FR_OFFER_META = '_wei_fr_ebay_offer_id';
    public const FR_INVENTORY_ITEM_META = '_wei_fr_ebay_inventory_item_id';
    public const FR_INVENTORY_ID_META = '_wei_fr_ebay_inventory_id';

    /** @var string[] */
    private const CSV_COLUMNS = ['product_id','product_title','sku','part_number_normalized','ktype_count','vehicle_ids','ebay_de_item_id','ebay_de_status','ebay_de_listing_management_type','ebay_de_inventory_item_sku','ebay_de_offer_id','ebay_de_inventory_marketplace','ebay_de_inventory_endpoint','ebay_de_would_update_inventory_fitment','ebay_de_blocked_reason_inventory','ebay_de_inventory_payload_summary','ebay_fr_item_id','ebay_fr_status','ebay_fr_listing_management_type','ebay_fr_inventory_item_sku','ebay_fr_offer_id','ebay_fr_inventory_marketplace','ebay_fr_inventory_endpoint','ebay_fr_would_update_inventory_fitment','ebay_fr_blocked_reason_inventory','ebay_fr_inventory_payload_summary','would_update_de','would_update_fr','blocked_reason_de','blocked_reason_fr','blocked_reason','live_checked_revisable_de','live_checked_revisable_fr','local_active_but_live_ended_de','local_active_but_live_ended_fr'];

    public function query(array $args = []): array
    {
        global $wpdb;
        $limit = max(1, min(1000, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $filters = $this->filters($args);
        $rows = $this->base_rows($limit, $offset, $filters);
        $rows = array_map(fn(array $row): array => $this->decorate_row($row), $rows);
        $rows = array_values(array_filter($rows, fn(array $row): bool => $this->row_matches($row, $filters)));

        return [
            'rows' => $rows,
            'counters' => $this->counters($filters),
            'diagnostics' => $this->diagnostics(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Load only the limited product candidates needed by the Inventory batch runner.
     *
     * This intentionally avoids query(), counters(), CSV columns, payload JSON, and any
     * full-preview decoration so batch dry-runs do not materialize thousands of rows.
     */
    public function inventory_batch_candidates(string $selection = 'both', int $offset = 0, int $limit = 25): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $markets = $selection === 'fr' ? ['fr'] : ($selection === 'de' ? ['de'] : ['fr', 'de']);
        $tables = Database::table_names();
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        $mappingJoin = $this->table_exists($mappingTable)
            ? "LEFT JOIN {$mappingTable} mm_fr ON mm_fr.woo_product_id = pm.product_id AND mm_fr.marketplace = 'ebay_fr'
               LEFT JOIN {$mappingTable} mm_de ON mm_de.woo_product_id = pm.product_id AND mm_de.marketplace = 'ebay'"
            : "";
        $mappingSelect = $this->table_exists($mappingTable)
            ? "mm_fr.remote_listing_id AS ebay_fr_item_id, mm_fr.remote_offer_id AS ebay_fr_offer_id, mm_fr.remote_inventory_id AS ebay_fr_inventory_item_sku, mm_fr.status AS ebay_fr_status,
               mm_de.remote_listing_id AS ebay_de_item_id, mm_de.remote_offer_id AS ebay_de_offer_id, mm_de.remote_inventory_id AS ebay_de_inventory_item_sku, mm_de.status AS ebay_de_status"
            : "'' AS ebay_fr_item_id, '' AS ebay_fr_offer_id, '' AS ebay_fr_inventory_item_sku, '' AS ebay_fr_status,
               '' AS ebay_de_item_id, '' AS ebay_de_offer_id, '' AS ebay_de_inventory_item_sku, '' AS ebay_de_status";

        $sql = "SELECT pm.product_id, pm.sku, pm.part_number_normalized, pm.part_cache_id,
                   p.post_title AS product_title,
                   GROUP_CONCAT(DISTINCT vc.vehicle_id ORDER BY vc.vehicle_id ASC SEPARATOR ',') AS vehicle_ids,
                   {$mappingSelect}
            FROM {$tables['product_map']} pm
            LEFT JOIN {$tables['part_cache']} pc ON pc.id = pm.part_cache_id
            LEFT JOIN {$tables['vehicle_cache']} vc ON vc.part_cache_id = pm.part_cache_id
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.product_id
            {$mappingJoin}
            WHERE pm.part_cache_id IS NOT NULL AND pc.vehicle_count > 0
            GROUP BY pm.product_id, pm.sku, pm.part_number_normalized, pm.part_cache_id, p.post_title
            ORDER BY pm.product_id ASC
            LIMIT %d OFFSET %d";
        $dbRows = $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A) ?: [];
        $candidates = [];
        foreach ($dbRows as $row) {
            $vehicles = $this->vehicle_ids_from_row(['vehicle_ids' => (string) ($row['vehicle_ids'] ?? '')]);
            if (!$vehicles) { $vehicles = $this->vehicle_ids((int) ($row['part_cache_id'] ?? 0)); }
            $candidate = [
                'product_id' => (string) (int) ($row['product_id'] ?? 0),
                'product_title' => trim((string) ($row['product_title'] ?? '')),
                'sku' => (string) ($row['sku'] ?? ''),
                'part_number_normalized' => (string) ($row['part_number_normalized'] ?? ''),
                'part_cache_id' => (string) ($row['part_cache_id'] ?? ''),
                'ktype_count' => (string) count($vehicles),
                'vehicle_ids' => implode(',', $vehicles),
                'sample_ktypes' => implode(',', array_slice($vehicles, 0, 10)),
            ];
            foreach ($markets as $market) {
                $prefix = $market === 'fr' ? 'ebay_fr' : 'ebay_de';
                $mapping = $this->mapping_listing((int) ($row['product_id'] ?? 0), $market);
                $candidate[$prefix . '_item_id'] = trim((string) (($row[$prefix . '_item_id'] ?? '') ?: $mapping['item_id']));
                $candidate[$prefix . '_status'] = trim((string) (($row[$prefix . '_status'] ?? '') ?: $mapping['status']));
                $candidate[$prefix . '_offer_id'] = trim((string) (($row[$prefix . '_offer_id'] ?? '') ?: $mapping['offer_id']));
                $candidate[$prefix . '_inventory_item_sku'] = trim((string) (($row[$prefix . '_inventory_item_sku'] ?? '') ?: $mapping['inventory_item_sku']));
                $candidate[$prefix . '_listing_management_type'] = ($candidate[$prefix . '_offer_id'] !== '' || $candidate[$prefix . '_inventory_item_sku'] !== '') ? 'inventory' : ($candidate[$prefix . '_item_id'] !== '' ? 'trading' : 'unknown');
            }
            $candidates[] = $candidate;
        }

        return [
            'rows' => $candidates,
            'limit' => $limit,
            'offset' => $offset,
            'marketplaces' => $markets,
            'candidate_products_loaded' => count($candidates),
            'marketplace_attempts_built' => count($candidates) * count($markets),
        ];
    }

    public function one_product(int $productId): ?array
    {
        $result = $this->query(['product_id' => $productId, 'limit' => 1, 'offset' => 0]);
        return $result['rows'][0] ?? null;
    }

    public function inventory_fitment_preview(int $productId, string $selection = 'both'): array
    {
        $row = $this->one_product($productId);
        $markets = $selection === 'de' ? ['de' => 'EBAY_DE'] : ($selection === 'fr' ? ['fr' => 'EBAY_FR'] : ['de' => 'EBAY_DE', 'fr' => 'EBAY_FR']);
        $results = [];
        foreach ($markets as $key => $marketplace) {
            $prefix = $key === 'fr' ? 'ebay_fr' : 'ebay_de';
            if (!$row) {
                $results[$marketplace] = ['marketplace' => $marketplace, 'would_update_inventory_fitment' => 'no', 'blocked_reason_inventory' => 'product_not_found_or_not_mapped'];
                continue;
            }
            $listing = [
                'item_id' => (string) ($row[$prefix . '_item_id'] ?? ''),
                'offer_id' => (string) ($row[$prefix . '_offer_id'] ?? ''),
                'inventory_item_sku' => (string) ($row[$prefix . '_inventory_item_sku'] ?? ''),
                'listing_management_type' => (string) ($row[$prefix . '_listing_management_type'] ?? 'unknown'),
                'status' => (string) ($row[$prefix . '_status'] ?? ''),
                'marketplace_id' => $marketplace,
            ];
            $results[$marketplace] = $this->inventory_fitment_preview_for_listing($marketplace, $listing, $this->vehicle_ids_from_row($row)) + [
                'product_id' => (string) ($row['product_id'] ?? ''),
                'item_id' => $listing['item_id'],
                'offer_id' => $listing['offer_id'],
                'inventory_item_sku' => $listing['inventory_item_sku'],
                'listing_management_type' => $listing['listing_management_type'],
                'listing_status' => $listing['status'],
                'ktype_count' => (string) count($this->vehicle_ids_from_row($row)),
                'sample_ktypes' => implode(',', array_slice($this->vehicle_ids_from_row($row), 0, 10)),
            ];
        }

        return ['product_id' => $productId, 'product' => $row, 'results' => $results, 'write_enabled' => false, 'note' => 'Preview only. No eBay Inventory API write is called.'];
    }

    public function stream_csv(array $args = []): void
    {
        $args['limit'] = isset($args['limit']) ? (int) $args['limit'] : 5000;
        $result = $this->query($args);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ebay-fitment-preview-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, self::CSV_COLUMNS);
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), self::CSV_COLUMNS));
        }
        fclose($out);
    }

    private function filters(array $args): array
    {
        return [
            'only_with_ktype' => !empty($args['only_with_ktype']),
            'missing_de' => !empty($args['missing_de']),
            'missing_fr' => !empty($args['missing_fr']),
            'ready_de' => !empty($args['ready_de']),
            'ready_fr' => !empty($args['ready_fr']),
            'product_id' => isset($args['product_id']) && trim((string) $args['product_id']) !== '' ? max(0, (int) $args['product_id']) : null,
            'part_number' => trim((string) ($args['part_number'] ?? '')),
        ];
    }

    private function base_rows(int $limit, int $offset, array $filters): array
    {
        global $wpdb;
        $tables = Database::table_names();
        $where = ['pm.part_cache_id IS NOT NULL'];
        $params = [];
        if (!empty($filters['only_with_ktype'])) { $where[] = 'pc.vehicle_count > 0'; }
        if ($filters['product_id'] !== null && (int) $filters['product_id'] > 0) { $where[] = 'pm.product_id = %d'; $params[] = (int) $filters['product_id']; }
        if ((string) $filters['part_number'] !== '') { $where[] = 'pm.part_number_normalized LIKE %s'; $params[] = '%' . $wpdb->esc_like((string) $filters['part_number']) . '%'; }
        $sql = "SELECT pm.product_id, pm.sku, pm.part_number_normalized, pm.part_cache_id, pc.vehicle_count AS ktype_count, p.post_title AS product_title, p.post_status
            FROM {$tables['product_map']} pm
            LEFT JOIN {$tables['part_cache']} pc ON pc.id = pm.part_cache_id
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.product_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pm.product_id ASC LIMIT %d OFFSET %d";
        $params[] = $limit * 3;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        return array_values(array_filter($rows, 'is_array'));
    }

    private function decorate_row(array $row): array
    {
        $productId = (int) ($row['product_id'] ?? 0);
        $ktypeCount = (int) ($row['ktype_count'] ?? 0);
        $vehicles = $this->vehicle_ids((int) ($row['part_cache_id'] ?? 0));
        $de = $this->listing($productId, 'de');
        $fr = $this->listing($productId, 'fr');
        $productTitle = trim((string) ($row['product_title'] ?? ''));
        $productExists = $productId > 0 && $productTitle !== '';
        $deBlocked = $this->market_blocked_reasons($productExists, $ktypeCount, $de, 'de');
        $frBlocked = $this->market_blocked_reasons($productExists, $ktypeCount, $fr, 'fr');
        $deReady = $deBlocked === [];
        $frReady = $frBlocked === [];
        $deInventory = $this->inventory_fitment_preview_for_listing('EBAY_DE', $de, $vehicles);
        $frInventory = $this->inventory_fitment_preview_for_listing('EBAY_FR', $fr, $vehicles);
        $blocked = $this->overall_blocked_reasons($deBlocked, $frBlocked, $deReady, $frReady);

        return [
            'product_id' => (string) $productId,
            'product_title' => $productTitle,
            'sku' => (string) ($row['sku'] !== '' ? $row['sku'] : get_post_meta($productId, '_sku', true)),
            'part_number_normalized' => (string) ($row['part_number_normalized'] ?? ''),
            'part_cache_id' => (string) ($row['part_cache_id'] ?? ''),
            'ktype_count' => (string) $ktypeCount,
            'vehicle_ids' => implode(',', $vehicles),
            'sample_ktypes' => implode(',', array_slice($vehicles, 0, 10)),
            'ebay_de_item_id' => $de['item_id'],
            'ebay_de_status' => $de['status'],
            'ebay_de_listing_management_type' => $de['listing_management_type'],
            'ebay_de_inventory_item_sku' => $de['inventory_item_sku'],
            'ebay_de_offer_id' => $de['offer_id'],
            'ebay_de_inventory_marketplace' => $deInventory['marketplace'],
            'ebay_de_inventory_endpoint' => $deInventory['endpoint'],
            'ebay_de_would_update_inventory_fitment' => $deInventory['would_update_inventory_fitment'],
            'ebay_de_blocked_reason_inventory' => $deInventory['blocked_reason_inventory'],
            'ebay_de_inventory_payload_summary' => $deInventory['payload_summary'],
            'ebay_de_inventory_payload_json' => $deInventory['payload_json'],
            'ebay_fr_item_id' => $fr['item_id'],
            'ebay_fr_status' => $fr['status'],
            'ebay_fr_listing_management_type' => $fr['listing_management_type'],
            'ebay_fr_inventory_item_sku' => $fr['inventory_item_sku'],
            'ebay_fr_offer_id' => $fr['offer_id'],
            'ebay_fr_inventory_marketplace' => $frInventory['marketplace'],
            'ebay_fr_inventory_endpoint' => $frInventory['endpoint'],
            'ebay_fr_would_update_inventory_fitment' => $frInventory['would_update_inventory_fitment'],
            'ebay_fr_blocked_reason_inventory' => $frInventory['blocked_reason_inventory'],
            'ebay_fr_inventory_payload_summary' => $frInventory['payload_summary'],
            'ebay_fr_inventory_payload_json' => $frInventory['payload_json'],
            'would_update_de' => $deReady ? 'yes' : 'no',
            'would_update_fr' => $frReady ? 'yes' : 'no',
            'blocked_reason_de' => $deBlocked ? implode('|', array_values(array_unique($deBlocked))) : '',
            'blocked_reason_fr' => $frBlocked ? implode('|', array_values(array_unique($frBlocked))) : '',
            'blocked_reason' => $blocked ? implode('|', array_values(array_unique($blocked))) : '',
            'live_checked_revisable_de' => '',
            'live_checked_revisable_fr' => '',
            'local_active_but_live_ended_de' => '',
            'local_active_but_live_ended_fr' => '',
        ];
    }

    private function market_blocked_reasons(bool $productExists, int $ktypeCount, array $listing, string $market): array
    {
        $blocked = [];
        if (!$productExists) { $blocked[] = 'product_not_found'; }
        if ($ktypeCount <= 0) { $blocked[] = 'no_ktype'; }
        if ((string) ($listing['item_id'] ?? '') === '') { $blocked[] = $market === 'fr' ? 'no_ebay_fr_listing' : 'no_ebay_de_listing'; }
        if ((string) ($listing['item_id'] ?? '') !== '' && !$this->status_ready((string) ($listing['status'] ?? ''))) { $blocked[] = 'listing_status_not_active'; }
        return array_values(array_unique($blocked));
    }

    private function overall_blocked_reasons(array $deBlocked, array $frBlocked, bool $deReady, bool $frReady): array
    {
        if ($deReady || $frReady) {
            return array_values(array_unique(array_merge($deReady ? [] : $deBlocked, $frReady ? [] : $frBlocked)));
        }
        return array_values(array_unique(array_merge($deBlocked, $frBlocked)));
    }

    private function row_matches(array $row, array $filters): bool
    {
        if (!empty($filters['missing_de']) && (string) $row['ebay_de_item_id'] !== '') { return false; }
        if (!empty($filters['missing_fr']) && (string) $row['ebay_fr_item_id'] !== '') { return false; }
        if (!empty($filters['ready_de']) && (string) $row['would_update_de'] !== 'yes') { return false; }
        if (!empty($filters['ready_fr']) && (string) $row['would_update_fr'] !== 'yes') { return false; }
        return true;
    }

    private function counters(array $filters): array
    {
        $rows = array_map(fn(array $row): array => $this->decorate_row($row), $this->base_rows(100000, 0, ['only_with_ktype' => true, 'product_id' => null, 'part_number' => '']));
        return [
            'products_with_ktype' => count($rows),
            'products_with_de_listing' => count(array_filter($rows, fn($r) => $r['ebay_de_item_id'] !== '')),
            'products_with_fr_listing' => count(array_filter($rows, fn($r) => $r['ebay_fr_item_id'] !== '')),
            'products_with_both_de_fr' => count(array_filter($rows, fn($r) => $r['ebay_de_item_id'] !== '' && $r['ebay_fr_item_id'] !== '')),
            'ready_for_de' => count(array_filter($rows, fn($r) => $r['would_update_de'] === 'yes')),
            'ready_for_fr' => count(array_filter($rows, fn($r) => $r['would_update_fr'] === 'yes')),
            'ready_for_both' => count(array_filter($rows, fn($r) => $r['would_update_de'] === 'yes' && $r['would_update_fr'] === 'yes')),
            'missing_de_listing' => count(array_filter($rows, fn($r) => $r['ebay_de_item_id'] === '')),
            'missing_fr_listing' => count(array_filter($rows, fn($r) => $r['ebay_fr_item_id'] === '')),
            'blocked_count' => count(array_filter($rows, fn($r) => $r['blocked_reason'] !== '')),
        ];
    }

    private function inventory_fitment_preview_for_listing(string $marketplace, array $listing, array $vehicleIds): array
    {
        $inventorySku = trim((string) ($listing['inventory_item_sku'] ?? ''));
        $offerId = trim((string) ($listing['offer_id'] ?? ''));
        $itemId = trim((string) ($listing['item_id'] ?? ''));
        $status = trim((string) ($listing['status'] ?? ''));
        $blocked = [];
        if ($itemId === '') { $blocked[] = 'missing_item_id'; }
        if ($offerId === '') { $blocked[] = 'missing_offer_id'; }
        if ($inventorySku === '') { $blocked[] = 'missing_inventory_item_sku'; }
        if (!$this->status_ready($status)) { $blocked[] = 'listing_status_not_active'; }
        if (!$vehicleIds) { $blocked[] = 'no_ktype'; }
        $payload = $this->inventory_compatibility_payload($marketplace, $vehicleIds);
        $endpoint = $inventorySku !== '' ? 'PUT /sell/inventory/v1/inventory_item/' . rawurlencode($inventorySku) . '/product_compatibility' : 'PUT /sell/inventory/v1/inventory_item/{inventory_item_sku}/product_compatibility';
        return [
            'marketplace' => $marketplace,
            'method' => 'PUT',
            'endpoint' => $endpoint,
            'target' => $inventorySku !== '' ? $inventorySku : $offerId,
            'payload' => $payload,
            'payload_json' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
            'payload_summary' => 'compatibleProducts=' . count($payload['compatibleProducts']) . '; productIdentifier.ktype sample=' . implode(',', array_slice($vehicleIds, 0, 10)),
            'would_update_inventory_fitment' => $blocked === [] ? 'yes' : 'no',
            'blocked_reason_inventory' => $blocked ? implode('|', array_values(array_unique($blocked))) : '',
            'live_write_enabled' => 'no',
        ];
    }

    private function inventory_compatibility_payload(string $marketplace, array $vehicleIds): array
    {
        return [
            'compatibleProducts' => array_map(static fn(string $ktype): array => ['productIdentifier' => ['ktype' => $ktype]], array_values(array_map('strval', $vehicleIds))),
        ];
    }

    private function vehicle_ids_from_row(array $row): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($row['vehicle_ids'] ?? '')))));
    }

    private function vehicle_ids(int $partCacheId): array
    {
        if ($partCacheId <= 0) { return []; }
        global $wpdb;
        $table = Database::table_names()['vehicle_cache'];
        return array_map('strval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT vehicle_id FROM {$table} WHERE part_cache_id = %d ORDER BY vehicle_id ASC", $partCacheId)) ?: []);
    }


    private function mapping_listing(int $productId, string $market): array
    {
        global $wpdb;
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        $marketplace = $market === 'fr' ? 'ebay_fr' : 'ebay';
        $mapping = $this->table_exists($mappingTable) ? $wpdb->get_row($wpdb->prepare("SELECT remote_listing_id, remote_offer_id, remote_inventory_id, marketplace_id, sku, status FROM {$mappingTable} WHERE marketplace=%s AND woo_product_id=%d ORDER BY updated_at DESC LIMIT 1", $marketplace, $productId), ARRAY_A) : [];
        return [
            'item_id' => trim((string) ($mapping['remote_listing_id'] ?? '')),
            'offer_id' => trim((string) ($mapping['remote_offer_id'] ?? '')),
            'inventory_item_sku' => trim((string) (($mapping['remote_inventory_id'] ?? '') ?: ($mapping['sku'] ?? ''))),
            'status' => trim((string) ($mapping['status'] ?? '')),
        ];
    }

    private function listing(int $productId, string $market): array
    {
        global $wpdb;
        $prefix = $market === 'fr' ? '_wei_fr_ebay_' : '_wei_ebay_';
        $marketplace = $market === 'fr' ? 'ebay_fr' : 'ebay';
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        $mapping = $this->table_exists($mappingTable) ? $wpdb->get_row($wpdb->prepare("SELECT remote_listing_id, remote_offer_id, remote_inventory_id, marketplace_id, sku, status FROM {$mappingTable} WHERE marketplace=%s AND woo_product_id=%d ORDER BY updated_at DESC LIMIT 1", $marketplace, $productId), ARRAY_A) : null;
        $itemId = trim((string) ($mapping['remote_listing_id'] ?? ''));
        if ($itemId === '') { $itemId = trim((string) get_post_meta($productId, $prefix . 'listing_id', true)); }
        if ($itemId === '') { $itemId = trim((string) get_post_meta($productId, $prefix . 'item_id', true)); }
        $offerId = trim((string) ($mapping['remote_offer_id'] ?? ''));
        if ($offerId === '') { $offerId = trim((string) get_post_meta($productId, $prefix . 'offer_id', true)); }
        $inventorySku = trim((string) ($mapping['remote_inventory_id'] ?? ''));
        if ($inventorySku === '') { $inventorySku = trim((string) get_post_meta($productId, $prefix . 'inventory_item_id', true)); }
        if ($inventorySku === '') { $inventorySku = trim((string) get_post_meta($productId, $prefix . 'inventory_id', true)); }
        if ($inventorySku === '') { $inventorySku = trim((string) ($mapping['sku'] ?? '')); }
        $marketplaceId = trim((string) ($mapping['marketplace_id'] ?? ''));
        if ($marketplaceId === '') { $marketplaceId = $market === 'fr' ? 'EBAY_FR' : 'EBAY_DE'; }
        $status = trim((string) ($mapping['status'] ?? ''));
        if ($status === '') { $status = trim((string) get_post_meta($productId, $prefix . 'listing_status', true)); }
        if ($status === '') { $status = trim((string) get_post_meta($productId, $prefix . 'export_status', true)); }
        $type = ($offerId !== '' || $inventorySku !== '') ? 'inventory' : ($itemId !== '' ? 'trading' : 'unknown');
        return ['item_id' => $itemId, 'status' => $status, 'offer_id' => $offerId, 'inventory_item_sku' => $inventorySku, 'listing_management_type' => $type, 'marketplace_id' => $marketplaceId];
    }

    private function status_ready(string $status): bool
    {
        $s = strtolower(trim($status));
        return $s === '' || in_array($s, ['active', 'published'], true);
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function diagnostics(): array
    {
        global $wpdb;
        return [
            'mapping_table' => $wpdb->prefix . 'marketplace_mappings',
            'mapping_table_exists' => $this->table_exists($wpdb->prefix . 'marketplace_mappings'),
            'de_sources' => ['marketplace_mappings.marketplace=ebay remote_listing_id', self::DE_LISTING_META, self::DE_ITEM_META, self::DE_STATUS_META, self::DE_OFFER_META, self::DE_INVENTORY_ITEM_META, self::DE_INVENTORY_ID_META, 'marketplace_mappings.remote_offer_id', 'marketplace_mappings.remote_inventory_id'],
            'fr_sources' => ['marketplace_mappings.marketplace=ebay_fr remote_listing_id', self::FR_LISTING_META, self::FR_ITEM_META, self::FR_STATUS_META, self::FR_OFFER_META, self::FR_INVENTORY_ITEM_META, self::FR_INVENTORY_ID_META, 'marketplace_mappings.remote_offer_id', 'marketplace_mappings.remote_inventory_id'],
            'note' => 'Preview reads local DB/cache/meta only; it does not call eBay, Apify, TecDoc, or modify Woo products.',
        ];
    }
}
