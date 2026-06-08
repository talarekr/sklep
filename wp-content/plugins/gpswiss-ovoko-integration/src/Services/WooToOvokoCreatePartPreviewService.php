<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCreatePartPreviewService
{
    private const ACTION_NAME = 'Preview Woo → Ovoko CRM-only import payload';
    private const MODE = 'dry_run_no_ovoko_write_no_woo_write';
    private const PROPOSED_ENDPOINT = 'DOCUMENTED_ENDPOINT_WRITE_BLOCKED';
    private const PROPOSED_ENDPOINT_PATH = '/crm/importPart';
    private const ALLOWED_STATUSES = ['draft'];
    private const PART_IDENTIFIER_META_KEYS = [
        '_part_number',
        '_mpn',
        'mpn',
        '_manufacturer_code',
        '_gpswiss_part_number',
        '_gps_detected_part_code',
        '_gps_detected_oem_part_number',
    ];
    private const DUPLICATE_META_KEYS = [
        '_ovoko_part_id',
        'ovoko_part_id',
        'part_id',
        'source_part_id',
        'external_part_id',
    ];

    public function preview(int $productId): array
    {
        $result = $this->base_result($productId);

        if ($productId <= 0) {
            $result['ok'] = false;
            $this->add_validation($result, 'error', 'invalid_product_id', 'A positive product_id is required.');
            $this->finalize($result);
            return $result;
        }

        $post = get_post($productId);
        if (!$post) {
            $result['ok'] = false;
            $this->add_validation($result, 'error', 'invalid_product_id', 'Product does not exist.');
            $this->finalize($result);
            return $result;
        }

        $postType = get_post_type($productId);
        if ($postType === 'product_variation') {
            $result['ok'] = false;
            $result['post_type'] = $postType;
            $this->add_validation($result, 'error', 'variation_not_supported', 'Product variations are not supported by this dry-run preview.');
            $this->finalize($result);
            return $result;
        }

        if ($postType !== 'product') {
            $result['ok'] = false;
            $result['post_type'] = (string) $postType;
            $this->add_validation($result, 'error', 'non_product_post_type', 'The supplied ID is not a WooCommerce product.');
            $this->finalize($result);
            return $result;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $status = (string) get_post_status($productId);
        $title = trim((string) get_the_title($productId));
        $sku = $this->product_value($product, $productId, 'sku', '_sku');
        $price = $this->product_value($product, $productId, 'price', '_price');
        $regularPrice = $this->product_value($product, $productId, 'regular_price', '_regular_price');
        $salePrice = $this->product_value($product, $productId, 'sale_price', '_sale_price');
        $stockStatus = $this->product_value($product, $productId, 'stock_status', '_stock_status');
        $stockQuantity = $this->product_stock_quantity($product, $productId);
        $partIdentifier = $this->first_meta_value($productId, self::PART_IDENTIFIER_META_KEYS);
        $manufacturerCodeData = $this->first_meta_value($productId, ['_manufacturer_code', '_mpn', 'mpn', '_gpswiss_part_number']);
        $manufacturerCode = (string) $manufacturerCodeData['value'];
        $categoryReadiness = $this->category_mapping_readiness($productId);
        $images = $this->image_preview($product, $productId);
        $duplicates = $this->duplicate_checks($productId, $sku, $manufacturerCode, $partIdentifier['value']);
        $description = $this->product_description($product, $productId);
        $contract = $this->create_part_contract_report();
        $categoryPayload = $this->category_payload($categoryReadiness);
        $storageLocation = $this->storage_location($productId);
        $qualityId = $this->resolved_quality_id($productId);
        $importStatus = $this->resolved_import_status();
        $carId = $this->first_meta_value($productId, ['_ovoko_car_id', 'ovoko_car_id', '_rrr_car_id', 'rrr_car_id', '_gps_source_car_id']);

        $result['product_status'] = $status;
        $result['post_type'] = $postType;
        $result['duplicate_checks'] = $duplicates;
        $result['images'] = $images;
        $result['source_woo_fields_meta_used'] = [
            'post' => ['ID' => $productId, 'post_type' => $postType, 'post_status' => $status, 'post_title' => $title],
            'product_methods' => ['get_sku', 'get_price', 'get_regular_price', 'get_sale_price', 'get_stock_status', 'get_stock_quantity', 'get_image_id', 'get_gallery_image_ids'],
            'meta_keys' => array_values(array_unique(array_merge(['_sku', '_price', '_regular_price', '_sale_price', '_stock_status', '_stock', '_thumbnail_id', '_product_image_gallery'], self::PART_IDENTIFIER_META_KEYS, self::DUPLICATE_META_KEYS, ['_ovoko_manufacturer_code', 'ovoko_id', 'source', '_ovoko_car_id', 'ovoko_car_id', '_ovoko_quality_id', 'ovoko_quality_id', '_gps_storage_location', 'storage_location', 'place']))),
            'taxonomy' => ['product_cat'],
        ];

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->add_validation($result, 'error', 'product_status_not_allowed', 'Only explicitly allowed statuses may be previewed for create-part payloads.', ['allowed_statuses' => self::ALLOWED_STATUSES, 'actual_status' => $status]);
        }
        if ($duplicates['has_existing_ovoko_part_id']) {
            $this->add_validation($result, 'error', 'existing_ovoko_part_id', 'Product already has _ovoko_part_id and must not be treated as a new Ovoko part.');
        }
        if ($title === '') {
            $this->add_validation($result, 'error', 'missing_title', 'Product title is required.');
        }
        if ($sku === '') {
            $this->add_validation($result, 'error', 'missing_sku', 'SKU is required.');
        }
        if ($stockStatus === '' && $stockQuantity === null) {
            $this->add_validation($result, 'error', 'unknown_stock_status_quantity', 'Stock status or stock quantity must be known.');
        }
        if ($partIdentifier['value'] === '') {
            $this->add_validation($result, 'error', 'missing_part_identifier', 'Part identifier is required from an approved Woo meta key.', ['accepted_meta_keys' => self::PART_IDENTIFIER_META_KEYS]);
        }
        if (!$categoryReadiness['known']) {
            $this->add_validation($result, 'warning', 'category_mapping_readiness_unknown', 'Category mapping readiness could not be determined.');
        }
        if ($categoryReadiness['known'] && $categoryPayload['category_id'] === null) {
            $this->add_validation($result, 'error', 'missing_ovoko_category_id', 'Ovoko/RRR importPart requires category_id; no mapped Ovoko category ID was found.');
        }
        if ($carId['value'] === '') {
            $this->add_validation($result, 'error', 'missing_required_car_id', 'CRM-only importPart preview requires a mapped Ovoko/RRR car_id before any future live import.');
        }
        if ($qualityId['value'] === '') {
            $this->add_validation($result, 'error', 'missing_required_quality', 'CRM-only importPart preview requires a configured/default quality value before any future live import.');
        }
        if (empty($images['image_urls'])) {
            $this->add_validation($result, 'error', 'missing_images', 'At least one accessible product image URL is required for create-part readiness.');
        }
        foreach ((array) ($images['image_details'] ?? []) as $imageDetail) {
            if (empty($imageDetail['accessible'])) {
                $this->add_validation($result, 'error', 'inaccessible_image_url', 'Product image URL is missing or not accessible for preview.', ['attachment_id' => (int) ($imageDetail['attachment_id'] ?? 0), 'url' => (string) ($imageDetail['url'] ?? '')]);
            }
        }
        foreach ($duplicates['warnings'] as $warning) {
            $this->add_validation($result, 'warning', (string) $warning['code'], (string) $warning['message'], (array) ($warning['context'] ?? []));
        }
        foreach ($duplicates['blocking_warnings'] as $warning) {
            $this->add_validation($result, 'error', (string) $warning['code'], (string) $warning['message'], (array) ($warning['context'] ?? []));
        }

        $result['create_part_contract_report'] = $contract;
        $result['candidate_endpoints'] = $contract['candidate_endpoints'];
        $result['proposed_endpoint_path'] = self::PROPOSED_ENDPOINT_PATH;
        $result['authentication_style'] = $contract['authentication_style'];
        $result['image_handling'] = $contract['image_handling'];
        $result['would_create_as_draft_or_unpublished'] = false;
        $result['draft_visibility_field'] = 'price';
        $result['draft_visibility_value'] = 'omitted';
        $result['draft_visibility_confirmation_required'] = false;
        $result['create_strategy'] = 'crm_only_non_public_initial_import';
        $result['e_shop_visibility_rule_confirmed_by_documentation'] = true;
        $result['e_shop_available_after_import'] = false;
        $result['non_public_reason'] = 'missing_price';
        $result['photos_included'] = !empty($images['image_urls']);
        $result['omitted_for_non_public_import'] = ['price', 'original_price', 'currency'];
        $result['live_create_still_requires_manual_confirmation'] = true;

        $result['proposed_payload'] = $this->build_crm_only_payload($title, $sku, $stockStatus, $stockQuantity, $partIdentifier, $manufacturerCode, $categoryPayload, $images, $description, $storageLocation, $qualityId, $carId, $importStatus);
        $result['full_payload_preview'] = $this->build_full_payload_preview($title, $sku, $price, $stockStatus, $stockQuantity, $partIdentifier, $manufacturerCode, $categoryPayload, $images, $description, $regularPrice, $salePrice, $storageLocation, $qualityId, $carId, $importStatus);

        $result['future_live_readiness'] = $this->future_live_readiness($result, $categoryPayload, $images, $price, $sku, $carId, $qualityId);

        /* legacy normalized preview fields retained inside proposed_payload */
        $result['legacy_payload_summary'] = [
            'title' => $title,
            'sku' => $sku,
            'price' => is_numeric($price) ? (float) $price : $price,
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            'stock_status' => $stockStatus,
            'stock_quantity' => $stockQuantity,
            'part_identifier' => $partIdentifier['value'],
            'part_identifier_source' => $partIdentifier['key'],
            'manufacturer_code' => $manufacturerCode,
            'category' => $categoryReadiness,
            'images' => ['urls' => $images['image_urls'], 'upload_policy' => $images['upload_policy']],
            'description' => $description,
            'regular_price' => is_numeric($regularPrice) ? (float) $regularPrice : $regularPrice,
            'sale_price' => is_numeric($salePrice) ? (float) $salePrice : $salePrice,
        ];

        $this->finalize($result);
        return $result;
    }

    private function base_result(int $productId): array
    {
        return [
            'ok' => true,
            'action_name' => self::ACTION_NAME,
            'mode' => self::MODE,
            'product_id' => $productId,
            'product_status' => '',
            'would_be_eligible' => false,
            'would_send' => false,
            'no_ovoko_write' => true,
            'no_woo_write' => true,
            'proposed_endpoint' => self::PROPOSED_ENDPOINT,
            'proposed_endpoint_path' => self::PROPOSED_ENDPOINT_PATH,
            'endpoint_confirmation_required' => false,
            'payload_format_confirmation_required' => false,
            'would_create_as_draft_or_unpublished' => false,
            'draft_visibility_field' => 'price',
            'draft_visibility_value' => 'omitted',
            'draft_visibility_confirmation_required' => false,
            'create_strategy' => 'crm_only_non_public_initial_import',
            'e_shop_visibility_rule_confirmed_by_documentation' => true,
            'e_shop_available_after_import' => false,
            'non_public_reason' => 'missing_price',
            'photos_included' => false,
            'omitted_for_non_public_import' => ['price', 'original_price', 'currency'],
            'live_create_still_requires_manual_confirmation' => true,
            'create_part_contract_report' => [],
            'future_live_readiness' => [],
            'candidate_endpoints' => [],
            'authentication_style' => [],
            'image_handling' => [],
            'duplicate_checks' => [],
            'validations' => [],
            'validation_errors' => [],
            'validation_warnings' => [],
            'source_woo_fields_meta_used' => [],
            'proposed_payload' => [],
            'full_payload_preview' => [],
            'images' => ['featured_image_id' => 0, 'gallery_image_ids' => [], 'image_urls' => [], 'upload_policy' => 'preview_urls_only_no_upload'],
            'checked_at' => gmdate('c'),
        ];
    }

    private function add_validation(array &$result, string $severity, string $code, string $message, array $context = []): void
    {
        $entry = ['severity' => $severity, 'code' => $code, 'message' => $message];
        if ($context !== []) {
            $entry['context'] = $context;
        }
        $result['validations'][] = $entry;
        if ($severity === 'error') {
            $result['validation_errors'][] = $entry;
        } else {
            $result['validation_warnings'][] = $entry;
        }
    }

    private function finalize(array &$result): void
    {
        $result['would_be_eligible'] = empty($result['validation_errors']);
        $result['would_send'] = false;
        $result['no_ovoko_write'] = true;
        $result['no_woo_write'] = true;
        $result['checked_at'] = gmdate('c');
    }


    public function create_part_contract_report(): array
    {
        return [
            'source' => [
                'official_openapi_url' => 'https://api.rrr.lt/openapi/swagger.yaml',
                'official_docs_url' => 'https://api.rrr.lt/docs/',
                'checked_on' => '2026-06-08',
                'repo_evidence' => ['RrrApiClient currently implements /crm/changePartStatus and /crm/updatePart writes only; no create/import call exists.'],
            ],
            'documentation_audit' => [
                'status' => 'confirmed_by_documentation',
                'repo_docs_found' => ['wp-content/plugins/gpswiss-ovoko-integration/README.md', 'RRR_API_AUTH_TEST.md'],
                'included_openapi_or_pdf_found' => false,
                'official_source_used' => 'https://api.rrr.lt/openapi/swagger.yaml',
                'official_source_status' => 'confirmed_by_documentation',
                'notes' => 'Repository search found references to the official RRR docs/spec but no bundled OpenAPI/PDF file. Findings below are backed by the official OpenAPI schema where marked confirmed_by_documentation.',
            ],
            'candidate_endpoints' => [
                [
                    'path' => '/crm/importPart',
                    'method' => 'POST',
                    'status' => 'confirmed_by_documentation',
                    'summary' => 'Official OpenAPI summary: Import part.',
                    'content_type' => 'application/x-www-form-urlencoded',
                ],
                ['path' => '/crm/updatePart', 'method' => 'POST', 'status' => 'confirmed_update_existing_only_requires_part_id'],
                ['path' => '/crm/changePartStatus', 'method' => 'POST', 'status' => 'confirmed_status_change_existing_only_requires_part_id'],
            ],
            'authentication_style' => [
                'method' => 'POST form fields',
                'content_type' => 'application/x-www-form-urlencoded',
                'required_auth_fields' => ['username', 'password', 'user_token'],
                'success_rule' => 'JSON status_code must be R200; HTTP 200 alone is insufficient.',
            ],
            'required_fields' => ['username', 'password', 'user_token', 'category_id', 'car_id', 'quality', 'status'],
            'optional_fields' => ['position', 'notes', 'place', 'manufacturer_code', 'visible_code', 'other_code', 'optional_codes[]', 'id_bridge', 'external_id', 'sell_price', 'sell_vat_null', 'sell_date', 'internal_notes', 'tires', 'rims', 'rims_spacing', 'rims_fixing_points', 'tires_width', 'rims_central_diameter', 'rims_quantity', 'tires_height', 'tires_tread_depth', 'tires_quantity', 'price', 'original_currency', 'photo', 'photos[]', 'sticker_note', 'english'],
            'field_contract' => [
                'category' => ['field' => 'category_id', 'notes' => 'Level 3 category required by OpenAPI.'],
                'price' => ['field' => 'price', 'currency_field' => 'original_currency', 'currency_allowed_values' => ['EUR', 'PLN'], 'documentation_status' => 'confirmed_by_documentation', 'notes' => 'OpenAPI says price > 0.00 plus a photo URL are required for the part to be available in e-shop; CRM-only initial import intentionally omits price while keeping photos.'],
                'part_code_oem' => ['primary_field' => 'manufacturer_code', 'visible_field' => 'visible_code', 'additional_fields' => ['other_code', 'optional_codes[]']],
                'vehicle' => ['field' => 'car_id', 'required' => true, 'notes' => 'Woo-only products need a confirmed RRR car_id or confirmed alternative before live import.'],
                'warehouse_location' => ['field' => 'place', 'required' => false],
                'stock_status' => ['field' => 'status', 'required' => true, 'documentation_status' => 'confirmed_by_documentation', 'notes' => 'Values are numeric inventory/sales lifecycle IDs. The /get/part_status probe returned stock/sales states, not publication visibility states.'],
            ],
            'image_handling' => [
                'mode' => 'url_form_fields',
                'main_image_field' => 'photo',
                'gallery_field' => 'photos[]',
                'binary_upload' => false,
                'ordering_rule' => 'photo must equal first photos[] value for main photo/thumbnail generation.',
            ],
            'draft_unpublished_visibility_support' => [
                'explicit_draft_field_found' => false,
                'visibility_fields_found' => [],
                'status_values_confirmed' => false,
                'status_field_is_operational_stock_sales_status' => true,
                'draft_visibility_field' => 'price',
                'draft_visibility_value' => 'omitted',
                'would_create_as_draft_or_unpublished' => false,
                'confirmation_required' => false,
                'notes' => 'CRM-only initial import no longer relies on unconfirmed draft/unpublished status behavior. The documentation-backed e-shop availability guard is omitting price while still sending photo/photos for internal review.',
            ],
            'latest_part_status_probe_result' => $this->latest_part_status_probe_result(),
            'documentation_backed_findings' => [
                'what_import_part_does' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'POST /crm/importPart imports a part using application/x-www-form-urlencoded request data and returns part_id, msg, and status_code on success.',
                ],
                'required_fields' => [
                    'status' => 'confirmed_by_documentation',
                    'fields' => ['username', 'password', 'user_token', 'category_id', 'car_id', 'quality', 'status'],
                ],
                'optional_fields' => [
                    'status' => 'confirmed_by_documentation',
                    'fields' => ['position', 'notes', 'place', 'manufacturer_code', 'visible_code', 'other_code', 'optional_codes[]', 'id_bridge', 'external_id', 'sell_price', 'sell_vat_null', 'sell_date', 'internal_notes', 'tires', 'rims', 'rims_spacing', 'rims_fixing_points', 'tires_width', 'rims_central_diameter', 'rims_quantity', 'tires_height', 'tires_tread_depth', 'tires_quantity', 'price', 'original_currency', 'photo', 'photos[]', 'sticker_note', 'english'],
                ],
                'status_field_meaning' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'The importPart schema describes status as the status ID assigned to the part. The documented status catalog endpoint is /get/part_status, whose observed values are operational stock/sales states, not publication visibility states.',
                ],
                'public_immediately_after_import' => [
                    'status' => 'conditional_rule_confirmed_by_documentation',
                    'answer' => 'The OpenAPI schema documents that price > 0.00 and a photo URL are required for e-shop availability. Therefore an import that includes photos but omits price is interpreted as not e-shop-available after import.',
                    'e_shop_available_after_import_without_price' => false,
                ],
                'hidden_draft_unpublished_private_field' => [
                    'status' => 'not_found_in_documentation',
                    'answer' => 'No importPart field named hidden, draft, unpublished, private, public, visible, visibility, publish, published, active, disabled, shop visibility, or marketplace visibility is documented.',
                ],
                'hide_unpublish_after_import_endpoint' => [
                    'status' => 'not_found_in_documentation',
                    'candidate_documented_existing_part_writes' => ['/crm/updatePart', '/crm/changePartStatus', '/crm/deletePart'],
                    'answer' => 'The OpenAPI documents update, status-change, and delete endpoints for existing parts, but no hide/unpublish-specific endpoint or visibility field.',
                ],
                'external_id_idempotency' => [
                    'status' => 'unknown',
                    'answer' => 'external_id is documented on importPart as Local id and /v2/get/parts supports external_ids filtering. Unlike importCar, importPart documentation does not state that duplicate external_id aborts import or returns the existing part, so idempotency is not confirmed by documentation.',
                ],
                'photo_handling' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'photo is a photo URL. photos[] must include the same first value as photo for correct main photo upload and thumbnail generation.',
                ],
                'category_id' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'category_id is the part category ID field, and a level 3 category is required.',
                ],
                'car_id' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'car_id is required and is the car ID assigned to the part. The OpenAPI does not document a Woo-only alternative; it must come from an existing/imported RRR car record or another documented car lookup/import workflow.',
                ],
                'quality' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'quality is required and is the quality ID assigned to the part. The OpenAPI documents /get/part_quality as the CRM Info endpoint for the part quality list, but importPart does not enumerate allowed quality IDs inline.',
                ],
                'shop_url_show_url' => [
                    'status' => 'unknown',
                    'answer' => 'The part response schema includes shop_url and show_url, but the OpenAPI does not define their semantics or say that missing shop_url means internal-only.',
                ],
            ],
            'listing_visibility_audit' => [
                'scope' => 'Documentation-backed audit of the official OpenAPI schema plus repository documentation references. No /crm/importPart or write endpoint call is made.',
                'searched_terms' => ['importPart', '/crm/importPart', 'status', 'visible', 'visibility', 'active', 'enabled', 'publish', 'published', 'hidden', 'show', 'shop', 'marketplace', 'on_sale', 'display', 'listing', 'public', 'private', 'draft', 'disabled', 'external_id', 'shop_url', 'show_url', 'category_id', 'car_id', 'quality', 'photo', 'photos'],
                'import_part_visibility_field_separate_from_status' => [
                    'status' => 'not_found_in_documentation',
                    'answer' => 'No documented importPart publication visibility field separate from operational status was found.',
                ],
                'imported_parts_not_visible_by_default_setting' => [
                    'status' => 'not_found_in_documentation',
                    'answer' => 'No documented per-request field or API setting was found that creates imported parts hidden/unpublished by default.',
                ],
                'status_0_public_effect' => [
                    'status' => 'unknown',
                    'answer' => 'Documentation/observed status catalog identifies status 0 as In stock / Na stanie, but docs do not state whether importPart with status=0 is public immediately.',
                ],
                'draft_import_queue_mode' => [
                    'status' => 'not_found_in_documentation',
                    'answer' => 'No documented draft/import queue mode for /crm/importPart was found.',
                ],
                'remaining_unknowns' => [
                    'Whether /crm/importPart creates a public shop/marketplace listing immediately when required fields, price > 0.00, and photo are present.',
                    'Whether business settings outside the API schema affect default imported-part visibility.',
                    'Exact semantics of shop_url versus show_url and whether missing shop_url proves internal-only state.',
                    'Whether external_id can safely enforce idempotency for part import without a public listing side effect.',
                ],
            ],
            'write_safety' => [
                'live_create_implemented' => false,
                'no_ovoko_write' => true,
                'no_woo_write' => true,
            ],
        ];
    }

    private function latest_part_status_probe_result(): array
    {
        if (!function_exists('get_option')) {
            return ['available' => false, 'source' => 'get_option_unavailable'];
        }

        $result = get_option('gpswiss_ovoko_part_status_probe_result', []);
        if (!is_array($result) || $result === []) {
            return ['available' => false, 'source' => 'gpswiss_ovoko_part_status_probe_result'];
        }

        return [
            'available' => true,
            'source' => 'gpswiss_ovoko_part_status_probe_result',
            'checked_at' => (string) ($result['checked_at'] ?? ''),
            'endpoint_used' => (string) ($result['endpoint_used'] ?? $result['endpoint'] ?? ''),
            'ok' => !empty($result['ok']),
            'status_count' => (int) ($result['status_count'] ?? count((array) ($result['statuses'] ?? []))),
            'candidate_draft_statuses' => array_values((array) ($result['candidate_draft_statuses'] ?? [])),
            'candidate_hidden_statuses' => array_values((array) ($result['candidate_hidden_statuses'] ?? [])),
            'candidate_inactive_statuses' => array_values((array) ($result['candidate_inactive_statuses'] ?? [])),
            'candidate_public_statuses' => array_values((array) ($result['candidate_public_statuses'] ?? [])),
            'candidate_sold_statuses' => array_values((array) ($result['candidate_sold_statuses'] ?? [])),
            'operational_stock_sales_statuses' => array_values((array) ($result['operational_stock_sales_statuses'] ?? [])),
            'status_catalog_scope' => (string) (($result['interpretation_summary']['status_catalog_scope'] ?? '') ?: ($result['status_catalog_scope'] ?? '')),
            'unknown_statuses' => array_values((array) ($result['unknown_statuses'] ?? [])),
            'interpretation_summary' => (array) ($result['interpretation_summary'] ?? []),
        ];
    }

    private function build_crm_only_payload(string $title, string $sku, string $stockStatus, ?int $stockQuantity, array $partIdentifier, string $manufacturerCode, array $categoryPayload, array $images, string $description, string $storageLocation, array $qualityId, array $carId, int $importStatus): array
    {
        $imageUrls = (array) ($images['image_urls'] ?? []);
        return [
            'category_id' => $categoryPayload['category_id'],
            'car_id' => $carId['value'] !== '' && ctype_digit((string) $carId['value']) ? (int) $carId['value'] : null,
            'quality' => $qualityId['value'] !== '' && ctype_digit((string) $qualityId['value']) ? (int) $qualityId['value'] : null,
            'status' => $importStatus,
            'notes' => $description,
            'place' => $storageLocation,
            'manufacturer_code' => $manufacturerCode !== '' ? $manufacturerCode : (string) $partIdentifier['value'],
            'visible_code' => (string) $partIdentifier['value'],
            'external_id' => $sku,
            'sku' => $sku,
            'photo' => $imageUrls[0] ?? '',
            'photos[]' => $imageUrls,
            '_preview_only_not_sent' => true,
            '_auth_fields_omitted_from_preview' => ['username', 'password', 'user_token'],
            '_create_strategy' => 'crm_only_non_public_initial_import',
            '_non_public_reason' => 'missing_price',
            '_omitted_for_non_public_import' => ['price', 'original_price', 'currency'],
            '_source_summary' => [
                'title' => $title,
                'sku' => $sku,
                'stock_status' => $stockStatus,
                'stock_quantity' => $stockQuantity,
                'part_identifier_source' => $partIdentifier['key'],
                'woo_category' => $categoryPayload['woo_category'],
                'quality_source' => $qualityId['key'],
                'car_id_source' => $carId['key'],
            ],
            '_confirmation_required' => [
                'endpoint' => false,
                'payload_format' => false,
                'live_create_manual_confirmation' => true,
                'car_id_source' => $carId['value'] === '',
                'quality_value' => $qualityId['value'] === '',
            ],
        ];
    }

    private function build_full_payload_preview(string $title, string $sku, string $price, string $stockStatus, ?int $stockQuantity, array $partIdentifier, string $manufacturerCode, array $categoryPayload, array $images, string $description, string $regularPrice, string $salePrice, string $storageLocation, array $qualityId, array $carId, int $importStatus): array
    {
        $payload = $this->build_crm_only_payload($title, $sku, $stockStatus, $stockQuantity, $partIdentifier, $manufacturerCode, $categoryPayload, $images, $description, $storageLocation, $qualityId, $carId, $importStatus);
        $payload['price'] = is_numeric($price) ? (float) $price : $price;
        $payload['original_currency'] = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $payload['_source_summary']['regular_price'] = is_numeric($regularPrice) ? (float) $regularPrice : $regularPrice;
        $payload['_source_summary']['sale_price'] = is_numeric($salePrice) ? (float) $salePrice : $salePrice;

        return [
            'payload' => $payload,
            'warnings' => is_numeric($price) && (float) $price > 0 && !empty($payload['photo'])
                ? [[
                    'code' => 'price_and_photos_may_enable_e_shop_availability',
                    'message' => 'Including price > 0 and a photo URL may make the part available in e-shop.',
                ]]
                : [],
        ];
    }

    private function category_payload(array $categoryReadiness): array
    {
        $mapped = (array) ($categoryReadiness['mapped_terms'] ?? []);
        $first = $mapped[0] ?? [];
        $id = isset($first['ovoko_category_id']) && ctype_digit((string) $first['ovoko_category_id']) ? (int) $first['ovoko_category_id'] : null;
        return ['category_id' => $id, 'woo_category' => ['term_id' => $first['term_id'] ?? null, 'name' => $first['name'] ?? '', 'slug' => $first['slug'] ?? '']];
    }

    private function storage_location(int $productId): string
    {
        $value = $this->first_meta_value($productId, ['_gps_storage_location', 'storage_location', '_storage_location', 'place', '_ovoko_place']);
        return (string) $value['value'];
    }

    private function future_live_readiness(array $result, array $categoryPayload, array $images, string $price, string $sku, array $carId, array $qualityId): array
    {
        $blockers = [];
        if ($result['product_status'] !== 'draft') { $blockers[] = 'product_status_must_be_draft_or_approved'; }
        if (!empty($result['duplicate_checks']['has_existing_ovoko_part_id'])) { $blockers[] = 'existing_ovoko_part_id'; }
        if ($sku === '') { $blockers[] = 'missing_sku'; }
        if ($categoryPayload['category_id'] === null) { $blockers[] = 'missing_ovoko_category_id'; }
        if (empty($images['image_urls'])) { $blockers[] = 'missing_image'; }
        if ($carId['value'] === '') { $blockers[] = 'missing_required_car_id'; }
        if ($qualityId['value'] === '') { $blockers[] = 'missing_required_quality'; }
        $blockers[] = 'explicit_admin_confirmation_required';
        $blockers[] = 'single_product_only_required';
        $blockers[] = 'recent_dry_run_preview_required';
        $blockers[] = 'live_create_not_implemented';
        return [
            'ready' => false,
            'blocked' => true,
            'blockers' => array_values(array_unique($blockers)),
            'live_creation_enabled' => false,
            'crm_only_non_public_initial_import_omits_price' => true,
            'photos_included_for_internal_review' => !empty($images['image_urls']),
        ];
    }

    private function product_value($product, int $productId, string $name, string $metaKey): string
    {
        $method = 'get_' . $name;
        if (is_object($product) && method_exists($product, $method)) {
            $value = $product->{$method}();
            return trim((string) $value);
        }
        return trim((string) get_post_meta($productId, $metaKey, true));
    }

    private function product_stock_quantity($product, int $productId): ?int
    {
        if (is_object($product) && method_exists($product, 'get_stock_quantity')) {
            $quantity = $product->get_stock_quantity();
            return $quantity === null ? null : (int) $quantity;
        }
        $raw = get_post_meta($productId, '_stock', true);
        return $raw === '' ? null : (int) $raw;
    }

    private function product_description($product, int $productId): string
    {
        if (is_object($product) && method_exists($product, 'get_description')) {
            return (string) $product->get_description();
        }
        $post = get_post($productId);
        return $post ? (string) ($post->post_content ?? '') : '';
    }


    private function resolved_quality_id(int $productId): array
    {
        $qualityId = $this->first_meta_value($productId, ['_ovoko_quality_id', 'ovoko_quality_id', '_rrr_quality_id', 'rrr_quality_id']);
        if ($qualityId['value'] !== '') {
            return $qualityId;
        }

        $default = function_exists('get_option') ? trim((string) get_option('gpswiss_ovoko_default_import_quality_id', '2')) : '2';
        return $default === '' ? ['key' => '', 'value' => ''] : ['key' => 'gpswiss_ovoko_default_import_quality_id', 'value' => $default];
    }

    private function resolved_import_status(): int
    {
        $default = function_exists('get_option') ? trim((string) get_option('gpswiss_ovoko_crm_only_import_status', '0')) : '0';
        return ctype_digit($default) ? (int) $default : 0;
    }

    private function first_meta_value(int $productId, array $keys): array
    {
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                return ['key' => $key, 'value' => $value];
            }
        }
        return ['key' => '', 'value' => ''];
    }

    private function category_mapping_readiness(int $productId): array
    {
        $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($productId, 'product_cat') : [];
        if (!is_array($terms)) {
            return ['known' => false, 'terms' => [], 'mapped_terms' => [], 'unmapped_terms' => [], 'mapping_meta_keys_checked' => ['_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id']];
        }
        $mapped = [];
        $unmapped = [];
        $keys = ['_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id'];
        foreach ($terms as $term) {
            $termId = (int) ($term->term_id ?? 0);
            $row = ['term_id' => $termId, 'name' => (string) ($term->name ?? ''), 'slug' => (string) ($term->slug ?? '')];
            $ovokoCategoryId = '';
            foreach ($keys as $key) {
                $value = function_exists('get_term_meta') ? trim((string) get_term_meta($termId, $key, true)) : '';
                if ($value !== '') {
                    $ovokoCategoryId = $value;
                    $row['mapping_meta_key'] = $key;
                    $row['ovoko_category_id'] = $value;
                    break;
                }
            }
            if ($ovokoCategoryId !== '') {
                $mapped[] = $row;
            } else {
                $unmapped[] = $row;
            }
        }
        return ['known' => true, 'terms' => array_merge($mapped, $unmapped), 'mapped_terms' => $mapped, 'unmapped_terms' => $unmapped, 'ready' => $terms !== [] && $unmapped === [], 'mapping_meta_keys_checked' => $keys];
    }

    private function image_preview($product, int $productId): array
    {
        $featuredId = 0;
        if (is_object($product) && method_exists($product, 'get_image_id')) {
            $featuredId = (int) $product->get_image_id();
        }
        if ($featuredId <= 0 && function_exists('get_post_thumbnail_id')) {
            $featuredId = (int) get_post_thumbnail_id($productId);
        }

        $galleryIds = [];
        if (is_object($product) && method_exists($product, 'get_gallery_image_ids')) {
            $galleryIds = array_map('intval', (array) $product->get_gallery_image_ids());
        }
        if ($galleryIds === []) {
            $rawGallery = trim((string) get_post_meta($productId, '_product_image_gallery', true));
            if ($rawGallery !== '') {
                $galleryIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $rawGallery)))));
            }
        }

        $ids = array_values(array_unique(array_filter(array_merge([$featuredId], $galleryIds))));
        $urls = [];
        $details = [];
        foreach ($ids as $id) {
            $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';
            $accessible = $url !== '' && (!function_exists('wp_http_validate_url') || (bool) wp_http_validate_url($url));
            if ($url !== '') {
                $urls[] = $url;
            }
            $details[] = ['attachment_id' => $id, 'url' => $url, 'accessible' => $accessible, 'binary_sent' => false];
        }

        return [
            'featured_image_id' => $featuredId,
            'gallery_image_ids' => $galleryIds,
            'image_urls' => $urls,
            'image_details' => $details,
            'upload_policy' => 'preview_urls_only_no_upload',
        ];
    }

    private function duplicate_checks(int $productId, string $sku, string $manufacturerCode, string $partNumber): array
    {
        $existingOvokoPartId = trim((string) get_post_meta($productId, '_ovoko_part_id', true));
        $foundMeta = [];
        foreach (self::DUPLICATE_META_KEYS as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                $foundMeta[$key] = $value;
            }
        }

        $warnings = [];
        $blocking = [];
        if ($existingOvokoPartId !== '') {
            $blocking[] = ['code' => 'duplicate_existing_ovoko_part_id', 'message' => '_ovoko_part_id already exists.', 'context' => ['_ovoko_part_id' => $existingOvokoPartId]];
        }
        foreach (['ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id'] as $key) {
            if (!empty($foundMeta[$key])) {
                $blocking[] = ['code' => 'duplicate_existing_part_identity_meta', 'message' => 'Existing part identity meta suggests this product is already linked to an external/Ovoko part.', 'context' => ['meta_key' => $key, 'meta_value' => $foundMeta[$key]]];
            }
        }
        if ($sku !== '' && preg_match('/^GPSW-OVK-\d+$/i', $sku) === 1) {
            $blocking[] = ['code' => 'sku_looks_like_imported_ovoko_sku', 'message' => 'SKU looks like an imported Ovoko SKU.', 'context' => ['sku' => $sku]];
        }

        $existingMatchRules = $this->existing_match_rule_markers($productId);
        if ($existingMatchRules !== []) {
            $warnings[] = ['code' => 'appears_matched_by_existing_ovoko_sync_match_rules', 'message' => 'Product has metadata that existing Ovoko sync matching uses or creates.', 'context' => ['markers' => $existingMatchRules]];
        }

        $sameSkuLinked = $sku === '' ? [] : $this->find_other_products_with_meta_and_ovoko_part($productId, '_sku', $sku);
        $sameManufacturerLinked = $manufacturerCode === '' ? [] : $this->find_other_products_with_meta_and_ovoko_part($productId, '_manufacturer_code', $manufacturerCode);
        if ($sameSkuLinked !== []) {
            $blocking[] = ['code' => 'another_product_same_sku_has_ovoko_part_id', 'message' => 'Another product with the same SKU already has _ovoko_part_id.', 'context' => ['product_ids' => $sameSkuLinked, 'sku' => $sku]];
        }
        if ($sameManufacturerLinked !== []) {
            $warnings[] = ['code' => 'another_product_same_manufacturer_code_has_ovoko_part_id', 'message' => 'Another product with the same manufacturer code already has _ovoko_part_id.', 'context' => ['product_ids' => $sameManufacturerLinked, 'manufacturer_code' => $manufacturerCode]];
        }

        return [
            'has_existing_ovoko_part_id' => $existingOvokoPartId !== '',
            'existing_ovoko_part_id' => $existingOvokoPartId,
            'sku' => $sku,
            'manufacturer_code' => $manufacturerCode,
            'part_number' => $partNumber,
            'identity_meta_found' => $foundMeta,
            'sku_looks_like_imported_ovoko_sku' => $sku !== '' && preg_match('/^GPSW-OVK-\d+$/i', $sku) === 1,
            'appears_matched_by_existing_ovoko_sync_match_rules' => $existingMatchRules !== [],
            'existing_match_rule_markers' => $existingMatchRules,
            'other_products_same_sku_with_ovoko_part_id' => $sameSkuLinked,
            'other_products_same_manufacturer_code_with_ovoko_part_id' => $sameManufacturerLinked,
            'warnings' => $warnings,
            'blocking_warnings' => $blocking,
        ];
    }

    private function existing_match_rule_markers(int $productId): array
    {
        $markers = [];
        foreach (['_allegro_offer_id', 'ovoko_id', '_ovoko_source_id', '_ovoko_raw_payload', '_ovoko_source_url', '_ovoko_manufacturer_code'] as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                $markers[$key] = $value;
            }
        }
        if ((string) get_post_meta($productId, 'source', true) === 'ovoko_master') {
            $markers['source'] = 'ovoko_master';
        }
        return $markers;
    }

    private function find_other_products_with_meta_and_ovoko_part(int $productId, string $metaKey, string $metaValue): array
    {
        if (!function_exists('get_posts')) {
            return [];
        }
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 10,
            'exclude' => [$productId],
            'meta_query' => [
                'relation' => 'AND',
                ['key' => $metaKey, 'value' => $metaValue, 'compare' => '='],
                ['key' => '_ovoko_part_id', 'compare' => 'EXISTS'],
            ],
        ]);
        return array_values(array_filter(array_map('intval', (array) $ids), static fn(int $id): bool => $id !== $productId));
    }
}
