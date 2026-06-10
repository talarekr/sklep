<?php

declare(strict_types=1);

namespace GPSwiss\Ovoko\Services;

class OvokoWooGmailDraftUpdateService
{
    public const GMAIL_SKU_PREFIX = 'GPS-GMAIL-';
    public const LIVE_CONFIRMATION = 'UPDATE ONE GMAIL DRAFT';

    /** @var callable|null */
    private $partFetcher;
    /** @var callable|null */
    private $titleBuilder;
    /** @var callable|null */
    private $slugBuilder;
    private ?RrrApiClient $rrrClient;

    public function __construct(?RrrApiClient $rrrClient = null, ?callable $partFetcher = null, ?callable $titleBuilder = null, ?callable $slugBuilder = null)
    {
        $this->rrrClient = $rrrClient;
        $this->partFetcher = $partFetcher;
        $this->titleBuilder = $titleBuilder;
        $this->slugBuilder = $slugBuilder;
    }

    public function preview_one(int $productId, array $options = []): array
    {
        try {
            return $this->build_plan($productId, $options + ['dry_run' => true]);
        } catch (\Throwable $throwable) {
            return $this->with_json_report($this->report_error($productId, 'ovoko_fetch_failed', [
                'blocked_reasons' => ['ovoko_fetch_failed'],
                'technical_details' => $this->throwable_details($throwable),
            ]));
        }
    }

    public function update_one(int $productId, array $options = []): array
    {
        $options += ['publish_when_ready' => true, 'confirmation' => ''];
        if ((string) $options['confirmation'] !== self::LIVE_CONFIRMATION) {
            return $this->report_error($productId, 'confirmation_required', ['required_confirmation' => self::LIVE_CONFIRMATION]);
        }

        $plan = $this->build_plan($productId, $options + ['dry_run' => false]);
        if (empty($plan['ok']) || empty($plan['would_update'])) {
            $plan['updated'] = false;
            return $plan;
        }

        $beforeImages = $this->image_snapshot($productId);
        $update = (array) ($plan['update_payload']['post'] ?? []);
        if ($update !== []) {
            $update['ID'] = $productId;
            wp_update_post($update);
        }

        $termId = (int) ($plan['update_payload']['category_term_id'] ?? 0);
        if ($termId > 0 && function_exists('wp_set_object_terms')) {
            wp_set_object_terms($productId, [$termId], 'product_cat', false);
        }

        foreach ((array) ($plan['update_payload']['meta'] ?? []) as $key => $value) {
            if (in_array((string) $key, ['_thumbnail_id', '_product_image_gallery'], true)) {
                continue;
            }
            update_post_meta($productId, (string) $key, $value);
        }

        $afterImages = $this->image_snapshot($productId);
        $imagesPreserved = $this->same_images($beforeImages, $afterImages);
        if (!$imagesPreserved) {
            update_post_meta($productId, '_thumbnail_id', $beforeImages['thumbnail_id_raw']);
            update_post_meta($productId, '_product_image_gallery', $beforeImages['gallery_raw']);
            $afterImages = $this->image_snapshot($productId);
            $imagesPreserved = $this->same_images($beforeImages, $afterImages);
        }

        $published = !empty($plan['would_publish']);
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_update_at', gmdate('c'));
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_update_status', $published ? 'updated_published' : 'updated_draft');
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_update_source_part_id', (string) ($plan['ovoko_part_id'] ?? ''));
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_was_published', $published ? '1' : '0');
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_blocked_reasons', wp_json_encode((array) ($plan['blocked_reasons'] ?? [])));
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_images_preserved', $imagesPreserved ? '1' : '0');

        $plan['updated'] = true;
        $plan['published'] = $published;
        $plan['images_preserved'] = $imagesPreserved;
        $plan['thumbnail_id_after'] = $afterImages['thumbnail_id'];
        $plan['gallery_after_count'] = $afterImages['gallery_count'];
        $plan['csv'] = $this->build_csv([$plan]);
        return $plan;
    }

