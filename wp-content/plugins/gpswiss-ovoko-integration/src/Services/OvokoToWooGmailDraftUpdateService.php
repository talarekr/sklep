<?php

namespace GPSwiss\Ovoko\Services;

class OvokoToWooGmailDraftUpdateService
{
    private array $settings;
    /** @var callable|null */
    private $partFetcher;

    public function __construct(array $settings = [], ?callable $partFetcher = null)
    {
        $this->settings = $settings;
        $this->partFetcher = $partFetcher;
    }

    public function preview_one(int $productId, array $options = []): array
    {
        $options = $this->normalize_options($options);
        return $this->build_plan($productId, $options, false);
    }

    public function update_one(int $productId, array $options = []): array
    {
        $options = $this->normalize_options($options);
        $options['dry_run'] = false;
        $plan = $this->build_plan($productId, $options, true);
        if (empty($plan['ok']) || empty($plan['would_update'])) {
            $this->write_live_audit_meta($productId, $plan, false, false);
            return $plan + ['updated' => false, 'published' => false];
        }

        $imageBefore = $this->capture_image_state($productId);
        $target = (array) ($plan['target'] ?? []);
        $postUpdate = ['ID' => $productId];
        foreach (['post_title' => 'title', 'post_name' => 'slug', 'post_content' => 'description', 'post_excerpt' => 'short_description', 'post_status' => 'status_after'] as $postKey => $targetKey) {
            if (isset($target[$targetKey])) {
                $postUpdate[$postKey] = (string) $target[$targetKey];
            }
        }
        if (count($postUpdate) > 1 && function_exists('wp_update_post')) {
            $updatedPostId = wp_update_post($postUpdate, true);
            if ($this->is_wp_error($updatedPostId)) {
                $plan['ok'] = false;
                $plan['error'] = $this->wp_error_message($updatedPostId);
                $this->restore_image_state($productId, $imageBefore);
                $this->write_live_audit_meta($productId, $plan, false, false);
                return $plan + ['updated' => false, 'published' => false];
            }
        }

        if (($target['price'] ?? '') !== '') {
            update_post_meta($productId, '_regular_price', (string) $target['price']);
            update_post_meta($productId, '_price', (string) $target['price']);
        }
        $termId = (int) ($target['category_term_id'] ?? 0);
        if ($termId > 0 && function_exists('wp_set_object_terms')) {
            wp_set_object_terms($productId, [$termId], 'product_cat', false);
            if (function_exists('update_post_meta')) {
                update_post_meta($productId, '_yoast_wpseo_primary_product_cat', (string) $termId);
            }
        }
        foreach ((array) ($target['meta'] ?? []) as $key => $value) {
            update_post_meta($productId, (string) $key, is_array($value) ? $this->json_encode($value) : (string) $value);
        }
        $this->upsert_custom_product_attributes($productId, (array) ($target['attributes'] ?? []));

        $this->restore_image_state($productId, $imageBefore);
        $imageAfter = $this->capture_image_state($productId);
        $imagesPreserved = $this->same_image_state($imageBefore, $imageAfter);
        if (!$imagesPreserved) {
            $this->restore_image_state($productId, $imageBefore);
            $imageAfter = $this->capture_image_state($productId);
            $imagesPreserved = $this->same_image_state($imageBefore, $imageAfter);
        }

        $published = (string) ($target['status_after'] ?? '') === 'publish' && (string) ($plan['current_status'] ?? '') !== 'publish';
        $plan['updated'] = true;
        $plan['published'] = $published;
        $plan['images_preserved'] = $imagesPreserved;
        $plan['thumbnail_id_after'] = $imageAfter['thumbnail_id'];
        $plan['gallery_after_count'] = $imageAfter['gallery_count'];
        $this->write_live_audit_meta($productId, $plan, true, $published);
        return $plan;
    }

