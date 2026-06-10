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
        $recoverablePartId = $this->recoverable_part_id_from_last_response($productId);
        if ($recoverablePartId !== '') {
            $result['recoverable_part_id'] = $recoverablePartId;
            return $this->fail_and_store($result, $productId, 'recoverable_part_id_blocks_retry', 'Last Ovoko import response already contains part_id ' . $recoverablePartId . '. Do not retry live import; use repair/link to attach this product to the existing CRM part.');
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
        $classification = $this->classify_import_response($response);
        $partId = (string) ($classification['part_id'] ?? '');
        $result['response_classification'] = $classification;
        if (empty($classification['success'])) {
            $result['ok'] = false;
            $result['status'] = !empty($classification['photo_file_missing']) ? 'repair_needed' : 'failed';
            $result['error_code'] = !empty($classification['photo_file_missing']) ? 'ovoko_photo_file_missing' : 'ovoko_import_failed';
            $result['message'] = !empty($classification['photo_file_missing'])
                ? 'Ovoko rejected photo file; image must be checked/reuploaded before retry'
                : 'Ovoko /crm/importPart did not return HTTP 200 + status_code R200/R202 + part_id for CRM-only no-price import.';
            if (!empty($classification['photo_file_missing'])) {
                $result['skip_reason'] = 'ovoko_photo_file_missing';
            }
            $result['part_id'] = $partId;
            if ($partId !== '') {
                $result['recoverable_part_id'] = $partId;
            }
            $this->store_failure($productId, $result, $response);
            return $result;
        }

        $result['ok'] = true;
        $result['status'] = 'success';
        $result['part_id'] = $partId;
        $result['message'] = !empty($classification['crm_only_missing_price_warning'])
            ? 'Created CRM-only Ovoko/RRR part from one Woo draft product. R202 missing price warning confirms the part was created in CRM and is not visible in shop until price is filled.'
            : 'Created CRM-only Ovoko/RRR part from one Woo draft product.';
        if (!empty($classification['crm_only_missing_price_warning'])) {
            $result['crm_only_confirmation'] = 'created_in_crm_not_visible_in_shop_missing_price';
        }
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



    public function repair_product_part_id(int $productId, string $partId, array $response = []): array
    {
        $partId = trim($partId);
        if ($productId <= 0 || !$this->product_exists($productId)) {
            return ['ok' => false, 'status' => 'blocked', 'error_code' => 'product_not_found', 'product_id' => $productId, 'part_id' => $partId];
        }
        if ($partId === '') {
            $partId = $this->recoverable_part_id_from_last_response($productId);
        }
        if ($partId === '') {
            return ['ok' => false, 'status' => 'blocked', 'error_code' => 'missing_recoverable_part_id', 'product_id' => $productId, 'part_id' => ''];
        }
        $result = $this->base_result($productId, gmdate('c'));
        $result['ok'] = true;
        $result['status'] = 'success';
        $result['part_id'] = $partId;
        $result['message'] = 'Linked Woo product to existing Ovoko/RRR CRM part without re-importing.';
        $result['repair_action'] = 'linked_existing_part_id_without_reimport';
        $this->store_success($productId, $partId, $result, $response, []);
        update_post_meta($productId, '_gps_ovoko_crm_only_import_repaired_at', gmdate('c'));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_repair_source', 'recoverable_failed_response_part_id');
        return $result;
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
        $allowed = ['category_id', 'car_id', 'quality', 'status', 'place', 'manufacturer_code', 'visible_code', 'external_id', 'photo', 'photos[]', 'notes', 'internal_notes'];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $previewPayload)) {
                $payload[$key] = $previewPayload[$key];
            }
        }
        unset($payload['price'], $payload['original_price'], $payload['currency'], $payload['original_currency'], $payload['description'], $payload['listing_text'], $payload['sticker_note']);
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
            'photo_equals_first_photos' => $photos !== [] && trim((string) ($payload['photo'] ?? '')) === trim((string) ($photos[0] ?? '')),
        ];
    }



    private function classify_import_response(array $response): array
    {
        $httpCode = (int) ($response['http_code'] ?? $response['http_status'] ?? 0);
        $statusCode = trim((string) ($response['status_code'] ?? ''));
        $rawMessage = $response['msg'] ?? $response['message'] ?? '';
        $message = is_array($rawMessage) ? wp_json_encode($rawMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : trim((string) $rawMessage);
        $partId = trim((string) ($response['part_id'] ?? ''));
        if ($partId === '' && is_array($response['parsed_json'] ?? null)) {
            $partId = $this->extract_part_id_from_decoded_response((array) $response['parsed_json']);
        }
        if ($partId === '' && trim((string) ($response['raw_body'] ?? '')) !== '') {
            $decoded = json_decode((string) $response['raw_body'], true);
            if (is_array($decoded)) {
                $partId = $this->extract_part_id_from_decoded_response($decoded);
                $statusCode = $statusCode !== '' ? $statusCode : trim((string) ($decoded['status_code'] ?? ''));
                $message = $message !== '' ? $message : trim((string) ($decoded['msg'] ?? $decoded['message'] ?? ''));
            }
        }
        $missingPriceWarning = $this->is_missing_price_r202_warning($statusCode, $message);
        $photoFileMissing = $this->response_has_photo_file_missing_error($statusCode, $response, $message);
        $success = $httpCode === 200 && $partId !== '' && ($statusCode === 'R200' || $missingPriceWarning);
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'part_id' => $partId,
            'message' => $message,
            'crm_only_missing_price_warning' => $missingPriceWarning,
            'photo_file_missing' => $photoFileMissing,
            'success_rule' => $success ? ($missingPriceWarning ? 'http_200_r202_part_id_missing_price_warning' : 'http_200_r200_part_id') : ($photoFileMissing ? 'r400_photo_file_missing_repair_needed' : 'not_success'),
        ];
    }

    private function is_missing_price_r202_warning(string $statusCode, string $message): bool
    {
        $message = strtolower($message);
        return $statusCode === 'R202'
            && str_contains($message, 'part won')
            && str_contains($message, 'shown in shop')
            && str_contains($message, 'price');
    }

    private function extract_part_id_from_decoded_response(array $decoded): string
    {
        $candidates = [
            $decoded['part_id'] ?? null,
            $decoded['id'] ?? null,
            $decoded['data']['part_id'] ?? null,
            $decoded['data']['id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return function_exists('sanitize_text_field') ? sanitize_text_field($candidate) : $candidate;
            }
        }
        return '';
    }

    private function recoverable_part_id_from_last_response(int $productId): string
    {
        $raw = trim((string) get_post_meta($productId, '_gps_ovoko_crm_only_import_last_response_raw', true));
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        return $this->extract_part_id_from_decoded_response($decoded);
    }

    private function response_has_photo_file_missing_error(string $statusCode, array $response, string $message): bool
    {
        if (strtoupper(trim($statusCode)) !== 'R400') {
            return false;
        }
        $haystack = strtolower($message . ' ' . (wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
        return str_contains($haystack, 'photo') && str_contains($haystack, 'file does not exist');
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
        update_post_meta($productId, '_gps_ovoko_crm_only_import_response_summary', wp_json_encode($this->redact_response_for_result($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_result_summary', wp_json_encode(['status' => $result['status'] ?? '', 'part_id' => $partId, 'message' => $result['message'] ?? '', 'crm_only_confirmation' => $result['crm_only_confirmation'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function store_failure(int $productId, array $result, array $response): void
    {
        update_post_meta($productId, '_gps_ovoko_crm_only_import_last_error', wp_json_encode(['code' => $result['error_code'] ?? 'unknown', 'message' => $result['message'] ?? '', 'status' => $result['status'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_last_error_at', gmdate('c'));
        update_post_meta($productId, '_gps_ovoko_crm_only_import_last_response_raw', (string) ($response['raw_body'] ?? wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        if ((string) ($result['error_code'] ?? '') === 'ovoko_photo_file_missing') {
            update_post_meta($productId, '_gps_ovoko_crm_only_import_repair_needed', '1');
            update_post_meta($productId, '_gps_ovoko_crm_only_import_repair_reason', 'ovoko_photo_file_missing');
        }
        if (trim((string) ($result['part_id'] ?? '')) !== '') {
            update_post_meta($productId, '_gps_ovoko_crm_only_import_recoverable_part_id', trim((string) $result['part_id']));
            update_post_meta($productId, '_gps_ovoko_crm_only_import_repair_available', '1');
        }
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
            'internal_notes_preview' => (string) ($preview['internal_notes_preview'] ?? ''),
            'listing_text_preview' => (string) ($preview['listing_text_preview'] ?? ''),
            'listing_text_source' => (string) ($preview['listing_text_source'] ?? ''),
            'price_fields_omitted' => true,
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
            'placeholder_car_id_warning' => !empty($preview['car_id_is_placeholder']) ? (string) ($preview['placeholder_car_id_warning'] ?? '') : '',
            'quality' => $payload['quality'] ?? null,
            'status' => $payload['status'] ?? null,
            'internal_notes_preview' => (string) ($preview['internal_notes_preview'] ?? ''),
            'listing_text_field' => 'notes',
            'listing_text_preview' => (string) ($payload['notes'] ?? ''),
            'listing_text_source' => (string) ($preview['listing_text_source'] ?? ''),
            'price_fields_omitted' => true,
            'price_fields_omitted_fields' => ['price', 'original_price', 'currency', 'original_currency'],
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