    public function preview_eligible(int $batchSize = 10, array $options = []): array
    {
        $batchSize = max(1, min(100, $batchSize));
        $ids = $this->find_eligible_product_ids($batchSize);
        $examples = [];
        $summary = [
            'ok' => true,
            'action_name' => 'Ovoko → Woo Gmail draft update preview',
            'mode' => 'preview_eligible_gmail_products',
            'total_gmail_products_with_ovoko_part_id' => count($ids),
            'total_ready_to_update' => 0,
            'total_not_ready' => 0,
            'total_would_publish' => 0,
            'total_would_remain_draft' => 0,
            'total_missing_price' => 0,
            'total_missing_category' => 0,
            'total_missing_title' => 0,
            'total_missing_description' => 0,
            'total_missing_existing_woo_images' => 0,
            'total_ovoko_fetch_failed' => 0,
            'examples' => [],
        ];

        foreach ($ids as $id) {
            try {
                $plan = $this->preview_one((int) $id, $options);
            } catch (\Throwable $throwable) {
                $plan = $this->with_json_report($this->report_error((int) $id, 'ovoko_fetch_failed', [
                    'blocked_reasons' => ['ovoko_fetch_failed'],
                    'technical_details' => $this->throwable_details($throwable),
                ]));
            }
            if (!empty($plan['ready_for_sale'])) {
                $summary['total_ready_to_update']++;
            } else {
                $summary['total_not_ready']++;
            }
            if (!empty($plan['would_publish'])) {
                $summary['total_would_publish']++;
            } else {
                $summary['total_would_remain_draft']++;
            }
            $blocked = (array) ($plan['blocked_reasons'] ?? []);
            foreach (['missing_price','missing_category','missing_title','missing_description','missing_existing_woo_images','ovoko_fetch_failed'] as $reason) {
                if (in_array($reason, $blocked, true)) {
                    $summary['total_' . $reason]++;
                }
            }
            $examples[] = $this->compact_report_row($plan);
        }

        $summary['examples'] = array_slice($examples, 0, $batchSize);
        $summary['json_report'] = wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $summary['csv'] = $this->build_csv($examples);
        return $summary;
    }

    public function build_csv(array $reports): string
    {
        $columns = ['product_id','sku','ovoko_part_id','current_status','ready_for_sale','would_update','would_publish','updated','published','blocked_reasons','price_before','price_after','title_before','title_after','category_before','category_after','thumbnail_id_before','thumbnail_id_after','gallery_before_count','gallery_after_count','images_preserved','error'];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $columns);
        foreach ($reports as $report) {
            $row = $this->compact_report_row((array) $report);
            fputcsv($fh, array_map(static fn(string $column) => $row[$column] ?? '', $columns));
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }

    private function build_plan(int $productId, array $options): array
    {
        $publishWhenReady = !array_key_exists('publish_when_ready', $options) || !empty($options['publish_when_ready']);
        $post = get_post($productId);
        if (!$post || (string) ($post->post_type ?? '') !== 'product') {
            return $this->report_error($productId, 'product_not_found');
        }

        $sku = (string) get_post_meta($productId, '_sku', true);
        $partId = $this->get_part_id($productId);
        $currentImages = $this->image_snapshot($productId);
        $currentTerms = $this->category_snapshot($productId);
        $base = [
            'ok' => true,
            'action_name' => 'Ovoko → Woo Gmail draft update',
            'product_id' => $productId,
            'sku' => $sku,
            'ovoko_part_id' => $partId,
            'current_status' => (string) $post->post_status,
            'would_update' => false,
            'would_publish' => false,
            'ready_for_sale' => false,
            'blocked_reasons' => [],
            'images_preserved' => true,
            'thumbnail_id_before' => $currentImages['thumbnail_id'],
            'thumbnail_id_after' => $currentImages['thumbnail_id'],
            'gallery_before_count' => $currentImages['gallery_count'],
            'gallery_after_count' => $currentImages['gallery_count'],
            'diff' => [],
            'updated' => false,
            'published' => false,
            'error' => '',
        ];

        if (!$this->is_gmail_sku($sku)) {
            $base['ok'] = false;
            $base['blocked_reasons'][] = 'non_gmail_sku';
            $base['error'] = 'skipped_non_gmail_sku';
            return $base;
        }
        if ($partId === '') {
            $base['ok'] = false;
            $base['blocked_reasons'][] = 'missing_part_id';
            $base['error'] = 'skipped_missing_part_id';
            return $base;
        }

        $fetch = $this->fetch_part($partId);
        if (empty($fetch['ok'])) {
            $base['ok'] = false;
            $base['blocked_reasons'][] = 'ovoko_fetch_failed';
            $base['error'] = (string) ($fetch['error'] ?? 'ovoko_fetch_failed');
            $base['ovoko_fetch_ok'] = false;
            $base['technical_details'] = (array) ($fetch['technical_details'] ?? ['error' => $base['error']]);
            return $this->with_json_report($base);
        }

        $part = (array) ($fetch['part'] ?? []);
        $mappedCategory = $this->map_category((string) ($part['category_id'] ?? ''), (string) ($part['category_title_path'] ?? ''));
        $titlePreview = $this->build_title_preview($part);
        $title = (string) ($titlePreview['proposed_title'] ?? '');
        $description = $this->first_text($part, ['description','notes','comment','comments']);
        $shortDescription = $this->short_description($part);
        $price = $this->normalize_price($part['woo_target_price'] ?? $part['price'] ?? $part['ovoko_price'] ?? $part['original_price'] ?? null);
        $blocked = [];
        if ($price === '') $blocked[] = 'missing_price';
        if ((string) ($part['category_id'] ?? '') === '') $blocked[] = 'missing_category';
        if ((string) ($part['category_id'] ?? '') !== '' && $mappedCategory['term_id'] <= 0) $blocked[] = 'category_mapping_failed';
        if ($title === '') $blocked[] = 'missing_title';
        if ($description === '' && $shortDescription === '') $blocked[] = 'missing_description';
        if ($currentImages['image_count'] <= 0) $blocked[] = 'missing_existing_woo_images';

        $ready = $blocked === [];
        $statusAfter = ($publishWhenReady && $ready) ? 'publish' : 'draft';
        $meta = $this->build_meta_payload($partId, $part, $price, $mappedCategory);
        $slugPreview = $title !== '' ? $this->build_slug_preview($title, $productId, $statusAfter) : ['slug' => '', 'slug_builder' => 'none'];
        $postPayload = [
            'post_title' => $title !== '' ? $title : (string) $post->post_title,
            'post_content' => $description,
            'post_excerpt' => $shortDescription,
            'post_status' => $statusAfter,
        ];
        if ((string) ($slugPreview['slug'] ?? '') !== '') {
            $postPayload['post_name'] = (string) $slugPreview['slug'];
        }

        $base['would_update'] = true;
        $base['would_publish'] = $publishWhenReady && $ready;
        $base['ready_for_sale'] = $ready;
        $base['blocked_reasons'] = $blocked;
        $base['ovoko_fetch_ok'] = true;
        $base['ovoko_category_id'] = (string) ($part['category_id'] ?? '');
        $base['ovoko_category_title_path'] = (string) ($part['category_title_path'] ?? '');
        $base['category_mapping'] = $mappedCategory;
        $base['category_mapping_attempts'] = (array) ($mappedCategory['category_mapping_attempts'] ?? []);
        $base['matched_by'] = (string) ($mappedCategory['matched_by'] ?? 'none');
        $base['candidate_terms'] = (array) ($mappedCategory['candidate_terms'] ?? []);
        $base['category_mapping_source'] = (string) ($mappedCategory['category_mapping_source'] ?? 'none');
        $base['title_builder_preview'] = $titlePreview;
        $base['title_builder_source'] = (string) ($titlePreview['_ovoko_title_source'] ?? ($titlePreview['source'] ?? ''));
        $base['title_builder_used_vehicle_data'] = !empty($titlePreview['title_builder_used_vehicle_data']);
        $base['slug_builder_preview'] = $slugPreview;
        $base['slug_builder_source'] = (string) ($slugPreview['slug_source'] ?? ($slugPreview['slug_builder'] ?? ''));
        $base['slug_before'] = (string) ($post->post_name ?? '');
        $base['slug_after'] = (string) ($postPayload['post_name'] ?? '');
        $base['url_before'] = $this->product_url_preview($productId, $base['slug_before']);
        $base['url_after'] = $this->product_url_preview($productId, $base['slug_after']);
        $base['planned_permalink_path'] = $this->product_permalink_path($base['url_after']);
        $base['update_payload'] = ['post' => $postPayload, 'meta' => $meta, 'category_term_id' => $mappedCategory['term_id']];
        $base['title_before'] = (string) $post->post_title;
        $base['title_after'] = (string) $postPayload['post_title'];
        $base['price_before'] = (string) get_post_meta($productId, '_price', true);
        $base['price_after'] = $price;
        $base['category_before'] = $currentTerms['label'];
        $base['category_after'] = (string) $mappedCategory['label'];
        $base['description_before'] = $this->summarize((string) $post->post_content);
        $base['description_after'] = $this->summarize($description);
        $base['diff'] = [
            'title' => ['before' => $base['title_before'], 'after' => $base['title_after']],
            'slug' => ['before' => $base['slug_before'], 'after' => $base['slug_after']],
            'url' => ['before' => $base['url_before'], 'after' => $base['url_after']],
            'price' => ['before' => $base['price_before'], 'after' => $base['price_after']],
            'category' => ['before' => $base['category_before'], 'after' => $base['category_after']],
            'status' => ['before' => (string) $post->post_status, 'after' => $statusAfter],
            'description' => ['before' => $base['description_before'], 'after' => $base['description_after']],
            'short_description' => ['before' => $this->summarize((string) ($post->post_excerpt ?? '')), 'after' => $this->summarize($shortDescription)],
            'key_meta' => $this->meta_diff($productId, $meta),
        ];
        $base['json_report'] = wp_json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $base['csv'] = $this->build_csv([$base]);
        return $base;
    }

