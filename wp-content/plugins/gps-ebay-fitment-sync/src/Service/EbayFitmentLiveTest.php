<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class EbayFitmentLiveTest
{
    public const CONFIRMATION = 'UPDATE EBAY FITMENT';
    public const INVENTORY_CONFIRMATION = 'UPDATE EBAY INVENTORY FITMENT';
    private const INVENTORY_METHOD = 'PUT';
    private const INVENTORY_PATH_TEMPLATE = '/sell/inventory/v1/inventory_item/{inventory_item_sku}/product_compatibility';

    public function __construct(private EbayFitmentPreview $preview) {}

    public function run(int $productId, string $selection, bool $dryRun, string $confirmation, string $apiMode = 'trading'): array
    {
        $apiMode = $apiMode === 'inventory' ? 'inventory' : 'trading';
        if ($apiMode === 'inventory') { return $this->run_inventory($productId, $selection, $dryRun, $confirmation); }
        $productId = max(0, $productId);
        $markets = $this->markets($selection);
        $row = $this->preview->one_product($productId);
        $results = [];
        if (!$dryRun && ($productId <= 0 || $confirmation !== self::CONFIRMATION)) {
            foreach ($markets as $market) { $results[$market] = $this->blocked($row, $market, 'live_confirmation_required'); }
            return ['dry_run' => false, 'product' => $row, 'results' => $results, 'api_method' => 'ReviseFixedPriceItem (Trading API only; inventory listings blocked)'];
        }
        foreach ($markets as $market) {
            $results[$market] = $this->process_market($row, $market, $dryRun);
        }
        return ['dry_run' => $dryRun, 'product' => $row, 'results' => $results, 'api_method' => 'ReviseFixedPriceItem (Trading API only; inventory listings blocked)'];
    }

    public function build_payload(string $itemId, array $vehicleIds): array
    {
        return [
            'ReviseFixedPriceItemRequest' => [
                'ErrorLanguage' => 'en_US',
                'WarningLevel' => 'High',
                'Item' => [
                    'ItemID' => $itemId,
                    'ItemCompatibilityList' => [
                        'Compatibility' => array_map(static fn(string $id): array => [
                            'NameValueList' => [['Name' => 'KType', 'Value' => [$id]]],
                        ], array_values(array_map('strval', $vehicleIds))),
                    ],
                ],
            ],
        ];
    }


    private function run_inventory(int $productId, string $selection, bool $dryRun, string $confirmation): array
    {
        $productId = max(0, $productId);
        $row = $this->preview->one_product($productId);
        $market = 'EBAY_FR';
        $results = [];
        if ($selection !== 'fr') {
            $results[$market] = $this->blocked($row, $market, 'inventory_live_test_fr_only');
            return ['dry_run' => $dryRun, 'product' => $row, 'results' => $results, 'api_mode' => 'inventory', 'api_method' => self::INVENTORY_METHOD . ' ' . self::INVENTORY_PATH_TEMPLATE, 'note' => 'Inventory live test is intentionally FR-only and one product per request.'];
        }
        if (!$dryRun && ($productId <= 0 || $confirmation !== self::INVENTORY_CONFIRMATION)) {
            $results[$market] = $this->blocked($row, $market, 'live_confirmation_required');
            return ['dry_run' => false, 'product' => $row, 'results' => $results, 'api_mode' => 'inventory', 'api_method' => self::INVENTORY_METHOD . ' ' . self::INVENTORY_PATH_TEMPLATE];
        }
        $results[$market] = $this->process_inventory_market($row, $dryRun);
        return ['dry_run' => $dryRun, 'product' => $row, 'results' => $results, 'api_mode' => 'inventory', 'api_method' => self::INVENTORY_METHOD . ' ' . self::INVENTORY_PATH_TEMPLATE, 'compatibility_target' => 'inventory_item_product_compatibility'];
    }

    private function process_inventory_market(?array $row, bool $dryRun): array
    {
        $market = 'EBAY_FR';
        if (!$row) { return $this->blocked($row, $market, 'product_not_found_or_not_mapped'); }
        $vehicleIds = $this->vehicle_ids($row);
        $itemId = trim((string) ($row['ebay_fr_item_id'] ?? ''));
        $offerId = trim((string) ($row['ebay_fr_offer_id'] ?? ''));
        $sku = trim((string) ($row['ebay_fr_inventory_item_sku'] ?? ''));
        $marketplaceId = trim((string) ($row['ebay_fr_inventory_marketplace'] ?? 'EBAY_FR')) ?: 'EBAY_FR';
        $endpoint = self::INVENTORY_METHOD . ' /sell/inventory/v1/inventory_item/' . ($sku !== '' ? rawurlencode($sku) : '{inventory_item_sku}') . '/product_compatibility';
        $payload = $this->inventory_payload($marketplaceId, $vehicleIds);
        $base = ['api_mode'=>'inventory','marketplace'=>$market,'item_id'=>$itemId,'itemId'=>$itemId,'offer_id'=>$offerId,'inventory_item_sku'=>$sku,'endpoint'=>$endpoint,'method'=>self::INVENTORY_METHOD,'payload'=>$payload,'count'=>count($vehicleIds),'ktype_count'=>count($vehicleIds),'merge_strategy'=>'Dedicated product_compatibility endpoint replaces only compatibility data; offer/inventory item details are not sent or overwritten.','attempted'=>false,'http_status'=>0,'warnings'=>[],'ack'=>''];
        $block = '';
        if ($itemId === '') { $block = 'missing_item_id'; }
        elseif ($offerId === '') { $block = 'missing_offer_id'; }
        elseif ($sku === '') { $block = 'missing_inventory_item_sku'; }
        elseif (!$vehicleIds) { $block = 'no_ktype'; }
        elseif ($marketplaceId !== 'EBAY_FR') { $block = 'marketplace_must_be_EBAY_FR'; }
        if ($block !== '') {
            $logId = $this->insert_inventory_log($row, $market, $itemId, $offerId, $sku, $endpoint, self::INVENTORY_METHOD, count($vehicleIds), 'blocked', $this->summary($payload), '', $block);
            return $base + ['status'=>'blocked','blocked_reason'=>$block,'error'=>$block,'logId'=>$logId,'log_id'=>$logId,'response_summary'=>''];
        }
        if ($dryRun) {
            $logId = $this->insert_inventory_log($row, $market, $itemId, $offerId, $sku, $endpoint, self::INVENTORY_METHOD, count($vehicleIds), 'preview', $this->summary($payload), 'dry-run only: no eBay write API called', '');
            return $base + ['status'=>'preview','blocked_reason'=>'','error'=>'','logId'=>$logId,'log_id'=>$logId,'response_summary'=>'dry-run only: no eBay write API called'];
        }
        $response = $this->inventory_request($sku, $payload);
        $http = (int) ($response['http_status'] ?? 0);
        $summary = $this->summary($response['body'] ?? $response);
        if (!empty($response['error'])) {
            $logId = $this->insert_inventory_log($row, $market, $itemId, $offerId, $sku, $endpoint, self::INVENTORY_METHOD, count($vehicleIds), 'error', $this->summary($payload), $summary, (string) $response['error']);
            return array_merge($base, ['attempted'=>true,'status'=>'error','http_status'=>$http,'blocked_reason'=>'','error'=>(string)$response['error'],'logId'=>$logId,'log_id'=>$logId,'response_summary'=>$summary]);
        }
        $warnings = $response['warnings'] ?? [];
        $status = $warnings ? 'warning_success' : 'success';
        $logId = $this->insert_inventory_log($row, $market, $itemId, $offerId, $sku, $endpoint, self::INVENTORY_METHOD, count($vehicleIds), $status, $this->summary($payload), $summary, '');
        return array_merge($base, ['attempted'=>true,'status'=>$status,'http_status'=>$http,'warnings'=>$warnings,'blocked_reason'=>'','error'=>'','logId'=>$logId,'log_id'=>$logId,'response_summary'=>$summary]);
    }

    private function inventory_payload(string $marketplaceId, array $vehicleIds): array
    {
        return ['marketplaceId' => $marketplaceId, 'compatibleProducts' => array_map(static fn(string $id): array => ['kTypeValue' => $id], array_values(array_map('strval', $vehicleIds)))];
    }

    private function inventory_request(string $sku, array $payload): array
    {
        $token = $this->access_token('EBAY_FR');
        if ($token === '') { return ['error' => 'safe_client_reuse_unavailable_or_missing_access_token', 'http_status' => 0, 'warnings' => []]; }
        $path = '/sell/inventory/v1/inventory_item/' . rawurlencode($sku) . '/product_compatibility';
        $res = wp_remote_request('https://api.ebay.com' . $path, ['method'=>self::INVENTORY_METHOD,'timeout'=>25,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json','Content-Language'=>'fr-FR','X-EBAY-C-MARKETPLACE-ID'=>'EBAY_FR'], 'body'=>wp_json_encode($payload)]);
        if (is_wp_error($res)) { return ['error'=>$res->get_error_message(),'http_status'=>0,'warnings'=>[]]; }
        $code = (int) wp_remote_retrieve_response_code($res); $body = (string) wp_remote_retrieve_body($res); $decoded = json_decode($body, true); $warnings = $this->response_messages(is_array($decoded) ? $decoded : []);
        if ($code < 200 || $code >= 300) { return ['error'=>$warnings[0] ?? ('eBay Inventory API HTTP '.$code),'http_status'=>$code,'warnings'=>$warnings,'body'=>is_array($decoded)?$decoded:$body]; }
        return ['http_status'=>$code,'warnings'=>$warnings,'body'=>is_array($decoded)?$decoded:$body];
    }

    private function response_messages(array $decoded): array
    {
        $out = [];
        foreach (['warnings','errors'] as $key) { foreach (($decoded[$key] ?? []) as $m) { if (is_array($m)) { $out[] = trim((string)($m['message'] ?? $m['longMessage'] ?? $m['errorId'] ?? '')); } } }
        return array_values(array_filter($out));
    }

    private function process_market(?array $row, string $market, bool $dryRun): array
    {
        if (!$row) { return $this->blocked($row, $market, 'product_not_found_or_not_mapped'); }
        $prefix = $market === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        $itemId = (string) ($row[$prefix . '_item_id'] ?? '');
        $blockKey = $market === 'EBAY_FR' ? 'blocked_reason_fr' : 'blocked_reason_de';
        $reason = (string) ($row[$blockKey] ?? '');
        $vehicleIds = $this->vehicle_ids($row);
        $localStatus = (string) ($row[$prefix . '_status'] ?? '');
        if ($reason !== '') { return $this->blocked($row, $market, $reason); }
        if ($itemId === '') { return $this->blocked($row, $market, 'missing_ebay_item_id'); }
        if (!$vehicleIds) { return $this->blocked($row, $market, 'no_ktype'); }
        $payload = $this->build_payload($itemId, $vehicleIds);
        if (!$dryRun) {
            $validation = $this->validate_live_listing($market, $itemId);
            if (!$validation['revisable']) {
                $reason = (string) ($validation['blocked_reason'] ?: 'ebay_listing_not_revisable');
                $validation['diagnostics'] = ($validation['diagnostics'] ?? []) + $this->listing_diagnostics_from_row($row, $market);
                $validation += $this->listing_diagnostics_from_row($row, $market);
                $logId = $this->insert_log($row, $market, $itemId, 'blocked', 'GetItem validation before ReviseFixedPriceItem', $this->summary($validation['diagnostics'] ?? []), $reason);
                return $this->with_listing_fields($this->result($market, $itemId, false, 'blocked', count($vehicleIds), $reason, (string) ($validation['ack'] ?? ''), $validation['warnings'] ?? [], $logId, $payload, $validation), $localStatus, $validation);
            }
        }
        if ($dryRun) {
            $logId = $this->insert_log($row, $market, $itemId, 'preview', $this->summary($payload), 'dry-run only: no eBay write API called', '');
            return $this->with_listing_fields($this->result($market, $itemId, false, 'preview', count($vehicleIds), '', '', [], $logId, $payload), $localStatus, []);
        }
        $response = $this->revise_fixed_price_item($market, $payload);
        $ack = (string) ($response['ack'] ?? '');
        $warnings = $response['warnings'] ?? [];
        if (isset($response['error'])) {
            if ($this->is_inventory_based_error($response)) {
                $reason = 'inventory_based_listing_not_supported_by_trading_api';
                $validation = $this->listing_diagnostics_from_row($row, $market) + ['revisable' => false, 'blocked_reason' => $reason, 'ack' => $ack, 'diagnostics' => ($response['diagnostics'] ?? []) + $this->listing_diagnostics_from_row($row, $market), 'errors' => $response['errors'] ?? []];
                $logId = $this->insert_log($row, $market, $itemId, 'blocked', $this->summary($payload), $this->summary($validation['diagnostics'] ?? []), $reason . ': ' . (string) $response['error']);
                return $this->with_listing_fields($this->result($market, $itemId, true, 'error_blocked', count($vehicleIds), $reason, $ack, $warnings, $logId, $payload, $validation), $localStatus, $validation);
            }
            $logId = $this->insert_log($row, $market, $itemId, 'error', $this->summary($payload), $this->summary($response['diagnostics'] ?? []), (string) $response['error']);
            return $this->with_listing_fields($this->result($market, $itemId, true, 'error', count($vehicleIds), (string) $response['error'], $ack, $warnings, $logId, $payload), $localStatus, []);
        }
        $status = $ack === 'Warning' || $warnings ? 'warning_success' : 'success';
        $logId = $this->insert_log($row, $market, $itemId, $status, $this->summary($payload), $this->summary($response['diagnostics'] ?? ['ack' => $ack, 'warnings' => $warnings]), '');
        return $this->with_listing_fields($this->result($market, $itemId, true, $status, count($vehicleIds), '', $ack, $warnings, $logId, $payload), $localStatus, []);
    }

    private function validate_live_listing(string $market, string $itemId): array
    {
        $token = $this->access_token($market);
        if ($token === '') { return ['revisable' => false, 'blocked_reason' => 'safe_client_reuse_unavailable_or_missing_access_token', 'warnings' => [], 'diagnostics' => []]; }
        $xml = '<?xml version="1.0" encoding="utf-8"?><GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents"><ErrorLanguage>en_US</ErrorLanguage><WarningLevel>High</WarningLevel><ItemID>' . htmlspecialchars($itemId, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</ItemID><DetailLevel>ReturnAll</DetailLevel></GetItemRequest>';
        $response = $this->trading_request($market, 'GetItem', $xml, $token);
        if (isset($response['error'])) { return $response + ['revisable' => false, 'blocked_reason' => $this->is_inventory_based_error($response) ? 'inventory_based_listing_not_supported_by_trading_api' : 'ebay_listing_validation_failed', 'diagnostics' => ($response['diagnostics'] ?? []) + ['listing_management_type' => $this->is_inventory_based_error($response) ? 'inventory' : 'unknown']]; }
        $body = (string) ($response['body'] ?? '');
        $state = $this->xml_value($body, 'ListingStatus') ?: $this->xml_value($body, 'SellingStatus');
        $endTime = $this->xml_value($body, 'EndTime');
        $inventoryBased = $this->is_inventory_based_response($response);
        if ($inventoryBased) {
            return $response + ['revisable' => false, 'blocked_reason' => 'inventory_based_listing_not_supported_by_trading_api', 'listing_state' => $state, 'listing_end_time' => $endTime, 'diagnostics' => array_merge($response['diagnostics'] ?? [], ['listing_state' => $state, 'listing_end_time' => $endTime, 'listing_management_type' => 'inventory', 'revisable' => false])];
        }
        $ended = in_array(strtolower($state), ['completed', 'ended'], true) || (preg_match('/<ListingDetails>.*?<EndTime>([^<]+)<\/EndTime>/is', $body, $m) && strtotime((string) $m[1]) !== false && strtotime((string) $m[1]) < time());
        return $response + ['revisable' => !$ended, 'blocked_reason' => $ended ? 'ebay_listing_ended' : '', 'listing_state' => $state, 'listing_end_time' => $endTime, 'diagnostics' => array_merge($response['diagnostics'] ?? [], ['listing_state' => $state, 'listing_end_time' => $endTime, 'revisable' => !$ended, 'listing_management_type' => 'trading'])];
    }


    private function is_inventory_based_response(array $response): bool
    {
        return $this->is_inventory_based_error($response) || stripos((string) ($response['body'] ?? ''), 'Inventory-based listing management is not currently supported') !== false;
    }

    private function is_inventory_based_error(array $response): bool
    {
        $haystack = strtolower((string) ($response['error'] ?? '') . ' ' . wp_json_encode($response['errors'] ?? []) . ' ' . wp_json_encode($response['diagnostics'] ?? []));
        return str_contains($haystack, 'inventory-based listing management is not currently supported')
            || str_contains($haystack, 'inventory based listing management is not currently supported')
            || (str_contains($haystack, 'inventory-based') && str_contains($haystack, 'not currently supported'));
    }

    private function listing_diagnostics_from_row(array $row, string $market): array
    {
        $prefix = $market === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        return [
            'marketplace' => $market,
            'item_id' => (string) ($row[$prefix . '_item_id'] ?? ''),
            'offer_id' => (string) ($row[$prefix . '_offer_id'] ?? ''),
            'inventory_item_sku' => (string) ($row[$prefix . '_inventory_item_sku'] ?? ''),
            'listing_management_type' => (string) ($row[$prefix . '_listing_management_type'] ?? 'unknown'),
            'required_future_inventory_api_fields' => ['marketplace', 'item_id', 'offer_id', 'inventory_item_sku', 'listing_management_type'],
        ];
    }

    private function revise_fixed_price_item(string $market, array $payload): array
    {
        $token = $this->access_token($market);
        if ($token === '') { return ['error' => 'safe_client_reuse_unavailable_or_missing_access_token']; }
        return $this->trading_request($market, 'ReviseFixedPriceItem', $this->payload_to_xml($payload), $token);
    }


    private function trading_request(string $market, string $callName, string $xml, string $token): array
    {
        $res = wp_remote_request('https://api.ebay.com/ws/api.dll', ['method'=>'POST','timeout'=>25,'headers'=>[
            'X-EBAY-API-CALL-NAME'=>$callName,'X-EBAY-API-SITEID'=>$market === 'EBAY_FR' ? '71' : '77','X-EBAY-API-COMPATIBILITY-LEVEL'=>'1231','X-EBAY-API-IAF-TOKEN'=>$token,'Content-Type'=>'text/xml','Accept'=>'text/xml'], 'body'=>$xml]);
        if (is_wp_error($res)) { return ['error' => $res->get_error_message(), 'warnings' => [], 'diagnostics' => []]; }
        $body = (string) wp_remote_retrieve_body($res); $code = (int) wp_remote_retrieve_response_code($res);
        $parsed = $this->parse_trading_response($body);
        $diagnostics = $parsed['diagnostics'] + ['http_code' => $code, 'call_name' => $callName];
        if ($code < 200 || $code >= 300 || !in_array($parsed['ack'], ['Success','Warning'], true) || $parsed['errors']) {
            return ['error' => (string) ($parsed['errors'][0]['message'] ?? ('eBay Trading API HTTP ' . $code)), 'ack' => $parsed['ack'], 'warnings' => $parsed['warnings'], 'errors' => $parsed['errors'], 'diagnostics' => $diagnostics, 'body' => $body];
        }
        return ['ack' => $parsed['ack'], 'warnings' => $parsed['warnings'], 'errors' => [], 'diagnostics' => $diagnostics, 'body' => $body];
    }

    public function parse_trading_response(string $body): array
    {
        $ack = $this->xml_value($body, 'Ack') ?: '';
        $warnings = []; $errors = []; $codes = []; $severities = []; $messages = [];
        if (preg_match_all('/<Errors>(.*?)<\/Errors>/is', $body, $blocks)) {
            foreach ($blocks[1] as $block) {
                $severity = $this->xml_value($block, 'SeverityCode') ?: 'Error';
                $code = $this->xml_value($block, 'ErrorCode');
                $message = $this->xml_value($block, 'LongMessage') ?: $this->xml_value($block, 'ShortMessage');
                $entry = ['severity' => $severity, 'code' => $code, 'message' => trim(wp_strip_all_tags((string) $message))];
                $codes[] = $code; $severities[] = $severity; $messages[] = substr($entry['message'], 0, 180);
                if (strcasecmp($severity, 'Warning') === 0) { $warnings[] = $entry['message']; } else { $errors[] = $entry; }
            }
        }
        return ['ack' => $ack, 'warnings' => $warnings, 'errors' => $errors, 'diagnostics' => ['ack' => $ack, 'error_count' => count($errors), 'warning_count' => count($warnings), 'error_codes' => array_values(array_filter($codes)), 'severity_codes' => $severities, 'short_messages' => $messages]];
    }

    private function xml_value(string $xml, string $tag): string
    {
        return preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/is', $xml, $m) ? html_entity_decode(trim(wp_strip_all_tags($m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
    }

    private function access_token(string $market): string
    {
        $authClass = $market === 'EBAY_FR' ? '\\WEI_FR\\Services\\EbayAuth' : '\\WEI\\Services\\EbayAuth';
        $loggerClass = $market === 'EBAY_FR' ? '\\WEI_FR\\Services\\Logger' : '\\WEI\\Services\\Logger';
        if (class_exists($authClass) && class_exists($loggerClass)) {
            $token = (new $authClass(new $loggerClass()))->get_valid_access_token();
            return is_wp_error($token) ? '' : (string) $token;
        }
        $option = $market === 'EBAY_FR' ? 'wei_fr_ebay_settings' : 'wei_ebay_settings';
        $s = get_option($option, []);
        return is_array($s) && (int) ($s['expires_at'] ?? 0) > time() + 120 ? (string) ($s['access_token'] ?? '') : '';
    }

    private function payload_to_xml(array $payload): string
    {
        $item = $payload['ReviseFixedPriceItemRequest']['Item'];
        $xml = '<?xml version="1.0" encoding="utf-8"?><ReviseFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents"><ErrorLanguage>en_US</ErrorLanguage><WarningLevel>High</WarningLevel><Item><ItemID>' . htmlspecialchars((string) $item['ItemID'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</ItemID><ItemCompatibilityList>';
        foreach ($item['ItemCompatibilityList']['Compatibility'] as $compat) { $value = (string) $compat['NameValueList'][0]['Value'][0]; $xml .= '<Compatibility><NameValueList><Name>KType</Name><Value>' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Value></NameValueList></Compatibility>'; }
        return $xml . '</ItemCompatibilityList></Item></ReviseFixedPriceItemRequest>';
    }

    private function markets(string $selection): array { return $selection === 'de' ? ['EBAY_DE'] : ($selection === 'fr' ? ['EBAY_FR'] : ['EBAY_DE','EBAY_FR']); }
    private function vehicle_ids(array $row): array { return array_values(array_filter(array_map('trim', explode(',', (string) ($row['vehicle_ids'] ?? ''))))); }
    private function blocked(?array $row, string $market, string $reason): array { $item = $row ? (string) ($row[$market === 'EBAY_FR' ? 'ebay_fr_item_id' : 'ebay_de_item_id'] ?? '') : ''; $logId = $row ? $this->insert_log($row, $market, $item, 'blocked', '', '', $reason) : 0; return $this->result($market, $item, false, 'blocked', $row ? count($this->vehicle_ids($row)) : 0, $reason, '', [], $logId, []); }
    private function result(string $market, string $itemId, bool $attempted, string $status, int $count, string $error, string $ack, array $warnings, int $logId, array $payload, array $liveValidation = []): array { return compact('market','itemId','attempted','status','count','error','ack','warnings','logId','payload','liveValidation'); }
    private function with_listing_fields(array $result, string $localStatus, array $validation): array { $result['local_listing_status'] = $localStatus; $result['live_checked_revisable'] = array_key_exists('revisable', $validation) ? (!empty($validation['revisable']) ? 'yes' : 'no') : ''; $result['live_listing_state'] = (string) ($validation['listing_state'] ?? ''); $result['listing_end_time'] = (string) ($validation['listing_end_time'] ?? ''); $result['blocked_reason'] = (string) ($validation['blocked_reason'] ?? ($result['status'] === 'blocked' || $result['status'] === 'error_blocked' ? $result['error'] : '')); $result['listing_management_type'] = (string) ($validation['diagnostics']['listing_management_type'] ?? $validation['listing_management_type'] ?? ''); $result['inventory_item_sku'] = (string) ($validation['diagnostics']['inventory_item_sku'] ?? $validation['inventory_item_sku'] ?? ''); $result['offer_id'] = (string) ($validation['diagnostics']['offer_id'] ?? $validation['offer_id'] ?? ''); $result['local_active_but_live_ended'] = strtolower(trim($localStatus)) === 'active' && $result['blocked_reason'] === 'ebay_listing_ended' ? 'yes' : 'no'; return $result; }
    private function summary(mixed $data): string { $json = is_string($data) ? $data : wp_json_encode($data, JSON_UNESCAPED_SLASHES); return strlen((string) $json) > 6000 ? substr((string) $json, 0, 6000) . '…' : (string) $json; }
    private function insert_log(array $row, string $market, string $itemId, string $status, string $request, string $response, string $error): int { global $wpdb; $wpdb->insert(Database::table_names()['ebay_sync_log'], ['product_id'=>(int)$row['product_id'],'marketplace'=>$market,'ebay_item_id'=>$itemId,'api_mode'=>'trading','part_number_normalized'=>(string)$row['part_number_normalized'],'part_cache_id'=>(int)$row['part_cache_id'],'ktype_count'=>(int)$row['ktype_count'],'status'=>$status,'request_summary'=>$request,'response_summary'=>$response,'error_message'=>$error,'created_at'=>current_time('mysql')]); return (int) $wpdb->insert_id; }
    private function insert_inventory_log(array $row, string $market, string $itemId, string $offerId, string $sku, string $endpoint, string $method, int $ktypeCount, string $status, string $request, string $response, string $error): int { global $wpdb; $summary = ['api_mode'=>'inventory','endpoint'=>$endpoint,'method'=>$method,'product_id'=>(int)$row['product_id'],'marketplace'=>$market,'item_id'=>$itemId,'offer_id'=>$offerId,'inventory_item_sku'=>$sku,'ktype_count'=>$ktypeCount,'payload'=>$request]; $wpdb->insert(Database::table_names()['ebay_sync_log'], ['product_id'=>(int)$row['product_id'],'marketplace'=>$market,'ebay_item_id'=>$itemId,'api_mode'=>'inventory','endpoint'=>$endpoint,'method'=>$method,'offer_id'=>$offerId,'inventory_item_sku'=>$sku,'part_number_normalized'=>(string)$row['part_number_normalized'],'part_cache_id'=>(int)$row['part_cache_id'],'ktype_count'=>$ktypeCount,'status'=>$status,'request_summary'=>$this->summary($summary),'response_summary'=>$response,'error_message'=>$error,'created_at'=>current_time('mysql')]); return (int) $wpdb->insert_id; }
}
