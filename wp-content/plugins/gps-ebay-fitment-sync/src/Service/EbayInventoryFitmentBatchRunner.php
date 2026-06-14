<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class EbayInventoryFitmentBatchRunner
{
    public const CONFIRMATION = 'RUN EBAY INVENTORY FITMENT BATCH';
    private const METHOD = 'PUT';
    private const PATH_TEMPLATE = '/sell/inventory/v1/inventory_item/{inventory_item_sku}/product_compatibility';
    private const CHECKPOINT_OPTION = 'gps_ebay_inventory_fitment_batch_checkpoint';

    public function __construct(private EbayFitmentPreview $preview) {}

    public function run_batch(array $args): array
    {
        $memoryStart = memory_get_usage(true);
        $mode = (string) ($args['mode'] ?? 'dry_run') === 'live' ? 'live' : 'dry_run';
        $marketplaceArg = (string) ($args['marketplace'] ?? 'both');
        $selection = $this->selection($marketplaceArg);
        $max = $mode === 'live' ? 25 : 100;
        $default = $mode === 'live' ? 5 : 25;
        $batchSize = max(1, min($max, (int) ($args['batch_size'] ?? $default)));
        if ($mode === 'live' && (string) ($args['confirmation'] ?? '') !== self::CONFIRMATION) {
            return ['ok' => false, 'error' => 'live_confirmation_required', 'required_confirmation' => self::CONFIRMATION, 'checkpoint' => $this->checkpoint()];
        }
        $checkpoint = !empty($args['resume']) ? $this->checkpoint() : [];
        $runId = (string) ($args['run_id'] ?? ($checkpoint['run_id'] ?? ''));
        if ($runId === '') { $runId = 'ebay-inventory-fitment-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false); }
        $attemptOffset = isset($args['attempt_offset']) ? max(0, (int) $args['attempt_offset']) : max(0, (int) ($checkpoint['attempt_offset'] ?? ((int) ($args['offset'] ?? 0) * count($selection))));
        $productOffset = intdiv($attemptOffset, count($selection));
        $marketStart = $attemptOffset % count($selection);
        $stop = (string) ($checkpoint['status'] ?? '') === 'stopping';
        $rows = [];
        $counters = $this->empty_counters();
        $totalAttempts = $batchSize * count($selection);
        $loaded = $this->preview->inventory_batch_candidates($marketplaceArg, $productOffset, $batchSize);
        $candidates = $loaded['rows'] ?? [];
        $attemptsBuilt = 0;
        foreach ($candidates as $candidateIndex => $row) {
            foreach ($selection as $marketIndex => $market) {
                if ($candidateIndex === 0 && $marketIndex < $marketStart) { continue; }
                if ($attemptsBuilt >= $totalAttempts || $stop) { break 2; }
                $absoluteAttempt = $attemptOffset + $attemptsBuilt;
                $counters['scanned_products'] = max($counters['scanned_products'], $productOffset + $candidateIndex + 1);
                $attempt = $this->process_attempt($runId, $row, $market, $mode, !empty($args['retry_errors']));
                $rows[] = $attempt;
                $attemptsBuilt++;
                $this->tally($counters, $attempt);
                $this->save_checkpoint(['run_id'=>$runId,'status'=>'running','mode'=>$mode,'marketplace'=>implode(',', $selection),'batch_size'=>$batchSize,'attempt_offset'=>$absoluteAttempt + 1,'product_offset'=>intdiv($absoluteAttempt + 1, count($selection)),'current_product_id'=>(int)$row['product_id'],'current_marketplace'=>$market,'updated_at'=>current_time('mysql')]);
            }
        }
        if (count($candidates) < $batchSize || $attemptsBuilt === 0) { $stop = true; }
        $csv = $this->export_csv($runId);
        $cp = $this->checkpoint();
        if ($stop) { $cp['status'] = 'completed'; $this->save_checkpoint($cp); }
        $diagnostics = ['memory_usage_start'=>$memoryStart,'memory_usage_end'=>memory_get_usage(true),'peak_memory_usage'=>memory_get_peak_usage(true),'candidate_products_loaded'=>count($candidates),'marketplace_attempts_built'=>$attemptsBuilt];
        return ['ok'=>true,'run_id'=>$runId,'mode'=>$mode,'marketplaces'=>$selection,'batch_size_products'=>$batchSize,'max_marketplace_attempts'=>$totalAttempts,'attempt_offset'=>(int)($this->checkpoint()['attempt_offset'] ?? $attemptOffset),'checkpoint'=>$this->checkpoint(),'counters'=>$counters,'rows'=>$rows,'last_rows'=>$this->last_rows($runId, 20),'csv'=>$csv,'diagnostics'=>$diagnostics];
    }

    public function stop(string $reason = 'manual_stop'): array { $cp = $this->checkpoint(); $cp['status'] = 'stopping'; $cp['stopped_reason'] = $reason; $cp['updated_at'] = current_time('mysql'); $this->save_checkpoint($cp); return $cp; }
    public function reset(): array { delete_option(self::CHECKPOINT_OPTION); return []; }
    public function checkpoint(): array { $cp = get_option(self::CHECKPOINT_OPTION, []); return is_array($cp) ? $cp : []; }

    private function process_attempt(string $runId, array $row, string $market, string $mode, bool $retryErrors): array
    {
        $prefix = $market === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        $productId = (int) $row['product_id'];
        $kt = $this->vehicle_ids($row);
        $itemId = trim((string) ($row[$prefix . '_item_id'] ?? ''));
        $offerId = trim((string) ($row[$prefix . '_offer_id'] ?? ''));
        $sku = trim((string) ($row[$prefix . '_inventory_item_sku'] ?? ''));
        $listingType = (string) ($row[$prefix . '_listing_management_type'] ?? 'unknown');
        $endpoint = self::METHOD . ' /sell/inventory/v1/inventory_item/' . ($sku !== '' ? rawurlencode($sku) : '{inventory_item_sku}') . '/product_compatibility';
        $blocked = '';
        if ($this->already_processed($runId, $productId, $market, $retryErrors)) { $blocked = 'already_processed'; }
        elseif ($productId <= 0) { $blocked = 'product_not_found_or_not_mapped'; }
        elseif (!$kt) { $blocked = 'missing_ktypes'; }
        elseif ($listingType !== 'inventory') { $blocked = 'trading_listing_not_supported'; }
        elseif ($itemId === '') { $blocked = $market === 'EBAY_FR' ? 'missing_fr_mapping' : 'missing_de_mapping'; }
        elseif ($offerId === '') { $blocked = 'missing_offer_id'; }
        elseif ($sku === '') { $blocked = 'missing_inventory_item_sku'; }
        $payload = $this->payload($kt);
        $base = ['run_id'=>$runId,'product_id'=>$productId,'marketplace'=>$market,'item_id'=>$itemId,'offer_id'=>$offerId,'inventory_item_sku'=>$sku,'part_number_normalized'=>(string)$row['part_number_normalized'],'ktype_count'=>count($kt),'sample_ktypes'=>implode(',', array_slice($kt,0,10)),'endpoint'=>$endpoint,'method'=>self::METHOD,'api_mode'=>'inventory','mode'=>$mode,'headers_summary'=>$this->headers_summary($market),'would_update'=>$blocked === '' ? 'yes' : 'no','blocked_reason'=>$blocked,'payload_summary'=>'compatibleProducts=' . count($kt) . '; productIdentifier.ktype only','attempted'=>'false','status'=>$blocked === '' ? 'preview' : ($blocked === 'already_processed' ? 'skipped' : 'blocked'),'http_status'=>0,'warnings'=>'','error_message'=>'','response_summary'=>'','created_at'=>current_time('mysql')];
        if ($blocked !== '' || $mode === 'dry_run') { $this->insert_log($base); return $base; }
        $response = $this->inventory_request($market, $sku, $payload);
        $http = (int) ($response['http_status'] ?? 0);
        $warnings = $response['warnings'] ?? [];
        $status = !empty($response['error']) ? 'error' : ($warnings ? 'warning_success' : 'success');
        $base = array_merge($base, ['attempted'=>'true','status'=>$status,'http_status'=>$http,'warnings'=>implode(' | ', array_map('strval', $warnings)),'error_message'=>(string)($response['error'] ?? ''),'response_summary'=>$this->summary($response['body'] ?? $response)]);
        $this->insert_log($base);
        return $base;
    }

    private function inventory_request(string $market, string $sku, array $payload): array
    {
        $token = $this->access_token($market);
        if ($token === '') { return ['error'=>'safe_client_reuse_unavailable_or_missing_access_token','http_status'=>0,'warnings'=>[]]; }
        $path = '/sell/inventory/v1/inventory_item/' . rawurlencode($sku) . '/product_compatibility';
        $res = wp_remote_request('https://api.ebay.com' . $path, ['method'=>self::METHOD,'timeout'=>25,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json','Content-Language'=>$market === 'EBAY_FR' ? 'fr-FR' : 'de-DE','X-EBAY-C-MARKETPLACE-ID'=>$market], 'body'=>wp_json_encode($payload)]);
        if (is_wp_error($res)) { return ['error'=>$res->get_error_message(),'http_status'=>0,'warnings'=>[]]; }
        $code = (int) wp_remote_retrieve_response_code($res); $body = (string) wp_remote_retrieve_body($res); $decoded = json_decode($body, true); $msgs = $this->response_messages(is_array($decoded) ? $decoded : []);
        if ($code < 200 || $code >= 300) { return ['error'=>$msgs[0] ?? ('eBay Inventory API HTTP '.$code),'http_status'=>$code,'warnings'=>$msgs,'body'=>is_array($decoded)?$decoded:$body]; }
        return ['http_status'=>$code,'warnings'=>$msgs,'body'=>is_array($decoded)?$decoded:$body];
    }

    private function access_token(string $market): string { $authClass = $market === 'EBAY_FR' ? '\\WEI_FR\\Services\\EbayAuth' : '\\WEI\\Services\\EbayAuth'; $loggerClass = $market === 'EBAY_FR' ? '\\WEI_FR\\Services\\Logger' : '\\WEI\\Services\\Logger'; if (class_exists($authClass) && class_exists($loggerClass)) { $token = (new $authClass(new $loggerClass()))->get_valid_access_token(); return is_wp_error($token) ? '' : (string) $token; } $s = get_option($market === 'EBAY_FR' ? 'wei_fr_ebay_settings' : 'wei_ebay_settings', []); return is_array($s) && (int)($s['expires_at'] ?? 0) > time() + 120 ? (string)($s['access_token'] ?? '') : ''; }
    private function response_messages(array $decoded): array { $out=[]; foreach(['warnings','errors'] as $key){ foreach(($decoded[$key] ?? []) as $m){ if(is_array($m)){ $out[] = trim((string)($m['message'] ?? $m['longMessage'] ?? $m['errorId'] ?? '')); }}} return array_values(array_filter($out)); }
    private function selection(string $s): array { return $s === 'fr' ? ['EBAY_FR'] : ($s === 'de' ? ['EBAY_DE'] : ['EBAY_FR','EBAY_DE']); }
    private function vehicle_ids(array $row): array { return array_values(array_filter(array_map('trim', explode(',', (string)($row['vehicle_ids'] ?? ''))))); }
    private function payload(array $kt): array { return ['compatibleProducts'=>array_map(static fn(string $id): array => ['productIdentifier'=>['ktype'=>$id]], array_values(array_map('strval', $kt)))]; }
    private function headers_summary(string $market): array { return ['Authorization'=>'Bearer [redacted]','Content-Type'=>'application/json','Accept'=>'application/json','Content-Language'=>$market === 'EBAY_FR' ? 'fr-FR' : 'de-DE','X-EBAY-C-MARKETPLACE-ID'=>$market]; }
    private function summary(mixed $data): string { $json = is_string($data) ? $data : wp_json_encode($data, JSON_UNESCAPED_SLASHES); return strlen((string)$json) > 6000 ? substr((string)$json,0,6000).'…' : (string)$json; }
    private function already_processed(string $runId, int $productId, string $market, bool $retryErrors): bool { global $wpdb; $t=Database::table_names()['ebay_sync_log']; $statuses = $retryErrors ? "'success','warning_success'" : "'success','warning_success','error','blocked','preview'"; return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE run_id=%s AND product_id=%d AND marketplace=%s AND api_mode='inventory' AND status IN ({$statuses})", $runId, $productId, $market)) > 0; }
    private function insert_log(array $r): int { global $wpdb; $wpdb->insert(Database::table_names()['ebay_sync_log'], ['run_id'=>$r['run_id'],'product_id'=>$r['product_id'],'marketplace'=>$r['marketplace'],'ebay_item_id'=>$r['item_id'],'api_mode'=>'inventory','endpoint'=>$r['endpoint'],'method'=>'PUT','offer_id'=>$r['offer_id'],'inventory_item_sku'=>$r['inventory_item_sku'],'part_number_normalized'=>$r['part_number_normalized'],'ktype_count'=>$r['ktype_count'],'mode'=>$r['mode'],'attempted'=>$r['attempted']==='true'?1:0,'http_status'=>$r['http_status'],'blocked_reason'=>$r['blocked_reason'],'warnings'=>$r['warnings'],'status'=>$r['status'],'request_summary'=>wp_json_encode(['headers'=>$r['headers_summary'],'payload'=>$r['payload_summary']]),'response_summary'=>$r['response_summary'],'error_message'=>$r['error_message'],'created_at'=>$r['created_at']]); return (int)$wpdb->insert_id; }
    private function last_rows(string $runId, int $limit): array { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Database::table_names()['ebay_sync_log'].' WHERE run_id=%s ORDER BY id DESC LIMIT %d', $runId, $limit), ARRAY_A) ?: []; }
    private function empty_counters(): array { return array_fill_keys(['scanned_products','marketplace_attempts','eligible','attempted','success','warning_success','blocked','skipped','errors','attempted_fr','success_fr','warning_success_fr','errors_fr','blocked_fr','attempted_de','success_de','warning_success_de','errors_de','blocked_de'], 0); }
    private function tally(array &$c, array $r): void { $c['marketplace_attempts']++; if($r['would_update']==='yes')$c['eligible']++; if($r['attempted']==='true')$c['attempted']++; $s=$r['status']; if($s==='success')$c['success']++; elseif($s==='warning_success')$c['warning_success']++; elseif($s==='blocked')$c['blocked']++; elseif($s==='skipped')$c['skipped']++; elseif($s==='error')$c['errors']++; $mk=$r['marketplace']==='EBAY_FR'?'fr':'de'; if($r['attempted']==='true')$c['attempted_'.$mk]++; foreach(['success','warning_success'] as $k){ if($s===$k)$c[$k.'_'.$mk]++; } if($s==='error')$c['errors_'.$mk]++; if($s==='blocked')$c['blocked_'.$mk]++; }
    private function save_checkpoint(array $cp): void { update_option(self::CHECKPOINT_OPTION, $cp, false); }
    public function export_csv(string $runId): array { global $wpdb; $upload=wp_upload_dir(); $dir=trailingslashit($upload['basedir']).'gps-ebay-fitment-sync'; if(!is_dir($dir)){ wp_mkdir_p($dir); } $file=$dir.'/ebay-inventory-fitment-'.$runId.'.csv'; $url=trailingslashit($upload['baseurl']).'gps-ebay-fitment-sync/'.basename($file); $out=fopen($file,'w'); $cols=['run_id','product_id','marketplace','item_id','offer_id','inventory_item_sku','part_number_normalized','ktype_count','sample_ktypes','endpoint','attempted','status','http_status','blocked_reason','warning_count','warnings','error_message','response_summary','created_at']; fputcsv($out,$cols); $lastId=0; $table=Database::table_names()['ebay_sync_log']; do { $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE run_id=%s AND id > %d ORDER BY id ASC LIMIT %d", $runId, $lastId, 500),ARRAY_A) ?: []; foreach($rows as $r){ $lastId=max($lastId,(int)($r['id'] ?? 0)); fputcsv($out,[$runId,$r['product_id'],$r['marketplace'],$r['ebay_item_id'],$r['offer_id'],$r['inventory_item_sku'],$r['part_number_normalized'],$r['ktype_count'],'',$r['endpoint'],empty($r['attempted'])?'false':'true',$r['status'],$r['http_status'] ?? '',$r['blocked_reason'] ?? '',substr_count((string)($r['warnings'] ?? ''),'|') + ((string)($r['warnings'] ?? '')!=='' ? 1 : 0),$r['warnings'] ?? '',$r['error_message'] ?? '',$r['response_summary'] ?? '',$r['created_at']]); } } while (count($rows) === 500); fclose($out); return ['path'=>$file,'url'=>$url,'columns'=>$cols]; }

}