    private function fetch_part(string $partId): array
    {
        try {
            if ($this->partFetcher !== null) {
                $result = ($this->partFetcher)($partId);
                if (isset($result['part']) && is_array($result['part'])) return ['ok' => !empty($result['ok']), 'part' => $result['part'], 'error' => (string) ($result['error'] ?? '')];
                if (isset($result['payload']) && is_array($result['payload'])) return $this->normalize_fetch_result($result);
                return ['ok' => !empty($result['ok']), 'part' => is_array($result) ? $result : [], 'error' => ''];
            }

            if ($this->rrrClient === null) {
                return ['ok' => false, 'part' => [], 'error' => 'rrr_api_client_not_configured', 'technical_details' => ['message' => 'RrrApiClient was not injected with plugin settings.']];
            }

            return $this->normalize_fetch_result($this->rrrClient->preview_fetch_single_part((int) $partId), $this->rrrClient);
        } catch (\Throwable $throwable) {
            return ['ok' => false, 'part' => [], 'error' => 'ovoko_fetch_failed', 'technical_details' => $this->throwable_details($throwable)];
        }
    }

    private function normalize_fetch_result(array $result, ?RrrApiClient $client = null): array
    {
        if (empty($result['ok']) && empty($result['success'])) {
            return [
                'ok' => false,
                'part' => [],
                'error' => (string) ($result['message'] ?? $result['error'] ?? 'ovoko_fetch_failed'),
                'technical_details' => $this->fetch_diagnostics($result),
            ];
        }

        $client = $client ?? $this->rrrClient;
        if ($client === null) {
            return ['ok' => false, 'part' => [], 'error' => 'rrr_api_client_not_configured', 'technical_details' => ['message' => 'RrrApiClient was not injected with plugin settings.']];
        }

        try {
            $part = $client->normalize_rrr_single_part_payload((array) ($result['payload'] ?? []), ['details_only' => false]);
        } catch (\Throwable $throwable) {
            return ['ok' => false, 'part' => [], 'error' => 'ovoko_normalize_failed', 'technical_details' => $this->throwable_details($throwable)];
        }

        return ['ok' => $part !== [], 'part' => $part, 'error' => $part === [] ? 'ovoko_normalize_failed' : '', 'technical_details' => $part === [] ? $this->fetch_diagnostics($result) : []];
    }

    private function build_title_preview(array $part): array
    {
        if ($this->titleBuilder !== null) {
            $preview = ($this->titleBuilder)($part, $this->rrrClient);
            if (is_array($preview)) {
                $title = trim((string) ($preview['proposed_title'] ?? ''));
                if ($title !== '') {
                    return $preview + ['title_builder' => 'injected_existing_ovoko_to_woo_builder'];
                }
            }
        }

        $title = $this->first_text($part, ['title','name']);
        return [
            'proposed_title' => $title,
            'title_builder' => 'raw_title_fallback',
            '_ovoko_title_source' => 'raw_title_fallback',
            '_ovoko_title_review_required' => 'yes',
            'title_builder_used_vehicle_data' => false,
        ];
    }

    private function build_slug_preview(string $title, int $productId, string $postStatus): array
    {
        if ($this->slugBuilder !== null) {
            $preview = ($this->slugBuilder)($title, $productId, $postStatus);
            if (is_array($preview) && trim((string) ($preview['slug'] ?? '')) !== '') {
                return $preview + ['slug_builder' => 'injected_existing_ovoko_to_woo_slug_builder'];
            }
        }

        $slug = function_exists('sanitize_title') ? sanitize_title($title) : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
        return [
            'slug' => $slug,
            'slug_base' => $slug,
            'slug_builder' => 'wordpress_title_slug_fallback',
            'slug_source' => 'wordpress_post_slug_from_generated_title_fallback',
        ];
    }

    private function product_url_preview(int $productId, string $slug): string
    {
        $slug = trim($slug);
        $currentUrl = function_exists('get_permalink') ? (string) get_permalink($productId) : '';
        if ($slug === '') {
            return $currentUrl;
        }

        $currentPath = $currentUrl !== '' ? (string) (parse_url($currentUrl, PHP_URL_PATH) ?: '') : '';
        if ($currentPath !== '') {
            $hasTrailingSlash = str_ends_with($currentPath, '/');
            $segments = array_values(array_filter(explode('/', trim($currentPath, '/')), static fn(string $segment): bool => $segment !== ''));
            if ($segments !== []) {
                $segments[count($segments) - 1] = $slug;
                $path = '/' . implode('/', $segments) . ($hasTrailingSlash ? '/' : '');
                return function_exists('home_url') ? (string) home_url($path) : $path;
            }
        }

        if (function_exists('home_url')) {
            return (string) home_url('/produkt/' . $slug . '/');
        }

        return '/produkt/' . $slug . '/';
    }

