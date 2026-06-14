<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class EbayInventoryFitmentBatchRunner
{
    public const CONFIRMATION = 'RUN EBAY INVENTORY FITMENT AUTO';
    private const METHOD = 'PUT';
    private const PATH_TEMPLATE = '/sell/inventory/v1/inventory_item/{inventory_item_sku}/product_compatibility';
    private const CHECKPOINT_OPTION = 'gps_ebay_inventory_fitment_batch_checkpoint';
    private const REMAP_BLOCK_REASON = 'stale_or_unconfirmed_listing_mapping';
    private const TICK_LOCK_TTL = 300;
    private const ATTEMPT_LOCK_TTL = 300;
    private const TERMINAL_STATUSES = ['success', 'warning_success', 'preview', 'blocked', 'error', 'already_processed', 'skipped'];

    public function __construct(private EbayFitmentPreview $preview, private ?EbayInventoryRemapAudit $remapAudit = null) {}

    public function run_batch(array $args): array
    {
        $memoryStart = memory_get_usage(true);
        $tickLockAcquired = false;
        $concurrentTickBlockedCount = 0;
        $skippedAlreadyProcessedCount = 0;
        $mode = (string) ($args['mode'] ?? 'dry_run') === 'live' ? 'live' : 'dry_run';
        $marketplaceArg = (string) ($args['marketplace'] ?? 'both');
        $selection = $this->selection($marketplaceArg);
        $max = $mode === 'live' ? 25 : 100;
        $default = $mode === 'live' ? 10 : 25;
        $batchSize = max(1, min($max, (int) ($args['batch_size'] ?? $default)));
        if ($mode === 'live' && (string) ($args['confirmation'] ?? '') !== self::CONFIRMATION) {
            return ['ok' => false, 'error' => 'live_confirmation_required', 'required_confirmation' => self::CONFIRMATION, 'checkpoint' => $this->checkpoint()];
        }
        $storedCheckpoint = $this->checkpoint();
        $requestedRunId = (string) ($args['run_id'] ?? '');
        $checkpoint = $this->checkpoint_for_tick($storedCheckpoint, $args, $requestedRunId, $mode, $selection, $batchSize);
        $runId = (string) ($requestedRunId !== '' ? $requestedRunId : ($checkpoint['run_id'] ?? ''));
        if ($runId === '') { $runId = 'ebay-inventory-fitment-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false); }
        $tickLockAcquired = $this->acquire_lock($this->tick_lock_key($runId));
        if (!$tickLockAcquired) {
            $concurrentTickBlockedCount = 1;
            return $this->progress_response($runId, $mode, $selection, $batchSize, 0, [], ['memory_usage_start'=>$memoryStart,'memory_usage_end'=>memory_get_usage(true),'peak_memory_usage'=>memory_get_peak_usage(true),'candidate_products_loaded'=>0,'marketplace_attempts_built'=>0,'tick_lock_acquired'=>'no','concurrent_tick_blocked_count'=>$concurrentTickBlockedCount,'skipped_already_processed_count'=>0], ['concurrent_tick_blocked_count'=>$concurrentTickBlockedCount]);
        }
        try {
        $attemptOffset = isset($args['attempt_offset']) && (int) $args['attempt_offset'] > 0 ? max(0, (int) $args['attempt_offset']) : max(0, (int) ($checkpoint['attempt_offset'] ?? ((int) ($args['offset'] ?? 0) * count($selection))));
        $productOffset = intdiv($attemptOffset, count($selection));
        $marketStart = $attemptOffset % count($selection);
        $checkpointStatus = (string) ($checkpoint['status'] ?? '');
        if (!empty($args['resume']) && in_array($checkpointStatus, ['stopping', 'stopped'], true)) {
            $checkpoint['status'] = 'stopped';
            $checkpoint['updated_at'] = current_time('mysql');
            $this->save_checkpoint($checkpoint);
            return $this->progress_response($runId, $mode, $selection, $batchSize, 0, [], ['memory_usage_start'=>$memoryStart,'memory_usage_end'=>memory_get_usage(true),'peak_memory_usage'=>memory_get_peak_usage(true),'candidate_products_loaded'=>0,'marketplace_attempts_built'=>0]);
        }
        $stop = false;
        $rows = [];
        $counters = $this->empty_counters();
        $totalAttempts = $batchSize * count($selection);
        $loaded = $this->preview->inventory_batch_candidates($marketplaceArg, $productOffset, $batchSize);
        $candidates = $this->dedupe_candidates_by_product($loaded['rows'] ?? []);
        $attemptsBuilt = 0;
        $attemptKeys = [];
        $skipExistingRunAttempts = empty($args['reset']);
        foreach ($candidates as $candidateIndex => $row) {
            foreach ($selection as $marketIndex => $market) {
                if ($candidateIndex === 0 && $marketIndex < $marketStart) { continue; }
                if ($attemptsBuilt >= $totalAttempts || $stop) { break 2; }
                $productId = (int) ($row['product_id'] ?? 0);
                $attemptKey = $productId . '|' . $market;
                if (isset($attemptKeys[$attemptKey])) { continue; }
                $attemptKeys[$attemptKey] = true;
                $absoluteAttempt = $attemptOffset + $attemptsBuilt;
                $counters['scanned_products'] = max($counters['scanned_products'], $productOffset + $candidateIndex + 1);
                if ($skipExistingRunAttempts && $this->has_run_attempt($runId, $productId, $market, !empty($args['retry_errors']))) {
                    $skippedAlreadyProcessedCount++;
                    $attemptsBuilt++;
                    $this->save_checkpoint(['run_id'=>$runId,'status'=>'running','mode'=>$mode,'marketplace'=>implode(',', $selection),'batch_size'=>$batchSize,'attempt_offset'=>$absoluteAttempt + 1,'product_offset'=>intdiv($absoluteAttempt + 1, count($selection)),'current_product_id'=>$productId,'current_marketplace'=>$market,'updated_at'=>current_time('mysql')]);
                    continue;
                }
                $attempt = $this->process_attempt($runId, $row, $market, $mode, !empty($args['retry_errors']), $skipExistingRunAttempts);
                if (($attempt['duplicate_guard_result'] ?? '') === 'skipped_already_processed') { $skippedAlreadyProcessedCount++; }
                $rows[] = $attempt;
                $attemptsBuilt++;
                $this->tally($counters, $attempt);
                $this->save_checkpoint(['run_id'=>$runId,'status'=>'running','mode'=>$mode,'marketplace'=>implode(',', $selection),'batch_size'=>$batchSize,'attempt_offset'=>$absoluteAttempt + 1,'product_offset'=>intdiv($absoluteAttempt + 1, count($selection)),'current_product_id'=>(int)$row['product_id'],'current_marketplace'=>$market,'updated_at'=>current_time('mysql')]);
            }
        }
        if (count($candidates) < $batchSize || $attemptsBuilt === 0) { $stop = true; }
        $cp = $this->checkpoint();
        if ($stop) { $cp['status'] = 'completed'; $cp['updated_at'] = current_time('mysql'); $this->save_checkpoint($cp); }
        $diagnostics = ['memory_usage_start'=>$memoryStart,'memory_usage_end'=>memory_get_usage(true),'peak_memory_usage'=>memory_get_peak_usage(true),'candidate_products_loaded'=>count($candidates),'marketplace_attempts_built'=>$attemptsBuilt,'tick_lock_acquired'=>'yes','concurrent_tick_blocked_count'=>$concurrentTickBlockedCount,'skipped_already_processed_count'=>$skippedAlreadyProcessedCount];
        if ($diagnostics['peak_memory_usage'] > 180 * 1024 * 1024) { $diagnostics['memory_warning'] = 'high_memory_usage'; }
        return $this->progress_response($runId, $mode, $selection, $batchSize, $totalAttempts, $rows, $diagnostics, $counters);
        } finally {
            $this->release_lock($this->tick_lock_key($runId));
        }
    }

    private function checkpoint_for_tick(array $stored, array $args, string $requestedRunId, string $mode, array $selection, int $batchSize): array
    {
        if (!empty($args['reset']) || (isset($args['offset']) && (int) $args['offset'] > 0) || (isset($args['attempt_offset']) && (int) $args['attempt_offset'] > 0)) { return []; }
        if (!empty($args['resume'])) { return $stored; }
        if (!$stored) { return []; }
        $storedRunId = (string) ($stored['run_id'] ?? '');
        if ($requestedRunId !== '' && $storedRunId !== $requestedRunId) { return []; }
        if ((string) ($stored['mode'] ?? '') !== $mode) { return []; }
        if ((string) ($stored['marketplace'] ?? '') !== implode(',', $selection)) { return []; }
        if ((int) ($stored['batch_size'] ?? 0) !== $batchSize) { return []; }
        if (in_array((string) ($stored['status'] ?? ''), ['completed', 'stopped'], true)) { return []; }
        return $stored;
    }

    public function stop(string $reason = 'manual_stop'): array { $cp = $this->checkpoint(); $cp['status'] = 'stopped'; $cp['stopped_reason'] = $reason; $cp['updated_at'] = current_time('mysql'); $this->save_checkpoint($cp); return $cp; }
    public function reset(): array { delete_option(self::CHECKPOINT_OPTION); return []; }
    public function checkpoint(): array { $cp = get_option(self::CHECKPOINT_OPTION, []); return is_array($cp) ? $cp : []; }

    private function process_attempt(string $runId, array $row, string $market, string $mode, bool $retryErrors, bool $skipExistingRunAttempts = true): array
    {
        $prefix = $market === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        $productId = (int) $row['product_id'];
        $kt = $this->vehicle_ids($row);
        $itemId = trim((string) ($row[$prefix . '_item_id'] ?? ''));
        $offerId = trim((string) ($row[$prefix . '_offer_id'] ?? ''));
        $sku = trim((string) ($row[$prefix . '_inventory_item_sku'] ?? ''));
        $listingType = (string) ($row[$prefix . '_listing_management_type'] ?? 'unknown');
        $endpoint = self::METHOD . ' /sell/inventory/v1/inventory_item/' . ($sku !== '' ? rawurlencode($sku) : '{inventory_item_sku}') . '/product_compatibility';
        $prior = $this->latest_run_terminal_attempt($runId, $productId, $market);
        $priorStatus = (string) ($prior['status'] ?? '');
        $priorCreatedAt = (string) ($prior['created_at'] ?? '');
        $duplicateGuardResult = 'not_checked';
        $blocked = '';
        if ($skipExistingRunAttempts && $priorStatus !== '' && (!$retryErrors || $priorStatus !== 'error')) {
            $blocked = 'already_processed';
            $duplicateGuardResult = 'skipped_already_processed';
        } elseif ($productId <= 0) { $blocked = 'product_not_found_or_not_mapped'; }
        elseif (!$kt) { $blocked = 'missing_ktypes'; }
        elseif ($listingType !== 'inventory') { $blocked = 'trading_listing_not_supported'; }
        elseif ($itemId === '') { $blocked = $market === 'EBAY_FR' ? 'missing_fr_mapping' : 'missing_de_mapping'; }
        elseif ($offerId === '') { $blocked = 'missing_offer_id'; }
        elseif ($sku === '') { $blocked = 'missing_inventory_item_sku'; }
        $remapValidation = null;
        if ($blocked === '' && $mode === 'live' && $this->remapAudit) {
            $remapValidation = $this->remapAudit->validate_before_write($row, $market);
            if (empty($remapValidation['ok'])) { $blocked = self::REMAP_BLOCK_REASON; }
        }
        $payload = $this->payload($kt);
        $base = ['run_id'=>$runId,'product_id'=>$productId,'marketplace'=>$market,'item_id'=>$itemId,'offer_id'=>$offerId,'inventory_item_sku'=>$sku,'part_number_normalized'=>(string)$row['part_number_normalized'],'ktype_count'=>count($kt),'sample_ktypes'=>implode(',', array_slice($kt,0,10)),'endpoint'=>$endpoint,'method'=>self::METHOD,'api_mode'=>'inventory','mode'=>$mode,'headers_summary'=>$this->headers_summary($market),'would_update'=>$blocked === '' ? 'yes' : 'no','blocked_reason'=>$blocked,'payload_summary'=>'compatibleProducts=' . count($kt) . '; productIdentifier.ktype only','attempted'=>'false','status'=>$blocked === '' ? 'preview' : ($blocked === 'already_processed' ? 'already_processed' : 'blocked'),'http_status'=>0,'warnings'=>'','error_message'=>$blocked === self::REMAP_BLOCK_REASON ? (string)($remapValidation['audit']['validation_detail'] ?? '') : '','response_summary'=>$remapValidation ? $this->summary(['remap_audit'=>$remapValidation['audit'] ?? []]) : '','created_at'=>current_time('mysql'),'duplicate_guard_result'=>$duplicateGuardResult,'prior_run_status'=>$priorStatus,'prior_run_created_at'=>$priorCreatedAt,'tick_lock_acquired'=>'yes'];
        if ($blocked === 'already_processed') { return $base; }
        if ($blocked !== '' || $mode === 'dry_run') { $this->insert_log($base); return $base; }

        $attemptLockKey = $this->attempt_lock_key($runId, $productId, $market);
        if (!$this->acquire_lock($attemptLockKey)) {
            return array_merge($base, ['would_update'=>'no','blocked_reason'=>'already_processed','status'=>'already_processed','duplicate_guard_result'=>'concurrent_attempt_blocked']);
        }
        try {
            $prior = $this->latest_run_terminal_attempt($runId, $productId, $market);
            $priorStatus = (string) ($prior['status'] ?? '');
            $priorCreatedAt = (string) ($prior['created_at'] ?? '');
            if ($priorStatus !== '' && (!$retryErrors || $priorStatus !== 'error')) {
                return array_merge($base, ['would_update'=>'no','blocked_reason'=>'already_processed','status'=>'already_processed','duplicate_guard_result'=>'skipped_already_processed','prior_run_status'=>$priorStatus,'prior_run_created_at'=>$priorCreatedAt]);
            }
            $base['duplicate_guard_result'] = $priorStatus === 'error' && $retryErrors ? 'retrying_prior_error' : 'claimed_before_put';
            $base['prior_run_status'] = $priorStatus;
            $base['prior_run_created_at'] = $priorCreatedAt;
            $response = $this->inventory_request($market, $sku, $payload);
            $http = (int) ($response['http_status'] ?? 0);
            $warnings = $response['warnings'] ?? [];
            $status = !empty($response['error']) ? 'error' : ($warnings ? 'warning_success' : 'success');
            $base = array_merge($base, ['attempted'=>'true','status'=>$status,'http_status'=>$http,'warnings'=>implode(' | ', array_map('strval', $warnings)),'error_message'=>(string)($response['error'] ?? ''),'response_summary'=>$this->summary($response['body'] ?? $response)]);
            $this->insert_log($base);
            return $base;
        } finally {
            $this->release_lock($attemptLockKey);
        }
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
    private function already_processed(string $runId, int $productId, string $market, bool $retryErrors): bool { return $this->has_run_attempt($runId, $productId, $market, $retryErrors); }
    private function has_run_attempt(string $runId, int $productId, string $market, bool $retryErrors = false): bool { $latest = $this->latest_run_terminal_attempt($runId, $productId, $market); $status = (string) ($latest['status'] ?? ''); return $status !== '' && (!$retryErrors || $status !== 'error'); }
    private function latest_run_terminal_attempt(string $runId, int $productId, string $market): array { global $wpdb; if ($runId === '' || $productId <= 0 || $market === '') { return []; } $t=Database::table_names()['ebay_sync_log']; $placeholders=implode(',', array_fill(0, count(self::TERMINAL_STATUSES), '%s')); $sql="SELECT status, created_at, id FROM {$t} WHERE run_id=%s AND product_id=%d AND marketplace=%s AND api_mode='inventory' AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1"; return $wpdb->get_row($wpdb->prepare($sql, array_merge([$runId, $productId, $market], self::TERMINAL_STATUSES)), ARRAY_A) ?: []; }
    private function tick_lock_key(string $runId): string { return 'gps_ebay_inv_fit_tick_lock_' . md5($runId); }
    private function attempt_lock_key(string $runId, int $productId, string $market): string { return 'gps_ebay_inv_fit_attempt_lock_' . md5($runId . '|' . $productId . '|' . $market); }
    private function acquire_lock(string $key): bool { $expires = time() + (($key !== '' && strpos($key, '_tick_lock_') !== false) ? self::TICK_LOCK_TTL : self::ATTEMPT_LOCK_TTL); if (add_option($key, (string) $expires, '', 'no')) { return true; } $existing = (int) get_option($key, 0); if ($existing > 0 && $existing < time()) { delete_option($key); return add_option($key, (string) $expires, '', 'no'); } return false; }
    private function release_lock(string $key): void { delete_option($key); }
    private function dedupe_candidates_by_product(array $candidates): array { $out=[]; $seen=[]; foreach ($candidates as $candidate) { if (!is_array($candidate)) { continue; } $productId=(int)($candidate['product_id'] ?? 0); if ($productId <= 0 || isset($seen[$productId])) { continue; } $seen[$productId]=true; $out[]=$candidate; } return $out; }
    private function insert_log(array $r): int { global $wpdb; $wpdb->insert(Database::table_names()['ebay_sync_log'], ['run_id'=>$r['run_id'],'product_id'=>$r['product_id'],'marketplace'=>$r['marketplace'],'ebay_item_id'=>$r['item_id'],'api_mode'=>'inventory','endpoint'=>$r['endpoint'],'method'=>'PUT','offer_id'=>$r['offer_id'],'inventory_item_sku'=>$r['inventory_item_sku'],'part_number_normalized'=>$r['part_number_normalized'],'ktype_count'=>$r['ktype_count'],'mode'=>$r['mode'],'attempted'=>$r['attempted']==='true'?1:0,'http_status'=>$r['http_status'],'blocked_reason'=>$r['blocked_reason'],'warnings'=>$r['warnings'],'status'=>$r['status'],'request_summary'=>wp_json_encode(['headers'=>$r['headers_summary'],'payload'=>$r['payload_summary']]),'response_summary'=>$r['response_summary'],'error_message'=>$r['error_message'],'created_at'=>$r['created_at']]); return (int)$wpdb->insert_id; }
    private function last_rows(string $runId, int $limit): array { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Database::table_names()['ebay_sync_log'].' WHERE run_id=%s ORDER BY id DESC LIMIT %d', $runId, $limit), ARRAY_A) ?: []; }
    private function empty_counters(): array { return array_fill_keys(['scanned_products','marketplace_attempts','eligible','preview','attempted','success','warning_success','blocked','skipped','errors','preview_fr','attempted_fr','success_fr','warning_success_fr','errors_fr','blocked_fr','preview_de','attempted_de','success_de','warning_success_de','errors_de','blocked_de'], 0); }
    private function tally(array &$c, array $r): void { $c['marketplace_attempts']++; if($r['would_update']==='yes')$c['eligible']++; if($r['attempted']==='true')$c['attempted']++; $s=$r['status']; if($s==='preview')$c['preview']++; elseif($s==='success')$c['success']++; elseif($s==='warning_success')$c['warning_success']++; elseif($s==='blocked')$c['blocked']++; elseif($s==='skipped'||$s==='already_processed')$c['skipped']++; elseif($s==='error')$c['errors']++; $mk=$r['marketplace']==='EBAY_FR'?'fr':'de'; if($s==='preview')$c['preview_'.$mk]++; if($r['attempted']==='true')$c['attempted_'.$mk]++; foreach(['success','warning_success'] as $k){ if($s===$k)$c[$k.'_'.$mk]++; } if($s==='error')$c['errors_'.$mk]++; if($s==='blocked')$c['blocked_'.$mk]++; }
    private function save_checkpoint(array $cp): void { update_option(self::CHECKPOINT_OPTION, $cp, false); }
    public function export_csv(string $runId): array { global $wpdb; $upload=wp_upload_dir(); $dir=trailingslashit($upload['basedir']).'gps-ebay-fitment-sync'; if(!is_dir($dir)){ wp_mkdir_p($dir); } $file=$dir.'/ebay-inventory-fitment-auto-'.$runId.'.csv'; $url=trailingslashit($upload['baseurl']).'gps-ebay-fitment-sync/'.basename($file); $out=fopen($file,'w'); $debugCols=['local_item_id','local_offer_id','local_sku','local_listing_status','live_item_id','live_offer_id','live_offer_status','live_listing_id','live_listing_status','live_listing_url','live_available_quantity','live_marketplace_id','validation_decision','validation_detail']; $cols=array_merge(['run_id','product_id','marketplace','item_id','offer_id','inventory_item_sku','part_number_normalized','ktype_count','sample_ktypes','endpoint','attempted','status','http_status','blocked_reason','warning_count','warnings','error_message','response_summary','created_at'],$debugCols); fputcsv($out,$cols); $lastId=PHP_INT_MAX; $seenCsvRows=[]; $table=Database::table_names()['ebay_sync_log']; do { $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE run_id=%s AND id < %d ORDER BY id DESC LIMIT %d", $runId, $lastId, 500),ARRAY_A) ?: []; foreach($rows as $r){ $lastId=min($lastId,(int)($r['id'] ?? 0)); $csvKey=(int)($r['product_id'] ?? 0).'|'.(string)($r['marketplace'] ?? ''); if (isset($seenCsvRows[$csvKey])) { continue; } $seenCsvRows[$csvKey]=true; $audit=$this->audit_from_response((string)($r['response_summary'] ?? '')); fputcsv($out,array_merge([$runId,$r['product_id'],$r['marketplace'],$r['ebay_item_id'],$r['offer_id'],$r['inventory_item_sku'],$r['part_number_normalized'],$r['ktype_count'],'',$r['endpoint'],empty($r['attempted'])?'false':'true',$r['status'],$r['http_status'] ?? '',$r['blocked_reason'] ?? '',substr_count((string)($r['warnings'] ?? ''),'|') + ((string)($r['warnings'] ?? '')!=='' ? 1 : 0),$r['warnings'] ?? '',$r['error_message'] ?? '',$r['response_summary'] ?? '',$r['created_at']], array_map(static fn($c)=>(string)($audit[$c] ?? ''), $debugCols))); } } while (count($rows) === 500); fclose($out); return ['path'=>$file,'url'=>$url,'columns'=>$cols]; }
    private function audit_from_response(string $summary): array { $decoded=json_decode($summary, true); return is_array($decoded) && isset($decoded['remap_audit']) && is_array($decoded['remap_audit']) ? $decoded['remap_audit'] : []; }

    private function progress_response(string $runId, string $mode, array $selection, int $batchSize, int $totalAttempts, array $rows, array $diagnostics, array $tickCounters = []): array
    {
        $csvUrl = '';
        if ($runId !== '' && function_exists('admin_url')) {
            $csvUrl = admin_url('admin-post.php?action=gps_ebay_inventory_fitment_csv&run_id=' . rawurlencode($runId));
            if (function_exists('wp_nonce_url')) {
                $csvUrl = wp_nonce_url($csvUrl, 'gps_ebay_inventory_fitment_csv');
            }
        }
        $csv = $runId !== '' ? ['url' => $csvUrl, 'streamed' => true] : [];
        $checkpoint = $this->checkpoint();
        $aggregate = $this->aggregate_counters($runId);
        if ($tickCounters && (int) ($aggregate['marketplace_attempts'] ?? 0) === 0) {
            $aggregate = $tickCounters;
        } elseif ($tickCounters) {
            $aggregate['tick'] = $tickCounters;
        }
        return ['ok'=>true,'run_id'=>$runId,'state'=>(string)($checkpoint['status'] ?? 'idle'),'mode'=>$mode,'marketplaces'=>$selection,'marketplace_selection'=>implode(',', $selection),'batch_size_products'=>$batchSize,'max_marketplace_attempts'=>$totalAttempts,'attempt_offset'=>(int)($checkpoint['attempt_offset'] ?? 0),'checkpoint'=>$checkpoint,'counters'=>$aggregate,'rows'=>$rows,'last_rows'=>$this->last_rows($runId, 20),'csv'=>$csv,'diagnostics'=>$diagnostics,'last_error'=>$this->last_error($runId),'error_count'=>(int)($aggregate['errors'] ?? 0)];
    }

    private function aggregate_counters(string $runId): array
    {
        $c = $this->empty_counters();
        if ($runId === '') { return $c; }
        global $wpdb;
        $table = Database::table_names()['ebay_sync_log'];
        $summary = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT product_id) AS scanned_products,
                COUNT(*) AS marketplace_attempts,
                SUM(CASE WHEN blocked_reason='' THEN 1 ELSE 0 END) AS eligible,
                SUM(CASE WHEN status='preview' THEN 1 ELSE 0 END) AS preview,
                SUM(CASE WHEN attempted=1 THEN 1 ELSE 0 END) AS attempted,
                SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status='warning_success' THEN 1 ELSE 0 END) AS warning_success,
                SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) AS blocked,
                SUM(CASE WHEN status IN ('skipped','already_processed') THEN 1 ELSE 0 END) AS skipped,
                SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN marketplace='EBAY_FR' AND status='preview' THEN 1 ELSE 0 END) AS preview_fr,
                SUM(CASE WHEN marketplace='EBAY_FR' AND attempted=1 THEN 1 ELSE 0 END) AS attempted_fr,
                SUM(CASE WHEN marketplace='EBAY_FR' AND status='success' THEN 1 ELSE 0 END) AS success_fr,
                SUM(CASE WHEN marketplace='EBAY_FR' AND status='warning_success' THEN 1 ELSE 0 END) AS warning_success_fr,
                SUM(CASE WHEN marketplace='EBAY_FR' AND status='error' THEN 1 ELSE 0 END) AS errors_fr,
                SUM(CASE WHEN marketplace='EBAY_FR' AND status='blocked' THEN 1 ELSE 0 END) AS blocked_fr,
                SUM(CASE WHEN marketplace='EBAY_DE' AND status='preview' THEN 1 ELSE 0 END) AS preview_de,
                SUM(CASE WHEN marketplace='EBAY_DE' AND attempted=1 THEN 1 ELSE 0 END) AS attempted_de,
                SUM(CASE WHEN marketplace='EBAY_DE' AND status='success' THEN 1 ELSE 0 END) AS success_de,
                SUM(CASE WHEN marketplace='EBAY_DE' AND status='warning_success' THEN 1 ELSE 0 END) AS warning_success_de,
                SUM(CASE WHEN marketplace='EBAY_DE' AND status='error' THEN 1 ELSE 0 END) AS errors_de,
                SUM(CASE WHEN marketplace='EBAY_DE' AND status='blocked' THEN 1 ELSE 0 END) AS blocked_de
            FROM {$table} WHERE run_id=%s AND api_mode='inventory'", $runId), ARRAY_A) ?: [];
        foreach ($c as $key => $_) { $c[$key] = (int) ($summary[$key] ?? 0); }
        $c['scanned_products_total'] = $c['scanned_products'];
        $c['marketplace_attempts_total'] = $c['marketplace_attempts'];
        $c['blocked_total'] = $c['blocked'];
        return $c;
    }

    private function last_error(string $runId): string
    {
        if ($runId === '') { return ''; }
        global $wpdb;
        $table = Database::table_names()['ebay_sync_log'];
        return (string) $wpdb->get_var($wpdb->prepare("SELECT error_message FROM {$table} WHERE run_id=%s AND api_mode='inventory' AND status='error' ORDER BY id DESC LIMIT 1", $runId));
    }

}
