<?php

namespace WEI\Services;

use WP_REST_Request;
use WP_REST_Response;

class OvokoIntegrationService
{
    private Logger $logger;
    public const OPTION_EVENTS = 'wei_ovoko_callback_events';
    public const OPTION_STATS = 'wei_ovoko_callback_stats';

    public function __construct(Logger $logger) { $this->logger = $logger; }
    public function hooks(): void {
        add_action('rest_api_init', function (): void {
            register_rest_route('gpswiss-ovoko/v1', '/callback', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_callback'],
                'permission_callback' => '__return_true',
            ]);
        });
    }
    public static function defaults(array $s): array {
        $s['ovoko_callback_enabled'] = !empty($s['ovoko_callback_enabled']) ? 1 : 0;
        $s['ovoko_callback_dry_run'] = array_key_exists('ovoko_callback_dry_run', $s) ? (!empty($s['ovoko_callback_dry_run']) ? 1 : 0) : 1;
        $s['ovoko_callback_header_name'] = (string) ($s['ovoko_callback_header_name'] ?? '');
        $s['ovoko_callback_header_secret'] = (string) ($s['ovoko_callback_header_secret'] ?? '');
        return $s;
    }
    public function handle_callback(WP_REST_Request $request): WP_REST_Response {
        $s = self::defaults((array) get_option('wei_ebay_settings', []));
        if (empty($s['ovoko_callback_enabled'])) { return new WP_REST_Response(['ok'=>false,'message'=>'disabled'], 403); }
        $headerName = strtolower(trim((string)$s['ovoko_callback_header_name']));
        $headerSecret = (string) $s['ovoko_callback_header_secret'];
        $headers = array_change_key_case($request->get_headers(), CASE_LOWER);
        $provided = is_array($headers[$headerName] ?? null) ? (string) ($headers[$headerName][0] ?? '') : (string) ($headers[$headerName] ?? '');
        if ($headerName === '' || $headerSecret === '' || $provided === '') { $this->bump('auth_failed'); $this->logger->error('OVOKO_CALLBACK_AUTH_FAILED',['reason'=>'missing']); return new WP_REST_Response(['ok'=>false], 401); }
        if (!hash_equals($headerSecret, $provided)) { $this->bump('auth_failed'); $this->logger->error('OVOKO_CALLBACK_AUTH_FAILED',['reason'=>'invalid']); return new WP_REST_Response(['ok'=>false], 403); }
        $payload = $request->get_json_params();
        $eventId = sanitize_text_field((string)($payload['event_id'] ?? ''));
        $eventType = sanitize_text_field((string)($payload['event_type'] ?? ''));
        $partId = sanitize_text_field((string)($payload['event_data']['part_id'] ?? ''));
        $status = sanitize_text_field((string)($payload['event_data']['status'] ?? ''));
        $this->bump('received');
        $this->logger->info('OVOKO_CALLBACK_RECEIVED',['event_id'=>$eventId,'event_type'=>$eventType,'part_id'=>$partId,'status'=>$status]);
        if ($this->is_duplicate($eventId)) { $this->bump('duplicate'); $this->logger->info('OVOKO_CALLBACK_DUPLICATE_SKIPPED',['event_id'=>$eventId]); return new WP_REST_Response(['ok'=>true,'duplicate'=>true], 200); }
        $result = $this->process_part_status_changed($partId, $status, !empty($s['ovoko_callback_dry_run']));
        $this->remember_event(['event_id'=>$eventId,'event_type'=>$eventType,'part_id'=>$partId,'status'=>$status,'result'=>$result['result'],'at'=>gmdate('c')]);
        return new WP_REST_Response(['ok'=>true,'result'=>$result], 200);
    }
    public function process_part_status_changed(string $partId, string $status, bool $dryRun=true): array {
        $this->logger->info('OVOKO_CALLBACK_PART_STATUS_CHANGED',['part_id'=>$partId,'status'=>$status]);
        $product = $this->find_product_by_part_id($partId);
        if (!$product) { $this->logger->info('OVOKO_CALLBACK_PRODUCT_NOT_FOUND',['part_id'=>$partId]); return ['result'=>'product_not_found']; }
        $ctx=['part_id'=>$partId,'product_id'=>$product->get_id(),'sku'=>(string)$product->get_sku(),'stock'=>(string)$product->get_stock_quantity(),'status'=>$status];
        if ($dryRun) { $this->bump('dry_run'); $this->logger->info('OVOKO_CALLBACK_DRY_RUN',$ctx+['would_set_stock_zero'=>$status==='sold']); return ['result'=>'dry_run'] + $ctx; }
        $this->bump('applied'); $this->logger->info('OVOKO_CALLBACK_APPLIED',$ctx+['note'=>'production apply deferred']);
        return ['result'=>'applied_deferred'] + $ctx;
    }
    private function find_product_by_part_id(string $partId): ?\WC_Product {
        if ($partId==='') return null; $keys=['_ovoko_part_id','ovoko_part_id','part_id','source_part_id','external_part_id','_allegro_source_id','_ovoko_source_id'];
        foreach($keys as $key){ $q=new \WP_Query(['post_type'=>'product','post_status'=>['publish','draft','private'],'posts_per_page'=>1,'fields'=>'ids','meta_query'=>[['key'=>$key,'value'=>$partId,'compare'=>'=']]]); if(!empty($q->posts[0])){ $p=wc_get_product((int)$q->posts[0]); if($p){ return $p; } } }
        return null;
    }
    private function is_duplicate(string $eventId): bool { if($eventId==='') return false; foreach((array)get_option(self::OPTION_EVENTS,[]) as $e){ if(($e['event_id']??'')===$eventId)return true; } return false; }
    private function remember_event(array $event): void { $events=(array)get_option(self::OPTION_EVENTS,[]); array_unshift($events,$event); update_option(self::OPTION_EVENTS,array_slice($events,0,30),false); }
    private function bump(string $k): void { $s=(array)get_option(self::OPTION_STATS,[]); $s[$k]=(int)($s[$k]??0)+1; update_option(self::OPTION_STATS,$s,false); }
}
