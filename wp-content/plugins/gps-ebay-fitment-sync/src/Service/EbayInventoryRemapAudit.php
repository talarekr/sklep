<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

final class EbayInventoryRemapAudit
{
    public const BLOCK_REASON = 'stale_or_unconfirmed_listing_mapping';
    public const COLUMNS = ['product_id','product_title','marketplace','part_number_normalized','ktype_count','inventory_item_sku','local_item_id','local_offer_id','local_sku','local_listing_status','live_item_id','live_listing_id','live_offer_id','live_offer_status','live_listing_status','live_listing_url','live_available_quantity','live_marketplace_id','marketplace_id','is_published','is_active_listing','stale_mapping','suggested_current_item_id','suggested_current_offer_id','suggested_inventory_item_sku','suggested_action','validation_decision','validation_detail','api_error'];

    public function __construct(private EbayFitmentPreview $preview) {}

    public function run_batch(array $args): array
    {
        $selection = (string) ($args['marketplace'] ?? 'de');
        $limit = max(1, min(50, (int) ($args['batch_size'] ?? 10)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $runId = (string) ($args['run_id'] ?? '');
        if ($runId === '') { $runId = 'ebay-inventory-remap-audit-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false); }
        $loaded = $this->preview->inventory_batch_candidates($selection, $offset, $limit);
        $rows = [];
        foreach (($loaded['rows'] ?? []) as $row) {
            foreach ($this->markets($selection) as $market) {
                $audit = $this->audit_row($row, $market);
                $rows[] = $audit;
            }
        }
        $csv = $this->write_csv($runId, $rows, $offset > 0);
        update_option('gps_ebay_inventory_remap_audit_last', ['run_id'=>$runId,'marketplace'=>$selection,'offset'=>$offset,'batch_size'=>$limit,'csv_path'=>$csv['path'],'csv_url'=>$csv['url'],'rows'=>$rows,'updated_at'=>current_time('mysql')], false);
        return ['ok'=>true,'run_id'=>$runId,'marketplace'=>$selection,'batch_size'=>$limit,'offset'=>$offset,'next_offset'=>$offset + $limit,'rows'=>$rows,'csv'=>$csv,'readonly_methods'=>['GET /sell/inventory/v1/offer/{offer_id}','GET /sell/inventory/v1/inventory_item/{inventory_item_sku}','GET /sell/inventory/v1/offer?sku={inventory_item_sku}&marketplace_id={marketplace_id}']];
    }

    public function audit_row(array $row, string $marketplace): array
    {
        $prefix = $marketplace === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        $localItem = trim((string) ($row[$prefix . '_item_id'] ?? ''));
        $localOffer = trim((string) ($row[$prefix . '_offer_id'] ?? ''));
        $sku = trim((string) ($row[$prefix . '_inventory_item_sku'] ?? ''));
        $localStatus = trim((string) ($row[$prefix . '_status'] ?? ''));
        $base = ['product_id'=>(string)($row['product_id'] ?? ''),'product_title'=>(string)($row['product_title'] ?? ''),'marketplace'=>$marketplace,'part_number_normalized'=>(string)($row['part_number_normalized'] ?? ''),'ktype_count'=>(string)($row['ktype_count'] ?? ''),'inventory_item_sku'=>$sku,'local_item_id'=>$localItem,'local_offer_id'=>$localOffer,'local_sku'=>$sku,'local_listing_status'=>$localStatus,'live_item_id'=>'','live_listing_id'=>'','live_offer_id'=>'','live_offer_status'=>'','live_listing_status'=>'','live_listing_url'=>'','live_available_quantity'=>'','live_marketplace_id'=>'','marketplace_id'=>$marketplace,'is_published'=>'no','is_active_listing'=>'no','stale_mapping'=>'no','suggested_current_item_id'=>'','suggested_current_offer_id'=>'','suggested_inventory_item_sku'=>'','suggested_action'=>'','validation_decision'=>'blocked','validation_detail'=>'','api_error'=>''];
        if ($sku === '') { $base['suggested_action'] = 'missing_inventory_sku'; $base['validation_detail'] = 'Missing local inventory SKU; cannot confirm writable Inventory API offer.'; return $base; }
        $live = $this->live_listing($marketplace, $sku, $localOffer);
        if (!empty($live['error'])) { $base['api_error'] = (string) $live['error']; $base['suggested_action'] = 'api_error'; $base['validation_detail'] = 'Inventory API read failed: ' . $base['api_error']; return $base; }
        foreach (['item_id'=>'live_item_id','listing_id'=>'live_listing_id','offer_id'=>'live_offer_id','offer_status'=>'live_offer_status','listing_status'=>'live_listing_status','available_quantity'=>'live_available_quantity','marketplace_id'=>'live_marketplace_id'] as $from=>$to) { $base[$to] = (string) ($live[$from] ?? ''); }
        $base['live_listing_url'] = $this->listing_url($marketplace, $base['live_listing_id']);
        $base['is_published'] = !empty($live['is_published']) ? 'yes' : 'no';
        $base['is_active_listing'] = !empty($live['is_active_listing']) ? 'yes' : 'no';
        $detail = $this->validation_detail($localItem, $localOffer, $sku, $marketplace, $live);
        $base['validation_detail'] = $detail;
        if (($live['listing_id'] ?? '') === '') { $base['suggested_action'] = $localOffer === '' ? 'missing_offer_id' : 'current_listing_not_found'; return $base; }
        if (($live['sku'] ?? '') !== $sku || ($live['marketplace_id'] ?? '') !== $marketplace || $base['is_active_listing'] !== 'yes') { $base['suggested_action'] = 'current_listing_not_found'; return $base; }
        if ($localItem !== '' && $localItem !== $base['live_listing_id']) {
            $base['stale_mapping'] = 'yes';
            $base['suggested_current_item_id'] = $base['live_listing_id'];
            $base['suggested_current_offer_id'] = $base['live_offer_id'];
            $base['suggested_inventory_item_sku'] = $sku;
            $base['suggested_action'] = 'update_mapping_to_live_listing';
            return $base;
        }
        $base['suggested_action'] = 'ok_current_mapping';
        $base['validation_decision'] = 'writable';
        return $base;
    }

    public function validate_before_write(array $row, string $marketplace): array
    {
        $audit = $this->audit_row($row, $marketplace);
        $ok = ($audit['suggested_action'] ?? '') === 'ok_current_mapping' && ($audit['stale_mapping'] ?? '') === 'no';
        return ['ok'=>$ok,'audit'=>$audit,'blocked_reason'=>$ok ? '' : self::BLOCK_REASON];
    }

    private function live_listing(string $marketplace, string $sku, string $offerId): array
    {
        $direct = [];
        if ($offerId !== '') {
            $offer = $this->get_json($marketplace, '/sell/inventory/v1/offer/' . rawurlencode($offerId));
            if (!empty($offer['error']) && (int)($offer['http_status'] ?? 0) !== 404) { return $offer; }
            if (empty($offer['error'])) { $direct = $this->normalize_offer($offer, $sku); }
        }
        $offers = $this->get_json($marketplace, '/sell/inventory/v1/offer?sku=' . rawurlencode($sku) . '&marketplace_id=' . rawurlencode($marketplace));
        if (!empty($offers['error'])) { return $offers; }
        $best = [];
        foreach (($offers['offers'] ?? []) as $offer) {
            $n = $this->normalize_offer((array) $offer, $sku);
            if (($n['sku'] ?? '') !== $sku || ($n['marketplace_id'] ?? '') !== $marketplace) { continue; }
            if ($offerId !== '' && ($n['offer_id'] ?? '') === $offerId) { $best = $n; break; }
            if (!$best && !empty($n['is_active_listing'])) { $best = $n; }
        }
        if ($best) { $best['direct_offer_id'] = (string)($direct['offer_id'] ?? ''); $best['direct_listing_id'] = (string)($direct['listing_id'] ?? ''); return $best; }
        if ($direct && ($direct['sku'] ?? '') === $sku && ($direct['marketplace_id'] ?? '') === $marketplace) { return $direct; }
        $item = $this->get_json($marketplace, '/sell/inventory/v1/inventory_item/' . rawurlencode($sku));
        if (!empty($item['error']) && (int)($item['http_status'] ?? 0) !== 404) { return $item; }
        return $direct ? $direct + ['is_active_listing'=>false] : [];
    }

    private function get_json(string $marketplace, string $path): array
    {
        $token = $this->access_token($marketplace);
        if ($token === '') { return ['error'=>'safe_client_reuse_unavailable_or_missing_access_token']; }
        $res = wp_remote_request('https://api.ebay.com' . $path, ['method'=>'GET','timeout'=>25,'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','Content-Language'=>$marketplace === 'EBAY_FR' ? 'fr-FR' : 'de-DE','X-EBAY-C-MARKETPLACE-ID'=>$marketplace]]);
        $code = (int) wp_remote_retrieve_response_code($res); $body = (string) wp_remote_retrieve_body($res); $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) { return ['error'=>'eBay Inventory API HTTP '.$code,'http_status'=>$code,'body'=>$body]; }
        return is_array($json) ? $json : [];
    }
    private function normalize_offer(array $offer, string $sku): array { $status=(string)($offer['status'] ?? $offer['offerStatus'] ?? ''); $listingStatus=(string)($offer['listingStatus'] ?? $offer['listing']['listingStatus'] ?? $status); $item=$this->offer_item_id($offer); $market=(string)($offer['marketplaceId'] ?? $offer['marketplace_id'] ?? $offer['listing']['marketplaceId'] ?? ''); $qty=(string)($offer['availableQuantity'] ?? $offer['quantityLimitPerBuyer'] ?? $offer['listing']['availableQuantity'] ?? ''); return ['item_id'=>$item,'listing_id'=>$item,'offer_id'=>(string)($offer['offerId'] ?? ''),'offer_status'=>$status,'listing_status'=>$listingStatus,'marketplace_id'=>$market,'available_quantity'=>$qty,'is_published'=>$item !== '' || stripos($status,'publish') !== false,'is_active_listing'=>$item !== '' && in_array(strtolower($listingStatus ?: $status), ['published','active'], true),'sku'=>(string)($offer['sku'] ?? $sku)]; }
    private function validation_detail(string $localItem, string $localOffer, string $sku, string $marketplace, array $live): string { if (($live['listing_id'] ?? '') === '') { return 'No current published listingId found from GET offer by offer_id or GET offer by sku/marketplace.'; } $parts=[]; foreach(['sku'=>$sku,'marketplace_id'=>$marketplace,'listing_id'=>$localItem,'offer_id'=>$localOffer] as $k=>$v){ $liveKey=$k==='listing_id'?'listing_id':$k; if ($v !== '' && isset($live[$liveKey]) && (string)$live[$liveKey] !== $v) { $parts[]=$k . ' mismatch local=' . $v . ' live=' . (string)$live[$liveKey]; }} if (empty($live['is_active_listing'])) { $parts[]='listing is not active/published: offer_status=' . (string)($live['offer_status'] ?? '') . ' listing_status=' . (string)($live['listing_status'] ?? ''); } return $parts ? implode('; ', $parts) : 'Inventory API confirmed same sku, marketplace, offer_id, and active published listingId ' . (string)($live['listing_id'] ?? '') . '.'; }
    private function listing_url(string $marketplace, string $listingId): string { if ($listingId === '') { return ''; } return ($marketplace === 'EBAY_FR' ? 'https://www.ebay.fr/itm/' : 'https://www.ebay.de/itm/') . rawurlencode($listingId); }
    private function offer_item_id(array $offer): string { return trim((string)($offer['listingId'] ?? $offer['listing']['listingId'] ?? $offer['listing']['itemId'] ?? $offer['itemId'] ?? '')); }
    private function access_token(string $market): string { $authClass = $market === 'EBAY_FR' ? '\\WEI_FR\\Services\\EbayAuth' : '\\WEI\\Services\\EbayAuth'; $loggerClass = $market === 'EBAY_FR' ? '\\WEI_FR\\Services\\Logger' : '\\WEI\\Services\\Logger'; if (class_exists($authClass) && class_exists($loggerClass)) { $token = (new $authClass(new $loggerClass()))->get_valid_access_token(); return is_wp_error($token) ? '' : (string) $token; } $s = get_option($market === 'EBAY_FR' ? 'wei_fr_ebay_settings' : 'wei_ebay_settings', []); return is_array($s) && (int)($s['expires_at'] ?? 0) > time() + 120 ? (string)($s['access_token'] ?? '') : ''; }
    private function markets(string $s): array { return $s === 'fr' ? ['EBAY_FR'] : ($s === 'both' ? ['EBAY_DE','EBAY_FR'] : ['EBAY_DE']); }
    private function write_csv(string $runId, array $rows, bool $append): array { $upload=wp_upload_dir(); $dir=trailingslashit($upload['basedir']).'gps-ebay-fitment-sync'; if(!is_dir($dir)){ wp_mkdir_p($dir); } $file=$dir.'/ebay-inventory-remap-audit-'.sanitize_file_name($runId).'.csv'; $new=!file_exists($file) || !$append; $out=fopen($file,$append?'a':'w'); if($new){ fputcsv($out,self::COLUMNS); } foreach($rows as $r){ fputcsv($out,array_map(static fn($c)=>(string)($r[$c] ?? ''), self::COLUMNS)); } fclose($out); return ['path'=>$file,'url'=>trailingslashit($upload['baseurl']).'gps-ebay-fitment-sync/'.basename($file),'columns'=>self::COLUMNS]; }
}
