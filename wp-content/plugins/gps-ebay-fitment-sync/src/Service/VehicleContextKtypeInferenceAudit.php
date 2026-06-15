<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class VehicleContextKtypeInferenceAudit
{
    private const BATCH_SIZE = 250;
    public const COLUMNS = ['product_id','sku','title','oem','part_number_normalized','parsed_make','parsed_model','parsed_platform','parsed_year','parsed_engine_code','parsed_engine','parsed_facelift_hint','parsed_side_body_hints','current_has_ktype_cache','inferred_ktype_count','inferred_ktype_sample','inference_confidence','inference_reason','matched_vehicle_context_source','has_fr_mapping','has_de_mapping','fr_item_id','de_item_id'];

    public function run(int $limit = 50, bool $onlyMappedNoKtype = true, string $threshold = 'LOW'): array
    {
        $before = memory_get_usage(true);
        $limit = max(0, min(200, $limit));
        $rows = $this->fetch_rows(0, $limit, $onlyMappedNoKtype);
        $rows = array_map(fn(array $row): array => $this->decorate($row), $rows);
        $summary = $this->summary($onlyMappedNoKtype, $threshold);
        foreach ($rows as $row) {
            if ($row['parsed_make'] !== '' || $row['parsed_model'] !== '' || $row['parsed_platform'] !== '') { $summary['preview_missing_ktype_with_vehicle_context']++; }
            if ($row['inference_confidence'] === 'HIGH') { $summary['preview_high_confidence_inferred']++; }
            if ($row['inference_confidence'] === 'MEDIUM') { $summary['preview_medium_confidence_inferred']++; }
            if ($row['inference_confidence'] === 'LOW') { $summary['preview_low_confidence_inferred']++; }
        }
        return ['summary' => $summary, 'rows' => $rows, 'preview_limit' => $limit, 'only_products_with_ebay_mapping_but_no_ktype' => $onlyMappedNoKtype ? 'yes' : 'no', 'confidence_threshold_display_only' => $threshold, 'note' => 'Read-only local vehicle-context KType inference audit. Uses local product titles/mappings and existing local KType vehicle cache only. No Apify, TecDoc live, eBay API, Woo, KType cache, or mapping writes are performed.', 'memory_diagnostics' => ['memory_limit' => ini_get('memory_limit'), 'memory_usage_before' => $before, 'memory_usage_after' => memory_get_usage(true), 'peak_memory_usage' => memory_get_peak_usage(true), 'batch_size' => self::BATCH_SIZE]];
    }

    public function stream_csv_download(bool $onlyMappedNoKtype = true, string $threshold = 'LOW'): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ebay-vehicle-context-ktype-inference-audit-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w'); if (!$out) { return; }
        fputcsv($out, ['summary_key','summary_value']);
        foreach ($this->summary($onlyMappedNoKtype, $threshold) as $k => $v) { fputcsv($out, [$k, (string) $v]); }
        fputcsv($out, []); fputcsv($out, self::COLUMNS);
        $last = 0; do { $rows = $this->fetch_rows($last, self::BATCH_SIZE, $onlyMappedNoKtype); foreach ($rows as $row) { $last = max($last, (int) $row['product_id']); $row = $this->decorate($row); fputcsv($out, array_map(static fn(string $c): string => (string)($row[$c] ?? ''), self::COLUMNS)); } fflush($out); } while (count($rows) === self::BATCH_SIZE);
        fclose($out);
    }

    public function parse_context(string $title): array
    {
        $t = strtoupper(strtr($title, ['Š'=>'S','Ś'=>'S','Ł'=>'L','Ż'=>'Z','Ź'=>'Z','Ć'=>'C','Ń'=>'N','Ó'=>'O','Ę'=>'E','Ą'=>'A']));
        $generic = (bool) preg_match('/\b(UNIWERSAL|UNIVERSAL|GENERIC|ZESTAW NAPRAWCZY|AKCESORIA)\b/u', $t);
        $make = ''; foreach (['MERCEDES-BENZ'=>'MERCEDES-BENZ','VOLKSWAGEN'=>'VW','AUDI'=>'AUDI','SKODA'=>'SKODA','SEAT'=>'SEAT','BMW'=>'BMW','RENAULT'=>'RENAULT','VOLVO'=>'VOLVO','JEEP'=>'JEEP','VW'=>'VW'] as $needle => $value) { if (preg_match('/\b'.preg_quote($needle,'/').'\b/u', $t)) { $make = $value; break; } }
        $model = ''; foreach (['T-ROC','T ROC','SQ5','Q5','A3','A4','GLK','X204','W204','TIGUAN','GOLF','PASSAT','LEON','OCTAVIA','XC60','GRAND CHEROKEE','CLIO','MEGANE'] as $m) { if (preg_match('/\b'.preg_quote($m,'/').'\b/u', $t)) { $model = str_replace(' ', '-', $m); break; } }
        if ($model === 'SQ5') { $model = 'Q5'; }
        preg_match_all('/\b(8RB|8R|8P|B8|W204|X204|5N|4M|F3|82A|AU\d{3}|[A-Z]\d{3})\b/u', $t, $platforms);
        $platform = implode('|', array_values(array_unique($platforms[1] ?? [])));
        preg_match('/\b(19|20)\d{2}\b/u', $t, $year);
        preg_match('/\b([A-Z]{3,4})\b/u', $t, $engineCode);
        $engineCodeValue = (isset($engineCode[1]) && !in_array($engineCode[1], ['AUDI','SKODA','SEAT','JEEP','LIFT'], true)) ? $engineCode[1] : '';
        preg_match('/\b\d[.,]\d\s*(TDI|TFSI|TSI|TCE|DIESEL|BENZYNA|HYBRID)\b/u', $t, $engine);
        preg_match_all('/\b(LEWY|PRAWY|PRZOD|TYL|TYLNY|PRZEDNI|OSLONA|SLUPKA|DRZWI|ZDERZAK|NADWOZIE|KOMBI|LIFT|FACELIFT)\b/u', $t, $hints);
        return ['make'=>$make,'model'=>$model,'platform'=>$platform,'year'=>$year[0] ?? '','engine_code'=>$engineCodeValue,'engine'=>$engine[0] ?? '','facelift_hint'=>preg_match('/\b(LIFT|FACELIFT)\b/u', $t) ? 'yes' : '','side_body_hints'=>implode('|', array_values(array_unique($hints[1] ?? []))),'is_generic'=>$generic];
    }

    private function decorate(array $row): array
    {
        $ctx = $this->parse_context((string)($row['title'] ?? ''));
        $inf = $this->infer($ctx);
        return $row + ['parsed_make'=>$ctx['make'],'parsed_model'=>$ctx['model'],'parsed_platform'=>$ctx['platform'],'parsed_year'=>$ctx['year'],'parsed_engine_code'=>$ctx['engine_code'],'parsed_engine'=>$ctx['engine'],'parsed_facelift_hint'=>$ctx['facelift_hint'],'parsed_side_body_hints'=>$ctx['side_body_hints'],'current_has_ktype_cache'=>'no','inferred_ktype_count'=>(string)count($inf['ktypes']),'inferred_ktype_sample'=>implode(',', array_slice($inf['ktypes'],0,20)),'inference_confidence'=>$inf['confidence'],'inference_reason'=>$inf['reason'],'matched_vehicle_context_source'=>$inf['source']];
    }

    private function infer(array $ctx): array
    {
        global $wpdb; if (!empty($ctx['is_generic'])) { return $this->reject('generic_or_universal_title'); }
        if ($ctx['make'] === '' || $ctx['model'] === '') { return $this->reject('no_clear_vehicle_context'); }
        if (str_contains($ctx['platform'], '|')) { return $this->reject('ambiguous_multiple_platforms'); }
        $tables = Database::table_names(); $vehicle = $tables['vehicle_cache'];
        $clauses = ['vehicle_id > 0']; $args = [];
        $clauses[] = 'UPPER(manufacturer_name) LIKE %s'; $args[] = '%' . $wpdb->esc_like($ctx['make'] === 'VW' ? 'VOLKSWAGEN' : $ctx['make']) . '%';
        $modelNeedle = $ctx['model'] === 'T-ROC' ? 'T-ROC' : $ctx['model'];
        $clauses[] = '(UPPER(model_name) LIKE %s OR UPPER(type_name) LIKE %s)'; $args[] = '%' . $wpdb->esc_like($modelNeedle) . '%'; $args[] = '%' . $wpdb->esc_like($modelNeedle) . '%';
        $platform = (string)$ctx['platform'];
        if ($platform !== '') { $clauses[] = '(UPPER(model_name) LIKE %s OR UPPER(type_name) LIKE %s OR UPPER(raw_json) LIKE %s)'; $args[]='%'.$wpdb->esc_like($platform).'%'; $args[]='%'.$wpdb->esc_like($platform).'%'; $args[]='%'.$wpdb->esc_like($platform).'%'; }
        $sql = 'SELECT DISTINCT vehicle_id, manufacturer_name, model_name, type_name FROM '.$vehicle.' WHERE '.implode(' AND ', $clauses).' ORDER BY vehicle_id ASC LIMIT 500';
        $matches = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
        if (!$matches && $platform !== '') { return $this->reject('no_local_vehicle_cache_match_for_make_model_platform'); }
        if (!$matches) { return $this->reject('no_local_vehicle_cache_match_for_make_model'); }
        $ids = array_values(array_unique(array_map(static fn(array $r): string => (string)$r['vehicle_id'], $matches)));
        $source = (string)($matches[0]['manufacturer_name'] ?? '') . ' ' . (string)($matches[0]['model_name'] ?? '') . ' ' . (string)($matches[0]['type_name'] ?? '');
        if ($platform !== '') { return ['confidence'=>'HIGH','reason'=>'make_model_platform_exact_local_vehicle_cache_match','source'=>$source,'ktypes'=>$ids]; }
        return ['confidence'=>'LOW','reason'=>'make_model_only_local_vehicle_cache_match_no_platform','source'=>$source,'ktypes'=>$ids];
    }
    private function reject(string $reason): array { return ['confidence'=>'REJECT','reason'=>$reason,'source'=>'','ktypes'=>[]]; }

    private function summary(bool $onlyMappedNoKtype, string $threshold): array
    {
        global $wpdb; $tables = Database::table_names(); $map=$tables['product_map']; $part=$tables['part_cache']; $where = $this->missing_where() . ($onlyMappedNoKtype ? ' AND ('.$this->has_mapping_sql().')' : '');
        return ['products_audited'=>(int)$wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE {$where}"),'missing_ktype_with_vehicle_context'=>0,'high_confidence_inferred'=>0,'medium_confidence_inferred'=>0,'low_confidence_inferred'=>0,'rejected_ambiguous'=>0,'rejected_no_vehicle_context'=>0,'products_with_ebay_mapping_and_high_confidence'=>0,'potential_additional_fr_listings'=>0,'potential_additional_de_listings'=>0,'confidence_threshold_display_only'=>$threshold,'summary_note'=>'Inference confidence counts are computed in bounded preview/CSV rows to avoid unbounded PHP parsing; products_audited uses aggregate SQL.'];
    }

    private function fetch_rows(int $lastProductId, int $limit, bool $onlyMappedNoKtype): array
    {
        if ($limit <= 0) { return []; } global $wpdb; $tables=Database::table_names(); $map=$tables['product_map']; $part=$tables['part_cache']; $mapping=$wpdb->prefix.'marketplace_mappings'; $log=$tables['ebay_sync_log']; $mappingExists=$this->table_exists($mapping);
        $mappingSelect = $mappingExists ? "MAX(mm_fr.remote_listing_id) map_fr_item_id, MAX(mm_de.remote_listing_id) map_de_item_id" : "'' map_fr_item_id, '' map_de_item_id";
        $mappingJoin = $mappingExists ? "LEFT JOIN {$mapping} mm_fr ON mm_fr.woo_product_id=pm.product_id AND mm_fr.marketplace='ebay_fr' LEFT JOIN {$mapping} mm_de ON mm_de.woo_product_id=pm.product_id AND mm_de.marketplace='ebay'" : '';
        $where = 'pm.product_id > %d AND '.$this->missing_where().($onlyMappedNoKtype ? ' AND ('.$this->has_mapping_sql().')' : '');
        $sql = "SELECT pm.product_id, MAX(pm.sku) sku, p.post_title title, MIN(pm.part_number_raw) oem, MIN(pm.part_number_normalized) part_number_normalized, {$mappingSelect}, MAX(CASE WHEN l.marketplace='EBAY_FR' THEN l.ebay_item_id ELSE '' END) log_fr_item_id, MAX(CASE WHEN l.marketplace='EBAY_DE' THEN l.ebay_item_id ELSE '' END) log_de_item_id FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id LEFT JOIN {$wpdb->posts} p ON p.ID=pm.product_id {$mappingJoin} LEFT JOIN {$log} l ON l.product_id=pm.product_id AND l.api_mode='inventory' AND l.status IN ('success','warning_success') WHERE {$where} GROUP BY pm.product_id, p.post_title ORDER BY pm.product_id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $lastProductId, $limit), ARRAY_A) ?: [];
        foreach ($rows as &$r) { $fr=trim((string)(($r['map_fr_item_id'] ?? '') ?: ($r['log_fr_item_id'] ?? ''))); $de=trim((string)(($r['map_de_item_id'] ?? '') ?: ($r['log_de_item_id'] ?? ''))); $r['fr_item_id']=$fr; $r['de_item_id']=$de; $r['has_fr_mapping']=$fr!==''?'yes':'no'; $r['has_de_mapping']=$de!==''?'yes':'no'; unset($r['map_fr_item_id'],$r['map_de_item_id'],$r['log_fr_item_id'],$r['log_de_item_id']); }
        return $rows;
    }
    private function missing_where(): string { return 'pm.part_number_normalized <> \'\' AND GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) <= 0'; }
    private function has_mapping_sql(): string { global $wpdb; $mapping=$wpdb->prefix.'marketplace_mappings'; $log=Database::table_names()['ebay_sync_log']; $parts=[]; if ($this->table_exists($mapping)) { $parts[]="EXISTS (SELECT 1 FROM {$mapping} mm WHERE mm.woo_product_id=pm.product_id AND mm.marketplace IN ('ebay_fr','ebay') AND COALESCE(NULLIF(mm.remote_listing_id,''),NULLIF(mm.remote_offer_id,''),NULLIF(mm.remote_inventory_id,''),NULLIF(mm.sku,'')) IS NOT NULL)"; } $parts[]="EXISTS (SELECT 1 FROM {$log} sl WHERE sl.product_id=pm.product_id AND sl.api_mode='inventory' AND sl.marketplace IN ('EBAY_FR','EBAY_DE') AND sl.status IN ('success','warning_success') AND COALESCE(NULLIF(sl.ebay_item_id,''),NULLIF(sl.offer_id,''),NULLIF(sl.inventory_item_sku,'')) IS NOT NULL)"; return '('.implode(' OR ', $parts).')'; }
    private function table_exists(string $table): bool { global $wpdb; return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table; }
}
