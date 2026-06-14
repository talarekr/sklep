<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class EbayFitmentLiveTest
{
    public const CONFIRMATION = 'UPDATE EBAY FITMENT';

    public function __construct(private EbayFitmentPreview $preview) {}

    public function run(int $productId, string $selection, bool $dryRun, string $confirmation): array
    {
        $productId = max(0, $productId);
        $markets = $this->markets($selection);
        $row = $this->preview->one_product($productId);
        $results = [];
        if (!$dryRun && ($productId <= 0 || $confirmation !== self::CONFIRMATION)) {
            foreach ($markets as $market) { $results[$market] = $this->blocked($row, $market, 'live_confirmation_required'); }
            return ['dry_run' => false, 'product' => $row, 'results' => $results, 'api_method' => 'ReviseFixedPriceItem'];
        }
        foreach ($markets as $market) {
            $results[$market] = $this->process_market($row, $market, $dryRun);
        }
        return ['dry_run' => $dryRun, 'product' => $row, 'results' => $results, 'api_method' => 'ReviseFixedPriceItem'];
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

    private function process_market(?array $row, string $market, bool $dryRun): array
    {
        if (!$row) { return $this->blocked($row, $market, 'product_not_found_or_not_mapped'); }
        $prefix = $market === 'EBAY_FR' ? 'ebay_fr' : 'ebay_de';
        $itemId = (string) ($row[$prefix . '_item_id'] ?? '');
        $blockKey = $market === 'EBAY_FR' ? 'blocked_reason_fr' : 'blocked_reason_de';
        $reason = (string) ($row[$blockKey] ?? '');
        $vehicleIds = $this->vehicle_ids($row);
        if ($reason !== '') { return $this->blocked($row, $market, $reason); }
        if ($itemId === '') { return $this->blocked($row, $market, 'missing_ebay_item_id'); }
        if (!$vehicleIds) { return $this->blocked($row, $market, 'no_ktype'); }
        $payload = $this->build_payload($itemId, $vehicleIds);
        if ($dryRun) {
            $logId = $this->insert_log($row, $market, $itemId, 'preview', $this->summary($payload), 'dry-run only: no eBay write API called', '');
            return $this->result($market, $itemId, false, 'preview', count($vehicleIds), '', '', [], $logId, $payload);
        }
        $response = $this->revise_fixed_price_item($market, $payload);
        if (isset($response['error'])) {
            $logId = $this->insert_log($row, $market, $itemId, 'error', $this->summary($payload), '', (string) $response['error']);
            return $this->result($market, $itemId, true, 'error', count($vehicleIds), (string) $response['error'], '', [], $logId, $payload);
        }
        $ack = (string) ($response['ack'] ?? '');
        $warnings = $response['warnings'] ?? [];
        $logId = $this->insert_log($row, $market, $itemId, 'success', $this->summary($payload), $this->summary(['ack' => $ack, 'warnings' => $warnings]), '');
        return $this->result($market, $itemId, true, 'success', count($vehicleIds), '', $ack, $warnings, $logId, $payload);
    }

    private function revise_fixed_price_item(string $market, array $payload): array
    {
        $token = $this->access_token($market);
        if ($token === '') { return ['error' => 'safe_client_reuse_unavailable_or_missing_access_token']; }
        $xml = $this->payload_to_xml($payload);
        $res = wp_remote_request('https://api.ebay.com/ws/api.dll', ['method'=>'POST','timeout'=>25,'headers'=>[
            'X-EBAY-API-CALL-NAME'=>'ReviseFixedPriceItem','X-EBAY-API-SITEID'=>$market === 'EBAY_FR' ? '71' : '77','X-EBAY-API-COMPATIBILITY-LEVEL'=>'1231','X-EBAY-API-IAF-TOKEN'=>$token,'Content-Type'=>'text/xml','Accept'=>'text/xml'], 'body'=>$xml]);
        if (is_wp_error($res)) { return ['error' => $res->get_error_message()]; }
        $body = (string) wp_remote_retrieve_body($res); $code = (int) wp_remote_retrieve_response_code($res);
        preg_match('/<Ack>([^<]+)<\/Ack>/i', $body, $ack);
        $warnings = []; if (preg_match_all('/<SeverityCode>Warning<\/SeverityCode>.*?<LongMessage>(.*?)<\/LongMessage>/is', $body, $m)) { $warnings = array_map('wp_strip_all_tags', $m[1]); }
        if ($code < 200 || $code >= 300 || !in_array($ack[1] ?? '', ['Success','Warning'], true)) { preg_match('/<LongMessage>(.*?)<\/LongMessage>/is', $body, $err); return ['error' => trim(wp_strip_all_tags($err[1] ?? ('eBay Trading API HTTP ' . $code)))]; }
        return ['ack' => $ack[1] ?? 'Success', 'warnings' => $warnings];
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
    private function result(string $market, string $itemId, bool $attempted, string $status, int $count, string $error, string $ack, array $warnings, int $logId, array $payload): array { return compact('market','itemId','attempted','status','count','error','ack','warnings','logId','payload'); }
    private function summary(array $data): string { $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES); return strlen((string) $json) > 6000 ? substr((string) $json, 0, 6000) . '…' : (string) $json; }
    private function insert_log(array $row, string $market, string $itemId, string $status, string $request, string $response, string $error): int { global $wpdb; $wpdb->insert(Database::table_names()['ebay_sync_log'], ['product_id'=>(int)$row['product_id'],'marketplace'=>$market,'ebay_item_id'=>$itemId,'part_number_normalized'=>(string)$row['part_number_normalized'],'part_cache_id'=>(int)$row['part_cache_id'],'ktype_count'=>(int)$row['ktype_count'],'status'=>$status,'request_summary'=>$request,'response_summary'=>$response,'error_message'=>$error,'created_at'=>current_time('mysql')]); return (int) $wpdb->insert_id; }
}
