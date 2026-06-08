<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCreatePartPreviewService
{
    private const ACTION_NAME = 'Preview Woo → Ovoko CRM-only import payload';
    private const MODE = 'dry_run_no_ovoko_write_no_woo_write';
    private const PROPOSED_ENDPOINT = 'DOCUMENTED_ENDPOINT_WRITE_BLOCKED';
    private const PROPOSED_ENDPOINT_PATH = '/crm/importPart';
    private const ALLOWED_STATUSES = ['draft'];
    private const PHOTO_PAYLOAD_MODE_OPTION = 'gpswiss_ovoko_import_part_photo_payload_mode';
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
        $carId = $this->resolved_car_id($productId);

        $result['product_status'] = $status;
        $result['post_type'] = $postType;
        $result['duplicate_checks'] = $duplicates;
        $result['images'] = $images;
        $result['source_woo_fields_meta_used'] = [
            'post' => ['ID' => $productId, 'post_type' => $postType, 'post_status' => $status, 'post_title' => $title],
            'product_methods' => ['get_sku', 'get_price', 'get_regular_price', 'get_sale_price', 'get_stock_status', 'get_stock_quantity', 'get_image_id', 'get_gallery_image_ids'],
            'meta_keys' => array_values(array_unique(array_merge(['_sku', '_price', '_regular_price', '_sale_price', '_stock_status', '_stock', '_thumbnail_id', '_product_image_gallery'], self::PART_IDENTIFIER_META_KEYS, self::DUPLICATE_META_KEYS, ['_ovoko_manufacturer_code', 'ovoko_id', 'source', '_ovoko_car_id', 'ovoko_car_id', '_gps_ovoko_car_id', 'gps_ovoko_car_id', '_ovoko_quality_id', 'ovoko_quality_id', '_gps_storage_location', 'storage_location', 'place', '_gps_selected_price_pln', '_gps_selected_price_source', '_gps_allegro_price_suggestion', '_gps_allegro_price_filtered_offer_count', '_gps_allegro_price_confidence', '_gps_allegro_price_query', '_gps_manual_price_pln', '_gps_ovoko_price_suggestion_pln', '_gps_ovoko_price_suggestion_source']))),
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
        if (!empty($categoryPayload['missing_woo_category_mapping'])) {
            $this->add_validation($result, 'warning', 'missing_woo_category_mapping_for_ovoko_category_' . (string) $categoryPayload['category_id'], 'Ovoko category detected but Woo category mapping missing');
        }
        if ($carId['value'] === '') {
            $this->add_validation($result, 'error', 'missing_required_car_id', 'CRM-only importPart preview requires either a product Ovoko/RRR car_id or a configured CRM-only placeholder car_id before any future live import.');
        } elseif (!empty($carId['is_placeholder'])) {
            $this->add_validation($result, 'warning', 'using_placeholder_car_id', 'Using configured placeholder car_id only to satisfy /crm/importPart. Staff must correct vehicle mapping in Ovoko before publishing.');
        }
        if ($qualityId['value'] === '') {
            $this->add_validation($result, 'error', 'missing_required_quality', 'CRM-only importPart preview requires a configured/default quality value before any future live import.');
        }
        if (empty($images['image_urls'])) {
            $this->add_validation($result, 'error', 'missing_images', 'At least one accessible product image URL is required for create-part readiness.');
        }
        foreach ((array) ($images['image_details'] ?? []) as $imageDetail) {
            if (empty($imageDetail['accessible'])) {
                $this->add_validation($result, 'error', 'inaccessible_image_url', 'Product image URL is missing or not accessible for preview.', ['attachment_id' => (int) ($imageDetail['attachment_id'] ?? 0), 'url' => (string) ($imageDetail['url'] ?? ''), 'diagnostics' => (array) ($imageDetail['diagnostics'] ?? [])]);
            }
        }
        if (empty($images['photo_equals_first_photos'])) {
            $this->add_validation($result, 'error', 'photo_must_equal_first_photos', 'The photo field must exactly equal the first photos[] value before live import.');
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
        $result['photo_payload_mode'] = (string) ($images['photo_payload_mode'] ?? 'repeated_url_fields');
        $result['omitted_for_non_public_import'] = ['price', 'original_price', 'currency', 'original_currency'];
        $priceSuggestionData = $this->crm_only_price_suggestion_data($productId);
        $result['selected_price_source'] = (string) ($priceSuggestionData['source'] ?? '');
        $result['selected_price_pln'] = (string) ($priceSuggestionData['price'] ?? '');
        $result['internal_notes_preview'] = $this->crm_only_internal_notes_from_price_data($priceSuggestionData);
        $result['listing_text_preview'] = $storageLocation;
        $result['listing_text_source'] = $storageLocation !== '' ? 'gmail_storage_location' : '';
        $result['price_fields_omitted'] = true;
        $result['price_fields_omitted_from_ovoko_payload'] = true;
        $result['live_create_still_requires_manual_confirmation'] = true;
        $result['car_id'] = $carId['value'] !== '' && ctype_digit((string) $carId['value']) ? (int) $carId['value'] : null;
        $result['car_id_source'] = (string) $carId['key'];
        $result['car_id_is_placeholder'] = !empty($carId['is_placeholder']);
        $result['car_id_review_required'] = !empty($carId['review_required']);
        $result['placeholder_car_id_warning'] = !empty($carId['is_placeholder']) ? $this->default_placeholder_car_note() : '';

        $result['proposed_payload'] = $this->build_crm_only_payload($productId, $title, $sku, $stockStatus, $stockQuantity, $partIdentifier, $manufacturerCode, $categoryPayload, $images, $description, $storageLocation, $qualityId, $carId, $importStatus);
        $result['full_payload_preview'] = $this->build_full_payload_preview($productId, $title, $sku, $price, $stockStatus, $stockQuantity, $partIdentifier, $manufacturerCode, $categoryPayload, $images, $description, $regularPrice, $salePrice, $storageLocation, $qualityId, $carId, $importStatus);

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
            'car_id' => $result['car_id'],
            'car_id_source' => $result['car_id_source'],
            'car_id_is_placeholder' => $result['car_id_is_placeholder'],
            'car_id_review_required' => $result['car_id_review_required'],
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
            'photo_payload_mode' => 'repeated_url_fields',
            'car_id' => null,
            'car_id_source' => '',
            'car_id_is_placeholder' => false,
            'car_id_review_required' => false,
            'omitted_for_non_public_import' => ['price', 'original_price', 'currency', 'original_currency'],
            'selected_price_source' => '',
            'selected_price_pln' => '',
            'internal_notes_preview' => '',
            'listing_text_preview' => '',
            'listing_text_source' => '',
            'price_fields_omitted' => true,
            'price_fields_omitted_from_ovoko_payload' => true,
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
                'repo_evidence' => ['RrrApiClient implements a manual, admin-only, single-product CRM-only /crm/importPart action gated by nonce, confirmations, draft-only preview eligibility, no existing part ID meta, no price fields, and required photos.'],
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
                'vehicle' => ['field' => 'car_id', 'required' => true, 'notes' => 'For CRM-only no-price imports, a configured placeholder car_id may satisfy the required technical field, but staff must correct vehicle mapping in Ovoko before publishing. Placeholder car_id is never allowed for full/public imports with price.'],
                'warehouse_location' => ['field' => 'place', 'required' => false],
                'listing_ad_text' => ['field' => 'notes', 'required' => false, 'documentation_status' => 'confirmed_by_documentation', 'notes' => 'CRM-only import uses notes only for the Gmail storage location/warehouse number; Woo/Gmail body text and suggested prices must not be sent here.'],
                'sticker_note' => ['field' => 'sticker_note', 'required' => false, 'documentation_status' => 'confirmed_by_documentation', 'notes' => 'Intentionally omitted for CRM-only import.'],
                'stock_status' => ['field' => 'status', 'required' => true, 'documentation_status' => 'confirmed_by_documentation', 'notes' => 'Values are numeric inventory/sales lifecycle IDs. The /get/part_status probe returned stock/sales states, not publication visibility states.'],
            ],
            'image_handling' => [
                'mode' => 'repeated_url_fields',
                'main_image_field' => 'photo',
                'gallery_field' => 'photos[]',
                'binary_upload' => false,
                'encoding' => 'application/x-www-form-urlencoded repeated fields: photo=url1&photos[]=url1&photos[]=url2; do not send photos[] as a nested array string.',
                'ordering_rule' => 'photo must equal first photos[] value for main photo/thumbnail generation.',
                'live_mode_confirmed' => false,
                'available_preview_modes' => ['url_fields', 'repeated_url_fields', 'multipart_file_upload', 'local_file_path_not_allowed'],
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
                    'answer' => 'photo is documented as a photo URL. photos[] must include the same first value as photo for correct main photo upload and thumbnail generation. The OpenAPI request content type for /crm/importPart is application/x-www-form-urlencoded, not multipart/form-data; multipart_file_upload remains preview-only until Ovoko confirms it.',
                ],
                'category_id' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'category_id is the part category ID field, and a level 3 category is required.',
                ],
                'car_id' => [
                    'status' => 'confirmed_by_documentation',
                    'answer' => 'car_id is required by importPart. For Woo/Gmail CRM-only no-price imports, preview may use a configured placeholder car_id as a technical field only; it is not authoritative vehicle compatibility and must be manually corrected in Ovoko before publishing.',
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
                'scope' => 'Documentation-backed audit of the official OpenAPI schema plus repository documentation references. The separate live action can call /crm/importPart only after strict one-product CRM-only no-price confirmations.' ,
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
                'live_create_implemented' => true,
                'live_create_scope' => 'manual_admin_only_single_product_crm_only_no_price',
                'requires_manage_options' => true,
                'requires_nonce' => true,
                'requires_all_confirmations' => true,
                'no_bulk' => true,
                'no_cron' => true,
                'no_product_save_hook' => true,
                'preview_no_ovoko_write' => true,
                'preview_no_woo_write' => true,
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

    private function build_crm_only_payload(int $productId, string $title, string $sku, string $stockStatus, ?int $stockQuantity, array $partIdentifier, string $manufacturerCode, array $categoryPayload, array $images, string $description, string $storageLocation, array $qualityId, array $carId, int $importStatus): array
    {
        $imageUrls = (array) ($images['image_urls'] ?? []);
        $internalNotes = $this->crm_only_internal_notes($productId);
        $payload = [
            'category_id' => $categoryPayload['category_id'],
            'car_id' => $carId['value'] !== '' && ctype_digit((string) $carId['value']) ? (int) $carId['value'] : null,
            'quality' => $qualityId['value'] !== '' && ctype_digit((string) $qualityId['value']) ? (int) $qualityId['value'] : null,
            'status' => $importStatus,
            'place' => $storageLocation,
            'manufacturer_code' => $manufacturerCode !== '' ? $manufacturerCode : (string) $partIdentifier['value'],
            'visible_code' => (string) $partIdentifier['value'],
            'external_id' => $sku,
            'sku' => $sku,
            'photo' => $imageUrls[0] ?? '',
            'photos[]' => $imageUrls,
            '_photo_payload_mode' => (string) ($images['photo_payload_mode'] ?? 'repeated_url_fields'),
            '_photo_equals_first_photos' => ($imageUrls[0] ?? '') !== '' && ($imageUrls[0] ?? '') === ((array) $imageUrls)[0],
            '_preview_only_not_sent' => true,
            '_auth_fields_omitted_from_preview' => ['username', 'password', 'user_token'],
            '_create_strategy' => 'crm_only_non_public_initial_import',
            '_non_public_reason' => 'missing_price',
            '_omitted_for_non_public_import' => ['price', 'original_price', 'currency', 'original_currency'],
            '_text_field_policy' => [
                'listing_ad_text_field' => 'notes',
                'listing_text_source' => $storageLocation !== '' ? 'gmail_storage_location' : '',
                'listing_text_contains_storage_location_only' => $storageLocation !== '',
                'description_omitted' => true,
                'sticker_note_omitted' => true,
                'suggested_price_kept_staff_only_in_internal_notes' => true,
            ],
            '_source_summary' => [
                'title' => $title,
                'sku' => $sku,
                'stock_status' => $stockStatus,
                'stock_quantity' => $stockQuantity,
                'part_identifier_source' => $partIdentifier['key'],
                'woo_category' => $categoryPayload['woo_category'],
                'quality_source' => $qualityId['key'],
                'car_id_source' => $carId['key'],
                'car_id_is_placeholder' => !empty($carId['is_placeholder']),
                'car_id_review_required' => !empty($carId['review_required']),
                'description_length' => mb_strlen($description),
            ],
            '_confirmation_required' => [
                'endpoint' => false,
                'payload_format' => false,
                'live_create_manual_confirmation' => true,
                'car_id_source' => $carId['value'] === '',
                'car_id_placeholder_review' => !empty($carId['is_placeholder']),
                'quality_value' => $qualityId['value'] === '',
            ],
        ];

        if ($storageLocation !== '') {
            $payload['notes'] = $storageLocation;
        }

        if ($internalNotes !== '') {
            $payload['internal_notes'] = $internalNotes;
        }

        return $payload;
    }

    private function crm_only_internal_notes(int $productId): string
    {
        return $this->crm_only_internal_notes_from_price_data($this->crm_only_price_suggestion_data($productId));
    }

    private function crm_only_internal_notes_from_price_data(array $priceData): string
    {
        $price = (string) ($priceData['price'] ?? '');
        $source = (string) ($priceData['source'] ?? '');
        if ($price === '' || $source === '') {
            return '';
        }

        if ($source === 'manual_override') {
            return 'Cena testowa/ręczna: ' . $price . ' PLN' . "\n" . 'Źródło: manual_override';
        }

        if ($source === 'ovoko_price_suggestion') {
            $ovokoSource = (string) ($priceData['ovoko_source'] ?? '');
            return implode("\n", array_filter([
                'Sugerowana cena Ovoko: ' . $price . ' PLN',
                'Źródło: ' . ($ovokoSource !== '' ? $ovokoSource : 'ovoko_price_suggestion'),
                'Dane z dopasowanej części Ovoko',
            ], static function ($line) { return $line !== ''; }));
        }

        if (in_array($source, ['allegro_api', 'allegro_suggestion'], true)) {
            $lines = ['Sugerowana cena Allegro: ' . $price . ' PLN', 'Źródło: Allegro API'];
            $filteredOfferCount = (string) ($priceData['filtered_offer_count'] ?? '');
            if ($filteredOfferCount !== '') {
                $lines[] = 'Liczba ofert filtrowanych: ' . $filteredOfferCount;
            }
            $confidence = (string) ($priceData['confidence'] ?? '');
            if ($confidence !== '') {
                $lines[] = 'Confidence: ' . $confidence;
            }
            $query = (string) ($priceData['query'] ?? '');
            if ($query !== '') {
                $lines[] = 'Query: ' . $query;
            }
            return implode("\n", $lines);
        }

        return 'Sugerowana cena z Woo/Gmail Importer: ' . $price . ' PLN';
    }

    private function crm_only_price_suggestion_data(int $productId): array
    {
        $stagingItemId = (int) get_post_meta($productId, '_gps_source_staging_item_id', true);
        $metaProductIds = $stagingItemId > 0 ? [$productId, $stagingItemId] : [$productId];
        $source = $this->first_non_empty_meta($metaProductIds, ['_gps_selected_price_source']);
        $price = $this->first_non_empty_meta($metaProductIds, ['_gps_selected_price_pln']);

        if ($source === 'manual_override') {
            $manualPrice = $this->first_non_empty_meta($metaProductIds, ['_gps_manual_price_pln']);
            return ['source' => $source, 'price' => $this->format_crm_only_price($price !== '' ? $price : $manualPrice)];
        }

        if (in_array($source, ['allegro_api', 'allegro_suggestion'], true)) {
            $allegroPrice = $this->first_non_empty_meta($metaProductIds, ['_gps_allegro_price_suggestion']);
            return [
                'source' => $source,
                'price' => $this->format_crm_only_price($price !== '' ? $price : $allegroPrice),
                'confidence' => $this->first_non_empty_meta($metaProductIds, ['_gps_allegro_price_confidence']),
                'filtered_offer_count' => $this->first_non_empty_meta($metaProductIds, ['_gps_allegro_price_filtered_offer_count']),
                'query' => $this->first_non_empty_meta($metaProductIds, ['_gps_allegro_price_query']),
            ];
        }

        if ($source === 'ovoko_price_suggestion') {
            $ovokoPrice = $this->first_non_empty_meta($metaProductIds, ['_gps_ovoko_price_suggestion_pln']);
            return [
                'source' => $source,
                'price' => $this->format_crm_only_price($price !== '' ? $price : $ovokoPrice),
                'ovoko_source' => $this->first_non_empty_meta($metaProductIds, ['_gps_ovoko_price_suggestion_source']),
            ];
        }

        return ['source' => $source, 'price' => $this->format_crm_only_price($price)];
    }

    private function first_non_empty_meta(array $productIds, array $keys): string
    {
        foreach ($productIds as $productId) {
            foreach ($keys as $key) {
                $value = trim((string) get_post_meta((int) $productId, $key, true));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function format_crm_only_price(string $price): string
    {
        $price = trim($price);
        if ($price === '' || !is_numeric(str_replace(',', '.', $price))) {
            return $price;
        }
        $normalized = str_replace(',', '.', $price);
        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }
        return $normalized;
    }

    private function build_full_payload_preview(int $productId, string $title, string $sku, string $price, string $stockStatus, ?int $stockQuantity, array $partIdentifier, string $manufacturerCode, array $categoryPayload, array $images, string $description, string $regularPrice, string $salePrice, string $storageLocation, array $qualityId, array $carId, int $importStatus): array
    {
        $payload = $this->build_crm_only_payload($productId, $title, $sku, $stockStatus, $stockQuantity, $partIdentifier, $manufacturerCode, $categoryPayload, $images, $description, $storageLocation, $qualityId, $carId, $importStatus);
        $payload['price'] = is_numeric($price) ? (float) $price : $price;
        $payload['original_currency'] = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $payload['_source_summary']['regular_price'] = is_numeric($regularPrice) ? (float) $regularPrice : $regularPrice;
        $payload['_source_summary']['sale_price'] = is_numeric($salePrice) ? (float) $salePrice : $salePrice;

        $warnings = [];
        if (is_numeric($price) && (float) $price > 0 && !empty($payload['photo'])) {
            $warnings[] = [
                'code' => 'price_and_photos_may_enable_e_shop_availability',
                'message' => 'Including price > 0 and a photo URL may make the part available in e-shop.',
            ];
        }
        if (!empty($carId['is_placeholder'])) {
            $warnings[] = [
                'code' => 'placeholder_car_id_not_allowed_for_public_import',
                'message' => 'Configured placeholder car_id is allowed only for CRM-only imports without price and must not be used for any full/public import payload.',
            ];
            $payload['car_id'] = null;
            $payload['_placeholder_car_id_not_allowed_for_public_import'] = true;
        }

        return [
            'payload' => $payload,
            'warnings' => $warnings,
        ];
    }

    private function category_payload(array $categoryReadiness): array
    {
        $mapped = (array) ($categoryReadiness['mapped_terms'] ?? []);
        $first = $mapped[0] ?? [];
        $id = isset($first['ovoko_category_id']) && ctype_digit((string) $first['ovoko_category_id']) ? (int) $first['ovoko_category_id'] : null;
        $payload = ['category_id' => $id, 'woo_category' => ['term_id' => $first['term_id'] ?? null, 'name' => $first['name'] ?? '', 'slug' => $first['slug'] ?? '']];
        if ($id === null && !empty($categoryReadiness['trusted_panel_suggestion']['category_id'])) {
            $suggestion = (array) $categoryReadiness['trusted_panel_suggestion'];
            $payload['category_id'] = (int) $suggestion['category_id'];
            $payload['ovoko_category_suggestion'] = $suggestion;
            $payload['missing_woo_category_mapping'] = true;
            $payload['mapping_message'] = 'Ovoko category detected but Woo category mapping missing';
        }
        return $payload;
    }

    private function storage_location(int $productId): string
    {
        $value = $this->first_meta_value($productId, ['_gps_storage_location', 'storage_location', '_storage_location', 'place', '_ovoko_place']);
        return (string) $value['value'];
    }

    private function future_live_readiness(array $result, array $categoryPayload, array $images, string $price, string $sku, array $carId, array $qualityId): array
    {
        $blockers = [];
        $usesPlaceholderCarId = !empty($carId['is_placeholder']);
        $crmOnlyNoPricePolicyAllowsPlaceholder = $usesPlaceholderCarId
            && (string) ($result['create_strategy'] ?? '') === 'crm_only_non_public_initial_import'
            && !empty($result['e_shop_visibility_rule_confirmed_by_documentation'])
            && empty($result['e_shop_available_after_import'])
            && in_array('price', (array) ($result['omitted_for_non_public_import'] ?? []), true);
        if ($result['product_status'] !== 'draft') { $blockers[] = 'product_status_must_be_draft_or_approved'; }
        if (!empty($result['duplicate_checks']['has_existing_ovoko_part_id'])) { $blockers[] = 'existing_ovoko_part_id'; }
        if ($sku === '') { $blockers[] = 'missing_sku'; }
        if ($categoryPayload['category_id'] === null) { $blockers[] = 'missing_ovoko_category_id'; }
        if (!empty($categoryPayload['missing_woo_category_mapping'])) { $blockers[] = 'missing_woo_category_mapping_for_ovoko_category_' . (string) $categoryPayload['category_id']; }
        if (empty($images['image_urls'])) { $blockers[] = 'missing_image'; }
        if (empty($images['all_images_accessible'])) { $blockers[] = 'image_url_accessibility_not_confirmed'; }
        if (empty($images['photo_equals_first_photos'])) { $blockers[] = 'photo_must_equal_first_photos'; }
        if ($carId['value'] === '') { $blockers[] = 'missing_required_car_id'; }
        if ($usesPlaceholderCarId && !$crmOnlyNoPricePolicyAllowsPlaceholder) { $blockers[] = 'placeholder_car_id_not_allowed_for_selected_strategy'; }
        if ($qualityId['value'] === '') { $blockers[] = 'missing_required_quality'; }
        $blockers[] = 'explicit_admin_confirmation_required';
        $blockers[] = 'single_product_only_required';
        $blockers[] = 'recent_dry_run_preview_required';
        return [
            'ready' => false,
            'blocked' => true,
            'blockers' => array_values(array_unique($blockers)),
            'live_creation_enabled' => true,
            'crm_only_non_public_initial_import_omits_price' => true,
            'photos_included_for_internal_review' => !empty($images['image_urls']),
            'image_url_accessibility_confirmed' => !empty($images['all_images_accessible']),
            'photo_payload_mode' => (string) ($images['photo_payload_mode'] ?? 'repeated_url_fields'),
            'placeholder_car_id_allowed_for_crm_only_no_price_import' => $crmOnlyNoPricePolicyAllowsPlaceholder,
            'placeholder_car_id_requires_admin_confirmation' => $usesPlaceholderCarId,
            'placeholder_car_id_live_import_constraints' => [
                'strategy' => 'crm_only_non_public_initial_import',
                'price_omitted' => true,
                'e_shop_available_after_import' => false,
                'admin_must_explicitly_confirm_placeholder_car_id_usage' => true,
                'live_action_scope' => 'single_product_only',
                'never_for_full_public_import_with_price' => true,
            ],
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


    private function resolved_car_id(int $productId): array
    {
        $carId = $this->first_meta_value($productId, ['_ovoko_car_id', 'ovoko_car_id', '_gps_ovoko_car_id', 'gps_ovoko_car_id']);
        if ($carId['value'] !== '') {
            $carId['is_placeholder'] = false;
            $carId['review_required'] = false;
            return $carId;
        }

        $default = $this->option_value('gpswiss_ovoko_default_crm_import_car_id', '');
        if ($default !== '' && ctype_digit($default)) {
            return [
                'key' => 'configured_placeholder_car_id',
                'value' => $default,
                'is_placeholder' => true,
                'review_required' => true,
            ];
        }

        return ['key' => '', 'value' => '', 'is_placeholder' => false, 'review_required' => false];
    }

    private function default_placeholder_car_note(): string
    {
        $note = $this->option_value('gpswiss_ovoko_default_crm_import_car_note', '');
        if ($note !== '') {
            return $note;
        }

        return 'Placeholder car_id used for CRM-only import. Vehicle must be corrected manually in Ovoko.';
    }

    private function option_value(string $key, string $default): string
    {
        if (!function_exists('get_option')) {
            return $default;
        }

        $value = trim((string) get_option($key, ''));
        if ($value !== '') {
            return $value;
        }

        $settings = get_option('gpswiss_ovoko_settings', []);
        if (is_array($settings) && array_key_exists($key, $settings)) {
            $value = trim((string) $settings[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
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
        $trustedSuggestion = $this->trusted_panel_category_suggestion($productId);
        return ['known' => true, 'terms' => array_merge($mapped, $unmapped), 'mapped_terms' => $mapped, 'unmapped_terms' => $unmapped, 'ready' => ($terms !== [] && $unmapped === []) || $trustedSuggestion !== [], 'mapping_meta_keys_checked' => $keys, 'trusted_panel_suggestion' => $trustedSuggestion, 'mapping_message' => ($trustedSuggestion !== [] && $mapped === []) ? 'Ovoko category detected but Woo category mapping missing' : ''];
    }


    private function trusted_panel_category_suggestion(int $productId): array
    {
        $status = trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_status', true));
        $confidence = trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_confidence', true));
        $sourceType = trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_source_type', true));
        $categoryId = trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_category_id', true));
        if ($status !== 'completed' || $confidence !== 'high' || $sourceType !== 'panel_marketplace_category_suggestions' || !ctype_digit($categoryId)) {
            return [];
        }
        return [
            'category_id' => (int) $categoryId,
            'category_name' => trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_category_name', true)),
            'category_path' => trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_category_path', true)),
            'confidence' => $confidence,
            'source_type' => $sourceType,
            'status' => $status,
            'dimensions' => trim((string) get_post_meta($productId, '_gps_ovoko_category_suggestion_dimensions', true)),
        ];
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
        foreach ($ids as $index => $id) {
            $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';
            if ($url !== '') {
                $urls[] = $url;
            }
            $diagnostics = $this->diagnose_image_url($url);
            $details[] = [
                'attachment_id' => $id,
                'local_file_path' => function_exists('get_attached_file') ? (string) get_attached_file($id) : '',
                'url' => $url,
                'public_url' => $url,
                'accessible' => !empty($diagnostics['accessible']),
                'accessibility_basis' => (string) ($diagnostics['accessibility_basis'] ?? ''),
                'warnings' => (array) ($diagnostics['warnings'] ?? []),
                'binary_sent' => false,
                'selected_as_main_photo' => $index === 0,
                'candidate_encoding_method' => 'repeated_url_fields',
                'diagnostics' => $diagnostics,
            ];
        }

        $photo = $urls[0] ?? '';
        $photoPayloadMode = $this->photo_payload_mode();
        return [
            'featured_image_id' => $featuredId,
            'gallery_image_ids' => $galleryIds,
            'image_urls' => $urls,
            'main_photo_url' => $photo,
            'photo_equals_first_photos' => $photo !== '' && $photo === ($urls[0] ?? ''),
            'all_images_accessible' => $details !== [] && count(array_filter($details, static fn(array $detail): bool => empty($detail['accessible']))) === 0,
            'image_details' => $details,
            'upload_policy' => 'preview_urls_only_no_upload',
            'photo_payload_mode' => $photoPayloadMode,
            'photo_payload_modes' => [
                'url_fields' => ['status' => 'legacy_preview_only', 'description' => 'photo plus photos[] array in preview data only; do not use for live encoding if it nests arrays.'],
                'repeated_url_fields' => ['status' => $photoPayloadMode === 'repeated_url_fields' ? 'configured_preview_candidate' : 'available_preview_candidate', 'description' => 'application/x-www-form-urlencoded repeated photos[] fields.'],
                'multipart_file_upload' => ['status' => 'preview_only_not_live', 'description' => 'Not documented for /crm/importPart; do not use live until Ovoko confirms.'],
                'local_file_path_not_allowed' => ['status' => 'not_allowed_unless_docs_change', 'description' => 'Local server paths are not public to Ovoko and are not documented as accepted.'],
            ],
        ];
    }

    private function photo_payload_mode(): string
    {
        $mode = $this->option_value(self::PHOTO_PAYLOAD_MODE_OPTION, 'repeated_url_fields');
        $allowed = ['url_fields', 'repeated_url_fields', 'multipart_file_upload', 'local_file_path_not_allowed'];
        return in_array($mode, $allowed, true) ? $mode : 'repeated_url_fields';
    }

    private function diagnose_image_url(string $url): array
    {
        $diagnostics = [
            'url' => $url,
            'valid_public_http_url' => $url !== '' && (!function_exists('wp_http_validate_url') || (bool) wp_http_validate_url($url)),
            'has_spaces_or_special_characters' => $url !== '' && preg_match('/\s|[<>"\']/', $url) === 1,
            'requires_cookies_or_auth' => false,
            'hotlink_or_user_agent_block_suspected' => false,
            'head' => ['attempted' => false],
            'get' => ['attempted' => false],
            'redirect_chain' => [],
            'server_side_checked' => false,
            'accessible' => false,
            'accessibility_basis' => '',
            'warnings' => [],
        ];
        if (!$diagnostics['valid_public_http_url']) {
            $diagnostics['accessibility_basis'] = 'invalid_public_http_url';
            return $diagnostics;
        }

        $head = $this->http_probe_image_url($url, 'HEAD');
        $get = $this->http_probe_image_url($url, 'GET');
        $diagnostics['head'] = $head;
        $diagnostics['get'] = $get;
        $diagnostics['server_side_checked'] = !empty($head['attempted']) || !empty($get['attempted']);
        $diagnostics['redirect_chain'] = array_values(array_unique(array_merge((array) ($head['redirect_chain'] ?? []), (array) ($get['redirect_chain'] ?? []))));

        $result = $this->image_probe_accessibility_result($get, $url, $diagnostics['redirect_chain']);
        $diagnostics['status_code'] = $get['status_code'] ?? null;
        $diagnostics['content_type'] = (string) ($get['content_type'] ?? '');
        $diagnostics['content_length'] = $get['content_length'] ?? null;
        $diagnostics['requires_cookies_or_auth'] = !empty($result['requires_cookies_or_auth']);
        $diagnostics['hotlink_or_user_agent_block_suspected'] = !empty($result['hotlink_or_user_agent_block_suspected']);
        $diagnostics['accessible'] = !empty($result['accessible']);
        $diagnostics['accessibility_basis'] = (string) ($result['basis'] ?? '');
        $diagnostics['warnings'] = (array) ($result['warnings'] ?? []);
        return $diagnostics;
    }

    private function http_probe_image_url(string $url, string $method): array
    {
        $probe = ['attempted' => false, 'method' => $method, 'status_code' => null, 'content_type' => '', 'content_length' => null, 'redirect_chain' => [], 'error' => 'wp_http_unavailable'];
        if (!function_exists('wp_remote_head') || !function_exists('wp_remote_get') || !function_exists('wp_remote_retrieve_response_code')) {
            return $probe;
        }
        $args = ['timeout' => 8, 'redirection' => 5, 'headers' => ['User-Agent' => 'GPSwiss-Ovoko-Image-Diagnostic/1.0']];
        $response = $method === 'HEAD' ? wp_remote_head($url, $args) : wp_remote_get($url, $args);
        $probe['attempted'] = true;
        unset($probe['error']);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $probe['error'] = method_exists($response, 'get_error_code') ? $response->get_error_code() : 'wp_error';
            return $probe;
        }
        $headers = is_array($response) ? (array) ($response['headers'] ?? []) : [];
        $probe['status_code'] = (int) wp_remote_retrieve_response_code($response);
        $probe['content_type'] = $this->header_value($headers, 'content-type');
        $length = $this->header_value($headers, 'content-length');
        $probe['content_length'] = ctype_digit($length) ? (int) $length : null;
        $location = $this->header_value($headers, 'location');
        $probe['redirect_chain'] = $location !== '' ? [$location] : [];
        return $probe;
    }

    private function header_value(array $headers, string $key): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === strtolower($key)) {
                if (is_array($value)) { $value = end($value); }
                return trim((string) $value);
            }
        }
        return '';
    }

    private function image_probe_accessibility_result(array $probe, string $url, array $redirectChain = []): array
    {
        $warnings = [];
        $status = (int) ($probe['status_code'] ?? 0);
        $contentType = strtolower(trim((string) ($probe['content_type'] ?? '')));
        $contentType = explode(';', $contentType)[0];
        $hasImageExtension = $this->url_has_known_image_extension($url);
        $requiresAuth = in_array($status, [401, 403], true) || $this->redirect_chain_suggests_auth($redirectChain);
        $hotlinkBlocked = in_array($status, [403, 406, 429], true);

        if (empty($probe['attempted'])) {
            return ['accessible' => false, 'basis' => 'get_not_attempted', 'warnings' => [], 'requires_cookies_or_auth' => $requiresAuth, 'hotlink_or_user_agent_block_suspected' => $hotlinkBlocked];
        }
        if ($requiresAuth) {
            return ['accessible' => false, 'basis' => 'auth_or_login_required', 'warnings' => [], 'requires_cookies_or_auth' => true, 'hotlink_or_user_agent_block_suspected' => $hotlinkBlocked];
        }
        if ($hotlinkBlocked) {
            return ['accessible' => false, 'basis' => 'hotlink_or_user_agent_block_suspected', 'warnings' => [], 'requires_cookies_or_auth' => false, 'hotlink_or_user_agent_block_suspected' => true];
        }
        if ($status !== 200) {
            return ['accessible' => false, 'basis' => 'get_status_not_200', 'warnings' => [], 'requires_cookies_or_auth' => false, 'hotlink_or_user_agent_block_suspected' => false];
        }
        if (($probe['content_length'] ?? null) === null) {
            $warnings[] = ['code' => 'image_content_length_missing', 'message' => 'Image URL returned HTTP 200 but no Content-Length header.'];
        }
        if ($contentType !== '') {
            if (str_starts_with($contentType, 'image/')) {
                return ['accessible' => true, 'basis' => 'get_200_image_content_type', 'warnings' => $warnings, 'requires_cookies_or_auth' => false, 'hotlink_or_user_agent_block_suspected' => false];
            }
            return ['accessible' => false, 'basis' => 'non_image_content_type', 'warnings' => $warnings, 'requires_cookies_or_auth' => false, 'hotlink_or_user_agent_block_suspected' => false];
        }
        if ($hasImageExtension) {
            array_unshift($warnings, ['code' => 'image_content_type_missing', 'message' => 'Image URL returned HTTP 200 but no image Content-Type header. Accepted based on image file extension.']);
            return ['accessible' => true, 'basis' => 'get_200_image_extension', 'warnings' => $warnings, 'requires_cookies_or_auth' => false, 'hotlink_or_user_agent_block_suspected' => false];
        }
        return ['accessible' => false, 'basis' => 'missing_content_type_and_image_extension', 'warnings' => $warnings, 'requires_cookies_or_auth' => false, 'hotlink_or_user_agent_block_suspected' => false];
    }

    private function url_has_known_image_extension(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        return preg_match('/\.(?:jpe?g|png|webp)$/', $path) === 1;
    }

    private function redirect_chain_suggests_auth(array $redirectChain): bool
    {
        foreach ($redirectChain as $redirectUrl) {
            $path = strtolower((string) (parse_url((string) $redirectUrl, PHP_URL_PATH) ?: (string) $redirectUrl));
            if (preg_match('/(?:login|log-in|signin|sign-in|auth|account|my-account|wp-login)/', $path) === 1) {
                return true;
            }
        }
        return false;
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