    private function product_permalink_path(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        return $path !== '' ? $path : $url;
    }

    private function build_meta_payload(string $partId, array $part, string $price, array $category): array
    {
        $json = static fn($value): string => wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $meta = [
            '_price' => $price,
            '_regular_price' => $price,
            '_ovoko_part_id' => $partId,
            'ovoko_part_id' => $partId,
            '_ovoko_status' => (string) ($part['status'] ?? ''),
            '_ovoko_updated_at' => gmdate('c'),
            '_ovoko_category_id' => (string) ($part['category_id'] ?? ''),
            '_ovoko_category' => (string) ($part['category_title_path'] ?? ''),
            '_ovoko_vehicle_make' => (string) ($part['make'] ?? $part['vehicle_make'] ?? ''),
            '_ovoko_vehicle_model' => (string) ($part['model'] ?? $part['vehicle_model'] ?? ''),
            '_ovoko_vehicle_version' => (string) ($part['version'] ?? $part['vehicle_generation'] ?? ''),
            '_ovoko_engine_code' => (string) ($part['engine'] ?? $part['engine_code'] ?? ''),
            '_ovoko_year' => (string) ($part['year'] ?? ''),
            '_ovoko_car_id' => (string) ($part['car_id'] ?? ''),
            '_ovoko_manufacturer_code' => (string) ($part['manufacturer_code'] ?? ''),
            '_ovoko_visible_code' => (string) ($part['visible_code'] ?? ''),
            '_ovoko_oe_numbers' => $json($part['oe_numbers'] ?? $part['oem_numbers'] ?? []),
            '_ovoko_technical_parameters' => $json($part['parameters'] ?? $part['technical_parameters'] ?? []),
            '_ovoko_raw_payload' => $json($part),
            '_gps_ovoko_to_woo_gmail_category_term_id' => $category['term_id'] > 0 ? (string) $category['term_id'] : '',
            '_gps_ovoko_to_woo_gmail_status' => (string) ($part['status'] ?? ''),
        ];
        foreach (['_weight' => ['weight'], '_length' => ['length'], '_width' => ['width'], '_height' => ['height']] as $metaKey => $keys) {
            $value = $this->first_text($part, $keys);
            if ($value !== '') $meta[$metaKey] = $value;
        }
        return $meta;
    }

