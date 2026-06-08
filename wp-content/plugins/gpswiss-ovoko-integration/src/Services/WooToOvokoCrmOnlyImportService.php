<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCrmOnlyImportService
{
    public const ACTION_NAME = 'Create Ovoko CRM-only part from Woo draft';
    public const ACTION_HOOK = 'gpswiss_ovoko_create_crm_only_part_from_woo';
    public const ENDPOINT_PATH = '/crm/importPart';
    public const STRATEGY = 'crm_only_non_public_initial_import';
    public const NON_PUBLIC_REASON = 'missing_price';

    /** @var callable|null */
    private $importer;

    public function __construct(private array $settings = [], ?callable $importer = null)
    {
        $this->importer = $importer;
    }

    public function create(int $productId, array $confirmations): array
    {
        $startedAt = gmdate('c');
        $result = $this->base_result($productId, $startedAt);

        if ($productId <= 0) {
            return $this->fail($result, 'invalid_product_id', 'Exactly one valid product_id is required.');
        }
        if (!$this->confirmations_pass($confirmations, $result)) {
            $this->store_error($productId, $result);
            return $result;
        }
        if (!$this->product_exists($productId)) {
            return $this->fail_and_store($result, $productId, 'product_not_found', 'Product does not exist.');
        }
        if ((string) get_post_type($productId) !== 'product') {
            return $this->fail_and_store($result, $productId, 'non_product_post_type', 'The supplied ID is not a WooCommerce product.');
        }
        if ((string) get_post_status($productId) !== 'draft') {
            return $this->fail_and_store($result, $productId, 'product_status_must_be_draft', 'Only Woo draft products can be imported by this CRM-only action.');
        }
        if ($this->has_existing_part_id_meta($productId)) {
            return $this->fail_and_store($result, $productId, 'existing_part_id_meta_blocks_import', 'Product already has Ovoko/RRR part ID meta.');
        }

        $preview = (new WooToOvokoCreatePartPreviewService())->preview($productId);
        $result['preview_checked_at'] = (string) ($preview['checked_at'] ?? $startedAt);
        $result['preview'] = $this->preview_summary($preview);

        if (!$this->preview_passes_live_safety($preview, $result)) {
            $this->store_error($productId, $result);
            return $result;
        }

        $payload = $this->live_payload_from_preview((array) ($preview['proposed_payload'] ?? []));
        $result['request_summary'] = $this->request_summary($payload, $preview);
        $result['payload_safety'] = $this->payload_safety($payload);

        if (!$result['payload_safety']['price_fields_absent']) {
            return $this->fail_and_store($result, $productId, 'price_fields_present_in_live_payload', 'Live CRM-only payload contains a forbidden price field.');
        }
        if (!$result['payload_safety']['photos_included']) {
            return $this->fail_and_store($result, $productId, 'photos_missing_from_live_payload', 'Live CRM-only payload must include photo and photos[].');
        }
        if (!empty($preview['car_id_is_placeholder']) && empty($confirmations['confirm_placeholder_car_id'])) {
            return $this->fail_and_store($result, $productId, 'placeholder_car_id_confirmation_required', 'Placeholder car_id confirmation is required.');
        }

        $response = $this->call_import($payload);
        $result['response'] = $this->redact_response_for_result($response);
        $partId = trim((string) ($response['part_id'] ?? ''));
        if (empty($response['ok']) || $partId === '') {
            $result['ok'] = false;
            $result['status'] = 'failed';
            $result['error_code'] = 'ovoko_import_failed';
            $result['message'] = 'Ovoko /crm/importPart did not return HTTP 200 + status_code R200 + part_id.';
            $result['part_id'] = '';
            $this->store_failure($productId, $result, $response);
            return $result;
        }

        $result['ok'] = true;
        $result['status'] = 'success';
        $result['part_id'] = $partId;
        $result['message'] = 'Created CRM-only Ovoko/RRR part from one Woo draft product.';
        $this->store_success($productId, $partId, $result, $response, $preview);
        return $result;
    }

    public static function required_confirmation_labels(bool $placeholderCarIdUsed): array
    {
        $labels = [
            'confirm_live_one_product' => 'I understand this will call /crm/importPart live for one product only.',
            'confirm_no_price_non_public' => 'I understand no price will be sent, so the part should not be available in e-shop according to documentation.',
        ];
        if ($placeholderCarIdUsed) {
            $labels = ['confirm_placeholder_car_id' => 'I understand this uses a placeholder car_id and staff must correct vehicle mapping in Ovoko before publishing.'] + $labels;
        }
        return $labels;
    }

    public static function all_part_id_meta_keys(): array
    {
        return ['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id'];
    }

    private function base_result(int $productId, string $startedAt): array
    {
        return [
            'ok' => false,
            'action_name' => self::ACTION_NAME,
            'action_hook' => self::ACTION_HOOK,
            'product_id' => $productId,
            'endpoint_path' => self::ENDPOINT_PATH,
            'content_type' => 'application/x-www-form-urlencoded',
            'create_strategy' => self::STRATEGY,
            'e_shop_available_after_import' => false,
            'non_public_reason' => self::NON_PUBLIC_REASON,
            'single_product_only' => true,
            'no_bulk' => true,
            'no_cron' => true,
            'no_product_save_hook' => true,
            'no_price_sent' => true,
            'started_at' => $startedAt,
            'errors' => [],
        ];
    }

    private function confirmations_pass(array $confirmations, array &$result): bool
    {
        foreach (['confirm_live_one_product', 'confirm_no_price_non_public'] as $key) {
            if (empty($confirmations[$key])) {
                $result['errors'][] = ['code' => $key . '_required', 'message' => 'Required admin confirmation is missing: ' . $key];
            }
        }
        if ($result['errors'] !== []) {
            $result['status'] = 'blocked';
            $result['error_code'] = 'missing_required_confirmations';
            $result['message'] = 'Live import blocked because required confirmation checkboxes were not all ticked.';
            return false;
        }
        return true;
    }

    private function product_exists(int $productId): bool
    {
        return function_exists('get_post') && get_post($productId) !== false && get_post($productId) !== null;
    }

    private function has_existing_part_id_meta(int $productId): bool
    {
        foreach (self::all_part_id_meta_keys() as $key) {
            if (trim((string) get_post_meta($productId, $key, true)) !== '') {
                return true;
            }
        }
        return false;
    }

    private function preview_passes_live_safety(array $preview, array &$result): bool
    {
        $errors = [];
        if (empty($preview['would_be_eligible']) || !empty($preview['validation_errors'])) { $errors[] = 'preview_ineligible'; }
        if ((string) ($preview['proposed_endpoint_path'] ?? '') !== self::ENDPOINT_PATH) { $errors[] = 'endpoint_not_confirmed'; }
        if (!empty($preview['endpoint_confirmation_required'])) { $errors[] = 'endpoint_confirmation_required'; }
        if (!empty($preview['payload_format_confirmation_required'])) { $errors[] = 'payload_format_confirmation_required'; }
        if ((string) ($preview['create_strategy'] ?? '') !== self::STRATEGY) { $errors[] = 'invalid_create_strategy'; }
        if (!empty($preview['e_shop_available_after_import'])) { $errors[] = 'e_shop_available_after_import_must_be_false'; }
        if ((string) ($preview['non_public_reason'] ?? '') !== self::NON_PUBLIC_REASON) { $errors[] = 'invalid_non_public_reason'; }
        if (empty($preview['photos_included'])) { $errors[] = 'photos_not_included'; }

        if ($errors !== []) {
            $result['status'] = 'blocked';
            $result['error_code'] = 'preview_live_safety_failed';
            $result['message'] = 'Live import blocked because CRM-only preview safety checks failed.';
            $result['errors'] = array_map(static fn(string $code): array => ['code' => $code], array_values(array_unique($errors)));
            return false;
        }
        return true;
    }

    private function live_payload_from_preview(array $previewPayload): array
    {
        $allowed = ['category_id', 'car_id', 'quality', 'status', 'notes', 'place', 'manufacturer_code', 'visible_code', 'external_id', 'photo', 'photos[]', 'internal_notes', 'sticker_note'];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $previewPayload)) {
                $payload[$key] = $previewPayload[$key];
            }
        }
        unset($payload['price'], $payload['original_price'], $payload['currency'], $payload['original_currency']);
        return $payload;
    }

    private function payload_safety(array $payload): array
    {
        $priceKeys = array_intersect(['price', 'original_price', 'currency', 'original_currency'], array_keys($payload));
        $photos = (array) ($payload['photos[]'] ?? []);
        return [
            'price_fields_absent' => $priceKeys === [],
            'forbidden_price_fields_present' => array_values($priceKeys),
            'photo_present' => trim((string) ($payload['photo'] ?? '')) !== '',
            'photos_count' => count(array_filter($photos, static fn($url): bool => trim((string) $url) !== '')),
            'photos_included' => trim((string) ($payload['photo'] ?? '')) !== '' && $photos !== [],
        ];
    }

    private function call_import(array $payload): array
    {
        if ($this->importer !== null) {
            return (array) call_user_func($this->importer, $payload);
        }
        return (new RrrApiClient($this->settings))->import_crm_only_part($payload);
    }

    private function store_success(int $productId, string $partId, array $result, array $response, array $preview): void
    {
        update_post_meta($productId, '_ovoko_part_id', $partId);
        update_post_meta($productId, 'ovoko_part_id', $partId);
        update_post_meta($productId, '_gps_ovoko_crm_only_imported_at', gmdate('c'));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_strategy', self::STRATEGY);
        update_post_meta($productId, '_gps_ovoko_crm_only_import_non_public_reason', self::NON_PUBLIC_REASON);
        update_post_meta($productId, '_gps_ovoko_crm_only_import_price_omitted', '1');
        update_post_meta($productId, '_gps_ovoko_crm_only_import_photos_included', '1');
        update_post_meta($productId, '_gps_ovoko_crm_only_import_placeholder_car_id_used', !empty($preview['car_id_is_placeholder']) ? '1' : '0');
        update_post_meta($productId, '_gps_ovoko_crm_only_import_car_id', (string) ($preview['car_id'] ?? ''));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_car_id_source', (string) ($preview['car_id_source'] ?? ''));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_response_raw', (string) ($response['raw_body'] ?? ''));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_request_summary', wp_json_encode($result['request_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function store_failure(int $productId, array $result, array $response): void
    {
        update_post_meta($productId, '_gps_ovoko_crm_only_import_last_error', wp_json_encode(['code' => $result['error_code'] ?? 'unknown', 'message' => $result['message'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_last_error_at', gmdate('c'));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_last_response_raw', (string) ($response['raw_body'] ?? wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    private function store_error(int $productId, array $result): void
    {
        if ($productId > 0 && $this->product_exists($productId)) {
            update_post_meta($productId, '_gps_ovoko_crm_only_import_last_error', wp_json_encode(['code' => $result['error_code'] ?? 'unknown', 'message' => $result['message'] ?? '', 'errors' => $result['errors'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($productId, '_gps_ovoko_crm_only_import_last_error_at', gmdate('c'));
        }
    }

    private function fail(array $result, string $code, string $message): array
    {
        $result['status'] = 'blocked';
        $result['error_code'] = $code;
        $result['message'] = $message;
        $result['errors'][] = ['code' => $code, 'message' => $message];
        return $result;
    }

    private function fail_and_store(array $result, int $productId, string $code, string $message): array
    {
        $result = $this->fail($result, $code, $message);
        $this->store_error($productId, $result);
        return $result;
    }

    private function preview_summary(array $preview): array
    {
        return [
            'would_be_eligible' => !empty($preview['would_be_eligible']),
            'validation_errors' => (array) ($preview['validation_errors'] ?? []),
            'proposed_endpoint_path' => (string) ($preview['proposed_endpoint_path'] ?? ''),
            'create_strategy' => (string) ($preview['create_strategy'] ?? ''),
            'e_shop_available_after_import' => !empty($preview['e_shop_available_after_import']),
            'non_public_reason' => (string) ($preview['non_public_reason'] ?? ''),
            'photos_included' => !empty($preview['photos_included']),
            'car_id' => $preview['car_id'] ?? null,
            'car_id_source' => (string) ($preview['car_id_source'] ?? ''),
            'car_id_is_placeholder' => !empty($preview['car_id_is_placeholder']),
        ];
    }

    private function request_summary(array $payload, array $preview): array
    {
        return [
            'method' => 'POST',
            'endpoint_path' => self::ENDPOINT_PATH,
            'content_type' => 'application/x-www-form-urlencoded',
            'product_id' => (int) ($preview['product_id'] ?? 0),
            'external_id' => (string) ($payload['external_id'] ?? ''),
            'category_id' => $payload['category_id'] ?? null,
            'car_id' => $payload['car_id'] ?? null,
            'car_id_source' => (string) ($preview['car_id_source'] ?? ''),
            'car_id_is_placeholder' => !empty($preview['car_id_is_placeholder']),
            'quality' => $payload['quality'] ?? null,
            'status' => $payload['status'] ?? null,
            'price_fields_omitted' => ['price', 'original_price', 'currency'],
            'photo' => !empty($payload['photo']) ? '[included]' : '[missing]',
            'photos_count' => count((array) ($payload['photos[]'] ?? [])),
            'auth_fields' => ['username' => '[redacted]', 'password' => '[redacted]', 'user_token' => '[redacted]'],
            'idempotency_note' => 'Local part ID meta is checked before import. external_id duplicate behavior is not fully documented for importPart.',
        ];
    }

    private function redact_response_for_result(array $response): array
    {
        unset($response['raw_body']);
        return $response;
    }
}