    public function preview_eligible(int $batchSize = 10, array $options = []): array
    {
        $batchSize = max(1, min(100, $batchSize));
        $ids = $this->find_eligible_product_ids($batchSize);
        $summary = [
            'ok' => true,
            'action_name' => 'Ovoko → Woo Gmail draft update preview eligible Gmail products',
            'dry_run' => true,
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
            'examples' => [],
            'csv' => '',
        ];
        foreach ($ids as $id) {
            $row = $this->preview_one((int) $id, $options);
            if (!empty($row['ready_for_sale'])) { $summary['total_ready_to_update']++; } else { $summary['total_not_ready']++; }
            if (!empty($row['would_publish'])) { $summary['total_would_publish']++; } else { $summary['total_would_remain_draft']++; }
            $reasons = (array) ($row['blocked_reasons'] ?? []);
            foreach (['missing_price','missing_category','missing_title','missing_description','missing_existing_woo_images'] as $reason) {
                if (in_array($reason, $reasons, true)) { $summary['total_' . $reason]++; }
            }
            $summary['examples'][] = $this->report_row($row);
        }
        $summary['csv'] = $this->build_csv($summary['examples']);
        return $summary;
    }

    public function find_eligible_product_ids(int $limit = 10): array
    {
        if (!function_exists('get_posts')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_sku', 'value' => 'GPS-GMAIL-', 'compare' => 'LIKE'],
                ['relation' => 'OR', ['key' => '_ovoko_part_id', 'compare' => 'EXISTS'], ['key' => 'ovoko_part_id', 'compare' => 'EXISTS']],
            ],
        ]);
        $eligible = [];
        foreach ((array) $ids as $id) {
            if ($this->is_gmail_product((int) $id) && $this->get_part_id((int) $id) !== '') {
                $eligible[] = (int) $id;
            }
        }
        return $eligible;
    }

    private function build_plan(int $productId, array $options, bool $live): array
    {
        $post = function_exists('get_post') ? get_post($productId) : null;
        $imageState = $this->capture_image_state($productId);
        $base = [
            'ok' => false,
            'action_name' => 'Ovoko → Woo Gmail draft update',
            'mode' => $live ? 'live_update_one_product' : 'preview_one_product',
            'dry_run' => !$live,
            'product_id' => $productId,
            'sku' => $this->get_meta($productId, '_sku'),
            'ovoko_part_id' => $this->get_part_id($productId),
            'current_status' => $post ? (string) $post->post_status : '',
            'would_update' => false,
            'would_publish' => false,
            'ready_for_sale' => false,
            'blocked_reasons' => [],
            'images_preserved' => true,
            'thumbnail_id_before' => $imageState['thumbnail_id'],
            'thumbnail_id_after' => $imageState['thumbnail_id'],
            'gallery_before_count' => $imageState['gallery_count'],
            'gallery_after_count' => $imageState['gallery_count'],
            'diff' => [],
            'report' => [],
            'csv' => '',
            'raw' => [],
            'safety' => ['no_new_product_create' => true, 'no_image_import' => true, 'only_existing_product_id' => true],
        ];
        if (!$post || (string) ($post->post_type ?? '') !== 'product') {
            $base['blocked_reasons'][] = 'missing_existing_woo_product';
            return $this->finalize_report($base);
        }
        if (!$this->is_gmail_product($productId)) {
            $base['blocked_reasons'][] = 'non_gmail_sku';
            return $this->finalize_report($base);
        }
        if ($base['ovoko_part_id'] === '') {
            $base['blocked_reasons'][] = 'missing_part_id';
            return $this->finalize_report($base);
        }

        $fetch = $this->fetch_part((string) $base['ovoko_part_id']);
        $base['raw']['fetch'] = $fetch;
        if (empty($fetch['ok'])) {
            $base['blocked_reasons'][] = 'ovoko_fetch_failed';
            $base['error'] = (string) ($fetch['error'] ?? $fetch['message'] ?? 'Ovoko fetch failed.');
            return $this->finalize_report($base);
        }
        $part = (array) ($fetch['normalized'] ?? []);
        $target = $this->build_target($productId, $post, $part, $options, $live);
        $base['target'] = $target;
        $base['diff'] = $this->build_diff($productId, $post, $target);
        $base['would_update'] = !empty($base['diff']);

        $blocked = $this->ready_for_sale_blockers($productId, $part, $target, $fetch, $imageState);
        $base['blocked_reasons'] = array_values(array_unique(array_merge($base['blocked_reasons'], $blocked)));
        $base['ready_for_sale'] = $base['blocked_reasons'] === [];
        $base['would_publish'] = !empty($options['publish_when_ready']) && !empty($base['ready_for_sale']);
        if ($base['would_publish']) {
            $base['target']['status_after'] = 'publish';
            $base['diff']['status'] = ['before' => (string) $post->post_status, 'after' => 'publish'];
        } else {
            $base['target']['status_after'] = 'draft';
            $base['diff']['status'] = ['before' => (string) $post->post_status, 'after' => 'draft'];
        }
        $base['ok'] = true;
        return $this->finalize_report($base);
    }

    private function fetch_part(string $partId): array
    {
        if ($this->partFetcher) {
            $fetched = call_user_func($this->partFetcher, $partId);
            return is_array($fetched) ? $fetched : ['ok' => false, 'error' => 'custom_fetcher_returned_non_array'];
        }
        $client = new RrrApiClient($this->settings);
        $result = $client->preview_fetch_single_part((int) $partId);
        $payload = (array) ($result['payload'] ?? []);
        return $result + ['normalized' => $client->normalize_rrr_single_part_payload($payload), 'endpoint' => '/get/part/{part_id}'];
    }

    private function build_target(int $productId, object $post, array $part, array $options, bool $live): array
    {
        $title = trim((string) (($part['title'] ?? '') ?: ($part['name'] ?? '')));
        $description = trim((string) (($part['notes'] ?? '') ?: ($part['description'] ?? '')));
        $short = $this->summary_text($description, 240);
        $price = $this->normalize_price($part['woo_target_price'] ?? $part['price'] ?? $part['ovoko_price'] ?? null);
        $category = $this->resolve_category($part, $live);
        $meta = $this->build_meta($part);
        return [
            'title' => $title !== '' ? $title : (string) $post->post_title,
            'slug' => $title !== '' && function_exists('sanitize_title') ? sanitize_title($title . '-' . ($part['part_id'] ?? '')) : (string) ($post->post_name ?? ''),
            'description' => $description !== '' ? $description : (string) ($post->post_content ?? ''),
            'short_description' => $short !== '' ? $short : (string) ($post->post_excerpt ?? ''),
            'price' => $price,
            'category_path' => (string) ($category['path'] ?? ''),
            'category_term_id' => (int) ($category['term_id'] ?? 0),
            'category_mapping_ok' => !empty($category['ok']),
            'attributes' => $this->build_attributes($part),
            'meta' => $meta,
        ];
    }

    private function build_meta(array $part): array
    {
        $partId = (string) ($part['part_id'] ?? '');
        $meta = [
            '_ovoko_part_id' => $partId,
            'ovoko_part_id' => $partId,
            '_ovoko_status' => (string) ($part['status'] ?? ''),
            '_ovoko_updated_at' => (string) (($part['updated_at'] ?? '') ?: gmdate('c')),
            '_ovoko_category' => (string) ($part['category_title_path'] ?? ''),
            '_ovoko_category_id' => (string) ($part['category_id'] ?? ''),
            '_ovoko_car_id' => (string) ($part['car_id'] ?? ''),
            '_ovoko_manufacturer_code' => (string) ($part['manufacturer_code'] ?? ''),
            '_manufacturer_code' => (string) ($part['manufacturer_code'] ?? ''),
            '_mpn' => (string) ($part['manufacturer_code'] ?? ''),
            'mpn' => (string) ($part['manufacturer_code'] ?? ''),
            '_ovoko_visible_code' => (string) ($part['visible_code'] ?? ''),
            '_ovoko_other_code' => (string) ($part['other_code'] ?? ''),
            '_ovoko_price' => (string) ($part['price'] ?? ''),
            '_ovoko_woo_target_price' => (string) ($part['woo_target_price'] ?? ''),
            '_ovoko_source_url' => (string) (($part['show_url'] ?? '') ?: ($part['shop_url'] ?? '')),
            '_ovoko_vehicle_make' => (string) ($part['vehicle_make'] ?? ''),
            '_ovoko_vehicle_model' => (string) ($part['vehicle_model'] ?? ''),
            '_ovoko_vehicle_version' => (string) (($part['vehicle_generation'] ?? '') ?: ($part['version'] ?? '')),
            '_ovoko_engine_code' => (string) (($part['vehicle_engine_code'] ?? '') ?: ($part['engine_code'] ?? '')),
            '_ovoko_year' => (string) (($part['vehicle_year'] ?? '') ?: ($part['year'] ?? '')),
            '_ovoko_raw_payload' => $this->json_encode($part),
            'source' => 'ovoko_master_gmail_draft_update',
        ];
        return array_filter($meta, static fn($v): bool => $v !== '');
    }

    private function build_attributes(array $part): array
    {
        return array_filter([
            'Numer części' => (string) ($part['manufacturer_code'] ?? ''),
            'Kod widoczny' => (string) ($part['visible_code'] ?? ''),
            'OEM / inne numery' => (string) ($part['other_code'] ?? ''),
            'ID części Ovoko' => (string) ($part['part_id'] ?? ''),
            'ID pojazdu Ovoko' => (string) ($part['car_id'] ?? ''),
            'Producent' => (string) ($part['vehicle_make'] ?? ''),
            'Model' => (string) ($part['vehicle_model'] ?? ''),
            'Wersja' => (string) ($part['vehicle_generation'] ?? ''),
            'Silnik' => (string) (($part['vehicle_engine_code'] ?? '') ?: ($part['vehicle_engine_marketing'] ?? '')),
            'Rok' => (string) ($part['vehicle_year'] ?? ''),
            'Kategoria Ovoko' => (string) ($part['category_title_path'] ?? ''),
            'Status sprzedażowy' => (string) ($part['status'] ?? ''),
            'Stan' => (string) ($part['quality'] ?? ''),
        ], static fn($v): bool => $v !== '');
    }

    private function ready_for_sale_blockers(int $productId, array $part, array $target, array $fetch, array $imageState): array
    {
        $blocked = [];
        if ($this->normalize_price($target['price'] ?? null) === '') { $blocked[] = 'missing_price'; }
        if (trim((string) ($target['category_path'] ?? '')) === '') { $blocked[] = 'missing_category'; }
        if (empty($target['category_mapping_ok'])) { $blocked[] = 'category_mapping_failed'; }
        if (trim((string) ($target['title'] ?? '')) === '') { $blocked[] = 'missing_title'; }
        if ($this->summary_text((string) ($target['description'] ?? '')) === '') { $blocked[] = 'missing_description'; }
        if ((int) $imageState['thumbnail_id'] <= 0 && (int) $imageState['gallery_count'] <= 0) { $blocked[] = 'missing_existing_woo_images'; }
        if (trim((string) ($part['part_id'] ?? '')) === '') { $blocked[] = 'missing_part_id'; }
        if (empty($fetch['ok'])) { $blocked[] = 'ovoko_fetch_failed'; }
        return array_values(array_unique($blocked));
    }

    private function build_diff(int $productId, object $post, array $target): array
    {
        $currentCategory = $this->current_category_path($productId);
        $metaDiff = [];
        foreach ((array) ($target['meta'] ?? []) as $key => $after) {
            $before = $this->get_meta($productId, (string) $key);
            if ((string) $before !== (string) $after) {
                $metaDiff[$key] = ['before' => (string) $before, 'after' => (string) $after];
            }
        }
        $diff = [
            'title' => ['before' => (string) $post->post_title, 'after' => (string) ($target['title'] ?? '')],
            'price' => ['before' => $this->get_meta($productId, '_price'), 'after' => (string) ($target['price'] ?? '')],
            'category' => ['before' => $currentCategory, 'after' => (string) ($target['category_path'] ?? '')],
            'status' => ['before' => (string) $post->post_status, 'after' => (string) $post->post_status],
            'description' => ['before' => $this->summary_text((string) $post->post_content), 'after' => $this->summary_text((string) ($target['description'] ?? ''))],
            'short_description' => ['before' => $this->summary_text((string) ($post->post_excerpt ?? '')), 'after' => $this->summary_text((string) ($target['short_description'] ?? ''))],
            'key_meta' => $metaDiff,
        ];
        return array_filter($diff, static function ($row): bool {
            if (!is_array($row) || isset($row['before'], $row['after'])) {
                return !is_array($row) || (string) ($row['before'] ?? '') !== (string) ($row['after'] ?? '');
            }
            return !empty($row);
        });
    }

    private function resolve_category(array $part, bool $live): array
    {
        $path = trim((string) ($part['category_title_path'] ?? $part['category'] ?? ''));
        if ($path === '') {
            return ['ok' => false, 'path' => '', 'term_id' => 0];
        }
        $termId = $this->find_category_term_by_path($path);
        if ($termId <= 0 && $live) {
            $termId = $this->ensure_category_path($path, (string) ($part['category_id'] ?? ''));
        }
        return ['ok' => $termId > 0 || !$live, 'path' => $path, 'term_id' => $termId];
    }

    private function find_category_term_by_path(string $path): int
    {
        if (!function_exists('get_term_by')) { return 0; }
        $parts = array_values(array_filter(array_map('trim', preg_split('#\s*(?:>|/|»|→)\s*#u', $path) ?: [])));
        if ($parts === []) { return 0; }
        $term = get_term_by('name', end($parts), 'product_cat');
        return is_object($term) && !empty($term->term_id) ? (int) $term->term_id : 0;
    }

    private function ensure_category_path(string $path, string $ovokoCategoryId = ''): int
    {
        if (!function_exists('wp_insert_term')) { return $this->find_category_term_by_path($path); }
        $parts = array_values(array_filter(array_map('trim', preg_split('#\s*(?:>|/|»|→)\s*#u', $path) ?: [])));
        $parent = 0;
        $termId = 0;
        foreach ($parts as $index => $name) {
            $existing = function_exists('get_terms') ? get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => $name, 'parent' => $parent, 'number' => 1]) : [];
            if (is_array($existing) && !empty($existing[0]->term_id)) {
                $termId = (int) $existing[0]->term_id;
            } else {
                $created = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
                if ($this->is_wp_error($created)) { return 0; }
                $termId = (int) ($created['term_id'] ?? 0);
            }
            if ($termId > 0 && function_exists('update_term_meta')) {
                update_term_meta($termId, '_gpswiss_ovoko_category_path', $path);
                if ($ovokoCategoryId !== '' && $index === count($parts) - 1) {
                    update_term_meta($termId, '_gpswiss_ovoko_category_id', $ovokoCategoryId);
                }
            }
            $parent = $termId;
        }
        return $termId;
    }

    private function upsert_custom_product_attributes(int $productId, array $technicalAttributes): void
    {
        $existing = (array) get_post_meta($productId, '_product_attributes', true);
        $position = count($existing);
        foreach ($technicalAttributes as $label => $value) {
            $key = function_exists('sanitize_title') ? sanitize_title((string) $label) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $label));
            $existing[$key] = ['name' => (string) $label, 'value' => (string) $value, 'position' => $position++, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0];
        }
        update_post_meta($productId, '_product_attributes', $existing);
    }

    private function capture_image_state(int $productId): array
    {
        $gallery = trim($this->get_meta($productId, '_product_image_gallery'));
        $galleryIds = $gallery === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $gallery)), static fn($v): bool => $v !== ''));
        return ['thumbnail_id' => (int) $this->get_meta($productId, '_thumbnail_id'), 'gallery' => $gallery, 'gallery_count' => count($galleryIds)];
    }

    private function restore_image_state(int $productId, array $state): void
    {
        update_post_meta($productId, '_thumbnail_id', (string) ((int) ($state['thumbnail_id'] ?? 0)));
        update_post_meta($productId, '_product_image_gallery', (string) ($state['gallery'] ?? ''));
    }

    private function same_image_state(array $a, array $b): bool
    {
        return (int) ($a['thumbnail_id'] ?? 0) === (int) ($b['thumbnail_id'] ?? 0) && (string) ($a['gallery'] ?? '') === (string) ($b['gallery'] ?? '');
    }

    private function write_live_audit_meta(int $productId, array $result, bool $updated, bool $published): void
    {
        if ($productId <= 0 || !function_exists('update_post_meta')) { return; }
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_update_at', gmdate('c'));
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_update_status', $updated ? 'updated' : 'skipped');
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_update_source_part_id', (string) ($result['ovoko_part_id'] ?? ''));
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_was_published', $published ? '1' : '0');
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_blocked_reasons', $this->json_encode((array) ($result['blocked_reasons'] ?? [])));
        update_post_meta($productId, '_gps_ovoko_to_woo_gmail_images_preserved', !empty($result['images_preserved']) ? '1' : '0');
    }

    private function finalize_report(array $result): array
    {
        $row = $this->report_row($result);
        $result['report'] = $row;
        $result['csv'] = $this->build_csv([$row]);
        return $result;
    }

    private function report_row(array $result): array
    {
        $diff = (array) ($result['diff'] ?? []);
        return [
            'product_id' => (string) ($result['product_id'] ?? ''),
            'sku' => (string) ($result['sku'] ?? ''),
            'ovoko_part_id' => (string) ($result['ovoko_part_id'] ?? ''),
            'current_status' => (string) ($result['current_status'] ?? ''),
            'ready_for_sale' => !empty($result['ready_for_sale']) ? '1' : '0',
            'would_update' => !empty($result['would_update']) ? '1' : '0',
            'would_publish' => !empty($result['would_publish']) ? '1' : '0',
            'updated' => !empty($result['updated']) ? '1' : '0',
            'published' => !empty($result['published']) ? '1' : '0',
            'blocked_reasons' => implode('|', (array) ($result['blocked_reasons'] ?? [])),
            'price_before' => (string) ($diff['price']['before'] ?? ''),
            'price_after' => (string) ($diff['price']['after'] ?? ''),
            'title_before' => (string) ($diff['title']['before'] ?? ''),
            'title_after' => (string) ($diff['title']['after'] ?? ''),
            'category_before' => (string) ($diff['category']['before'] ?? ''),
            'category_after' => (string) ($diff['category']['after'] ?? ''),
            'thumbnail_id_before' => (string) ($result['thumbnail_id_before'] ?? ''),
            'thumbnail_id_after' => (string) ($result['thumbnail_id_after'] ?? ''),
            'gallery_before_count' => (string) ($result['gallery_before_count'] ?? ''),
            'gallery_after_count' => (string) ($result['gallery_after_count'] ?? ''),
            'images_preserved' => !empty($result['images_preserved']) ? '1' : '0',
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    private function build_csv(array $rows): string
    {
        $columns = ['product_id','sku','ovoko_part_id','current_status','ready_for_sale','would_update','would_publish','updated','published','blocked_reasons','price_before','price_after','title_before','title_after','category_before','category_after','thumbnail_id_before','thumbnail_id_after','gallery_before_count','gallery_after_count','images_preserved','error'];
        $lines = [implode(',', $columns)];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = '"' . str_replace('"', '""', (string) ($row[$column] ?? '')) . '"';
            }
            $lines[] = implode(',', $values);
        }
        return implode("\n", $lines) . "\n";
    }

    private function is_gmail_product(int $productId): bool
    {
        return str_starts_with($this->get_meta($productId, '_sku'), 'GPS-GMAIL-');
    }

    private function get_part_id(int $productId): string
    {
        $partId = trim($this->get_meta($productId, '_ovoko_part_id'));
        return $partId !== '' ? $partId : trim($this->get_meta($productId, 'ovoko_part_id'));
    }

    private function get_meta(int $productId, string $key): string
    {
        return function_exists('get_post_meta') ? (string) get_post_meta($productId, $key, true) : '';
    }

    private function current_category_path(int $productId): string
    {
        if (!function_exists('wp_get_post_terms')) { return ''; }
        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'all']);
        if (!is_array($terms) || empty($terms[0]->name)) { return ''; }
        return (string) $terms[0]->name;
    }

    private function normalize_price(mixed $value): string
    {
        if ($value === null || $value === '') { return ''; }
        $value = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($value) || (float) $value <= 0) { return ''; }
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function summary_text(string $text, int $limit = 180): string
    {
        $plain = trim(function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text));
        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit) . '…' : $plain;
    }

    private function normalize_options(array $options): array
    {
        return ['dry_run' => $options['dry_run'] ?? true, 'publish_when_ready' => $options['publish_when_ready'] ?? true, 'stop_on_first_error' => $options['stop_on_first_error'] ?? false];
    }


    private function json_encode(array $value): string
    {
        return function_exists('wp_json_encode') ? (string) wp_json_encode($value) : (string) json_encode($value);
    }

    private function is_wp_error(mixed $value): bool
    {
        return function_exists('is_wp_error') && is_wp_error($value);
    }

    private function wp_error_message(mixed $value): string
    {
        return is_object($value) && method_exists($value, 'get_error_message') ? (string) $value->get_error_message() : 'WordPress error';
    }
}