    private function find_eligible_product_ids(int $limit): array
    {
        if (!function_exists('get_posts')) return [];
        $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => max(100, $limit * 5), 'fields' => 'ids']);
        $eligible = [];
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($this->is_gmail_sku((string) get_post_meta($id, '_sku', true)) && $this->get_part_id($id) !== '') {
                $eligible[] = $id;
                if (count($eligible) >= $limit) break;
            }
        }
        return $eligible;
    }

    private function map_category(string $ovokoCategoryId, string $categoryTitlePath = ''): array
    {
        $ovokoCategoryId = trim($ovokoCategoryId);
        $categoryTitlePath = trim($categoryTitlePath);
        $base = [
            'term_id' => 0,
            'label' => '',
            'ovoko_category_id' => $ovokoCategoryId,
            'ovoko_category_title_path' => $categoryTitlePath,
            'matched_by' => 'none',
            'category_mapping_source' => 'none',
            'category_mapping_attempts' => [],
            'candidate_terms' => [],
        ];
        if ($ovokoCategoryId === '' || !function_exists('get_terms')) {
            $base['category_mapping_attempts'][] = ['method' => 'none', 'ok' => false, 'reason' => $ovokoCategoryId === '' ? 'missing_ovoko_category_id' : 'get_terms_unavailable'];
            return $base;
        }

        $mappingTable = $this->lookup_category_mapping_table($ovokoCategoryId);
        $base['category_mapping_attempts'][] = ['method' => 'existing_mapping_table', 'ok' => $mappingTable['term_id'] > 0, 'source' => $mappingTable['source'], 'term_id' => $mappingTable['term_id']];
        if ($mappingTable['term_id'] > 0) {
            return $this->mapped_category_result($base, $mappingTable['term_id'], $mappingTable['label'], 'existing mapping table', $mappingTable['source']);
        }

        foreach (['_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id', '_rrr_category_id', 'rrr_category_id'] as $metaKey) {
            $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => $metaKey, 'meta_value' => $ovokoCategoryId, 'number' => 1]);
            $ok = !is_wp_error($terms) && !empty($terms[0]);
            $base['category_mapping_attempts'][] = ['method' => 'ovoko_category_id meta', 'ok' => $ok, 'meta_key' => $metaKey, 'term_id' => $ok ? (int) $terms[0]->term_id : 0];
            if ($ok) {
                $term = $terms[0];
                return $this->mapped_category_result($base, (int) $term->term_id, (string) $term->name, 'ovoko_category_id meta', 'term_meta:' . $metaKey);
            }
        }

        $terms = $this->all_product_category_terms();
        $pathCandidates = $this->category_path_candidates($categoryTitlePath);
        $base['category_mapping_attempts'][] = ['method' => 'exact name/path', 'ok' => false, 'candidates' => $pathCandidates];
        foreach ($pathCandidates as $candidate) {
            foreach ($terms as $term) {
                $termPath = $this->term_path((int) $term->term_id, $terms);
                if ($candidate !== '' && ($termPath === $candidate || (string) $term->name === $candidate)) {
                    $base['category_mapping_attempts'][count($base['category_mapping_attempts']) - 1]['ok'] = true;
                    $base['candidate_terms'] = $this->candidate_terms($terms, $pathCandidates, $term, 'exact path/name match');
                    return $this->mapped_category_result($base, (int) $term->term_id, (string) $term->name, 'exact name/path', 'product_cat_path_exact');
                }
            }
        }

        $normalizedCandidates = array_values(array_unique(array_filter(array_map([$this, 'normalize_category_text'], $pathCandidates))));
        $base['category_mapping_attempts'][] = ['method' => 'normalized name/path', 'ok' => false, 'candidates' => $normalizedCandidates];
        foreach ($normalizedCandidates as $candidate) {
            foreach ($terms as $term) {
                $termPath = $this->term_path((int) $term->term_id, $terms);
                $normalizedTermValues = array_values(array_unique(array_filter([
                    $this->normalize_category_text($termPath),
                    $this->normalize_category_text((string) $term->name),
                ])));
                if ($candidate !== '' && in_array($candidate, $normalizedTermValues, true)) {
                    $base['category_mapping_attempts'][count($base['category_mapping_attempts']) - 1]['ok'] = true;
                    $base['candidate_terms'] = $this->candidate_terms($terms, $pathCandidates, $term, 'normalized path/name match');
                    return $this->mapped_category_result($base, (int) $term->term_id, (string) $term->name, 'normalized name/path', 'product_cat_path_normalized');
                }
            }
        }

        $base['candidate_terms'] = $this->candidate_terms($terms, $pathCandidates, null, 'no exact or normalized match');
        $base['category_mapping_attempts'][] = ['method' => 'none', 'ok' => false, 'reason' => 'no_existing_product_cat_match'];
        return $base;
    }

    private function lookup_category_mapping_table(string $ovokoCategoryId): array
    {
        if (!function_exists('get_option')) return ['term_id' => 0, 'label' => '', 'source' => 'get_option_unavailable'];
        $settings = (array) get_option('gpswiss_ovoko_settings', []);
        $tables = [
            'gpswiss_ovoko_settings.ovoko_to_woo_category_map' => (array) ($settings['ovoko_to_woo_category_map'] ?? []),
            'gpswiss_ovoko_settings.ovoko_category_to_woo_term_map' => (array) ($settings['ovoko_category_to_woo_term_map'] ?? []),
            'gpswiss_ovoko_category_map' => (array) get_option('gpswiss_ovoko_category_map', []),
            'gpswiss_ovoko_category_mapping' => (array) get_option('gpswiss_ovoko_category_mapping', []),
        ];
        foreach ($tables as $source => $table) {
            if ($table === []) continue;
            $value = $table[$ovokoCategoryId] ?? $table[(int) $ovokoCategoryId] ?? null;
            $termId = is_array($value) ? (int) ($value['term_id'] ?? $value['woo_term_id'] ?? 0) : (int) $value;
            if ($termId > 0) {
                return ['term_id' => $termId, 'label' => $this->term_label($termId), 'source' => $source];
            }
        }
        return ['term_id' => 0, 'label' => '', 'source' => 'not_configured'];
    }

    private function mapped_category_result(array $base, int $termId, string $label, string $matchedBy, string $source): array
    {
        $base['term_id'] = $termId;
        $base['label'] = $label !== '' ? $label : $this->term_label($termId);
        $base['matched_by'] = $matchedBy;
        $base['category_mapping_source'] = $source;
        if ($base['candidate_terms'] === []) {
            $terms = $this->all_product_category_terms();
            $matchedTerm = null;
            foreach ($terms as $term) {
                if ((int) $term->term_id === $termId) { $matchedTerm = $term; break; }
            }
            $base['candidate_terms'] = $this->candidate_terms($terms, $this->category_path_candidates((string) $base['ovoko_category_title_path']), $matchedTerm, $matchedBy);
        }
        return $base;
    }

    private function all_product_category_terms(): array
    {
        if (!function_exists('get_terms')) return [];
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 0]);
        return is_wp_error($terms) ? [] : array_values((array) $terms);
    }

    private function category_path_candidates(string $path): array
    {
        $path = trim($path);
        $segments = array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|\/|»|→)\s*/u', $path) ?: []), static fn($segment) => $segment !== ''));
        $candidates = [$path];
        if (count($segments) > 1) $candidates[] = implode(' / ', array_slice($segments, 1));
        if ($segments !== []) {
            $candidates[] = (string) end($segments);
            $candidates[] = (string) reset($segments);
        }
        return array_values(array_unique(array_filter(array_map('trim', $candidates), static fn($candidate) => $candidate !== '')));
    }

    private function normalize_category_text(string $value): string
    {
        $value = mb_strtolower(trim(wp_strip_all_tags($value)));
        $value = preg_replace('/\s*(?:>|\/|»|→)\s*/u', ' / ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function term_path(int $termId, array $terms): string
    {
        $byId = [];
        foreach ($terms as $term) $byId[(int) $term->term_id] = $term;
        if (!isset($byId[$termId])) return $this->term_label($termId);
        $segments = [];
        $current = $byId[$termId];
        $guard = 0;
        while ($current && $guard < 20) {
            array_unshift($segments, (string) $current->name);
            $parent = (int) ($current->parent ?? 0);
            $current = $parent > 0 && isset($byId[$parent]) ? $byId[$parent] : null;
            $guard++;
        }
        return implode(' / ', $segments);
    }

    private function term_label(int $termId): string
    {
        if ($termId <= 0 || !function_exists('get_term')) return '';
        $term = get_term($termId, 'product_cat');
        return ($term && !is_wp_error($term)) ? (string) $term->name : '';
    }

    private function candidate_terms(array $terms, array $pathCandidates, ?object $matchedTerm, string $reason): array
    {
        $rows = [];
        $normalizedCandidates = array_values(array_unique(array_filter(array_map([$this, 'normalize_category_text'], $pathCandidates))));
        foreach ($terms as $term) {
            $termId = (int) ($term->term_id ?? 0);
            if ($termId <= 0) continue;
            $termPath = $this->term_path($termId, $terms);
            $score = 0;
            $rowReason = 'candidate';
            if ($matchedTerm !== null && $termId === (int) $matchedTerm->term_id) {
                $score = 100;
                $rowReason = $reason;
            } elseif (in_array($termPath, $pathCandidates, true) || in_array((string) $term->name, $pathCandidates, true)) {
                $score = 90;
                $rowReason = 'exact candidate';
            } elseif (in_array($this->normalize_category_text($termPath), $normalizedCandidates, true) || in_array($this->normalize_category_text((string) $term->name), $normalizedCandidates, true)) {
                $score = 80;
                $rowReason = 'normalized candidate';
            }
            $rows[] = ['term_id' => $termId, 'name' => (string) ($term->name ?? ''), 'slug' => (string) ($term->slug ?? ''), 'parent' => (int) ($term->parent ?? 0), 'matched_score' => $score, 'reason' => $rowReason];
        }
        usort($rows, static fn(array $a, array $b): int => ($b['matched_score'] <=> $a['matched_score']) ?: ($a['term_id'] <=> $b['term_id']));
        return array_slice($rows, 0, 10);
    }

    private function get_part_id(int $productId): string
    {
        foreach (['_ovoko_part_id', 'ovoko_part_id'] as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function is_gmail_sku(string $sku): bool
    {
        return str_starts_with($sku, self::GMAIL_SKU_PREFIX);
    }

    private function image_snapshot(int $productId): array
    {
        $thumbnailRaw = (string) get_post_meta($productId, '_thumbnail_id', true);
        $galleryRaw = (string) get_post_meta($productId, '_product_image_gallery', true);
        $galleryIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $galleryRaw)))));
        $thumb = (int) $thumbnailRaw;
        return ['thumbnail_id_raw' => $thumbnailRaw, 'gallery_raw' => $galleryRaw, 'thumbnail_id' => $thumb, 'gallery_ids' => $galleryIds, 'gallery_count' => count($galleryIds), 'image_count' => ($thumb > 0 ? 1 : 0) + count($galleryIds)];
    }

    private function same_images(array $before, array $after): bool
    {
        return (string) $before['thumbnail_id_raw'] === (string) $after['thumbnail_id_raw'] && (string) $before['gallery_raw'] === (string) $after['gallery_raw'];
    }

    private function category_snapshot(int $productId): array
    {
        if (!function_exists('wp_get_post_terms')) return ['term_ids' => [], 'label' => ''];
        $terms = wp_get_post_terms($productId, 'product_cat');
        $labels = [];
        $ids = [];
        if (!is_wp_error($terms)) {
            foreach ((array) $terms as $term) {
                $ids[] = (int) $term->term_id;
                $labels[] = (string) $term->name;
            }
        }
        return ['term_ids' => $ids, 'label' => implode(', ', $labels)];
    }

    private function first_text(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_scalar($source[$key]) && trim((string) $source[$key]) !== '') {
                return trim((string) $source[$key]);
            }
        }
        return '';
    }

    private function short_description(array $part): string
    {
        $pieces = array_filter([$this->first_text($part, ['category_title_path']), $this->first_text($part, ['manufacturer_code']), $this->first_text($part, ['visible_code'])]);
        return implode(' | ', $pieces);
    }

    private function normalize_price(mixed $value): string
    {
        if (!is_numeric($value)) return '';
        $number = (float) $value;
        return $number > 0 ? rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') : '';
    }

    private function summarize(string $text): string
    {
        $text = trim(wp_strip_all_tags($text));
        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '...' : $text;
    }

    private function meta_diff(int $productId, array $meta): array
    {
        $diff = [];
        foreach (['_price','_regular_price','_ovoko_part_id','_ovoko_category_id','_ovoko_vehicle_make','_ovoko_vehicle_model','_ovoko_manufacturer_code','_ovoko_visible_code'] as $key) {
            $old = (string) get_post_meta($productId, $key, true);
            $new = (string) ($meta[$key] ?? '');
            if ($old !== $new) $diff[$key] = ['before' => $old, 'after' => $new];
        }
        return $diff;
    }

    private function compact_report_row(array $report): array
    {
        $diff = (array) ($report['diff'] ?? []);
        return [
            'product_id' => (string) ($report['product_id'] ?? ''),
            'sku' => (string) ($report['sku'] ?? ''),
            'ovoko_part_id' => (string) ($report['ovoko_part_id'] ?? ''),
            'current_status' => (string) ($report['current_status'] ?? ''),
            'ready_for_sale' => !empty($report['ready_for_sale']) ? '1' : '0',
            'would_update' => !empty($report['would_update']) ? '1' : '0',
            'would_publish' => !empty($report['would_publish']) ? '1' : '0',
            'updated' => !empty($report['updated']) ? '1' : '0',
            'published' => !empty($report['published']) ? '1' : '0',
            'blocked_reasons' => implode('|', (array) ($report['blocked_reasons'] ?? [])),
            'price_before' => (string) ($diff['price']['before'] ?? ''),
            'price_after' => (string) ($diff['price']['after'] ?? ''),
            'title_before' => (string) ($diff['title']['before'] ?? ''),
            'title_after' => (string) ($diff['title']['after'] ?? ''),
            'category_before' => (string) ($diff['category']['before'] ?? ''),
            'category_after' => (string) ($diff['category']['after'] ?? ''),
            'thumbnail_id_before' => (string) ($report['thumbnail_id_before'] ?? ''),
            'thumbnail_id_after' => (string) ($report['thumbnail_id_after'] ?? ''),
            'gallery_before_count' => (string) ($report['gallery_before_count'] ?? ''),
            'gallery_after_count' => (string) ($report['gallery_after_count'] ?? ''),
            'images_preserved' => !array_key_exists('images_preserved', $report) || !empty($report['images_preserved']) ? '1' : '0',
            'error' => (string) ($report['error'] ?? ''),
        ];
    }

    private function report_error(int $productId, string $error, array $extra = []): array
    {
        return $extra + ['ok' => false, 'product_id' => $productId, 'would_update' => false, 'would_publish' => false, 'ready_for_sale' => false, 'blocked_reasons' => [$error], 'images_preserved' => true, 'error' => $error, 'updated' => false, 'published' => false];
    }

    private function with_json_report(array $report): array
    {
        $report['json_report'] = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $report['csv'] = $this->build_csv([$report]);
        return $report;
    }

    private function fetch_diagnostics(array $result): array
    {
        return [
            'http_code' => $result['http_code'] ?? null,
            'status_code' => (string) ($result['status_code'] ?? ''),
            'message' => (string) ($result['message'] ?? $result['msg'] ?? $result['error'] ?? 'ovoko_fetch_failed'),
            'executed' => !empty($result['executed']),
        ];
    }

    private function throwable_details(\Throwable $throwable): array
    {
        return [
            'message' => $throwable->getMessage(),
            'type' => get_class($throwable),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ];
    }
}
