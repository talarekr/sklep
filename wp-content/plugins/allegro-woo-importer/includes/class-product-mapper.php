<?php

namespace AWI;

use WC_Product;
use WC_Product_Attribute;

if (!defined('ABSPATH')) {
    exit;
}

class ProductMapper
{
    private const MAX_IMAGE_FILE_SIZE_BYTES = 12582912; // 12 MB
    private const MAX_IMAGE_TOTAL_PIXELS = 24000000; // 24 MP
    private const IMPORT_ALLOWED_SUBSIZES = ['thumbnail'];

    private AllegroClient $client;
    private Logger $logger;
    private array $image_import_context = [];

    public function __construct(AllegroClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function upsert_product(array $offer, array $settings): array
    {
        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        if ($offer_id === '') {
            return ['result' => 'skipped', 'error' => 'missing_offer_id'];
        }

        $existing_id = $this->find_product_id_by_offer_id($offer_id);
        $sync_mode = $settings['sync_mode'] ?? 'create_update';

        if ($existing_id && $sync_mode === 'create_only') {
            return ['result' => 'skipped', 'product_id' => $existing_id];
        }

        if (!$existing_id && $sync_mode === 'update_only') {
            return ['result' => 'skipped'];
        }

        $product = $existing_id ? wc_get_product($existing_id) : new \WC_Product_Simple();
        if (!$product instanceof WC_Product) {
            return ['result' => 'error', 'error' => 'invalid_product_instance'];
        }

        $title = sanitize_text_field((string) ($offer['name'] ?? __('Oferta Allegro', 'allegro-woo-importer')));
        $description = $this->map_description($offer);
        $price = $this->extract_price($offer);
        $currency = sanitize_text_field((string) ($offer['sellingMode']['price']['currency'] ?? 'PLN'));
        $publication_status = strtoupper((string) ($offer['publication']['status'] ?? 'INACTIVE'));
        $missing_fields = $this->collect_missing_fields($offer, $title, $description, $price);
        if (!empty($missing_fields)) {
            $this->logger->warning('Offer mapping used fallback values.', ['offer_id' => $offer_id, 'missing_fields' => $missing_fields]);
        }

        $product->set_name($title);
        $product->set_description($description);

        if ($price !== null) {
            $product->set_regular_price((string) $price);
            $product->set_price((string) $price);
        }

        $sku = $this->extract_sku($offer);
        if (!empty($sku)) {
            $product->set_sku($sku);
        }

        $product->set_catalog_visibility('visible');
        $product->set_status($this->map_product_status($publication_status, $settings));

        if (isset($offer['stock']['available'])) {
            $stock_qty = (int) $offer['stock']['available'];
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_qty);
            $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
        }

        $product_id = $product->save();

        if (!$product_id) {
            return ['result' => 'error', 'error' => 'save_failed'];
        }

        $this->assign_category($product_id, $offer);
        $this->map_attributes($product, $offer);
        $this->sync_product_images($product, $offer, $offer_id);
        $this->logger->info('Saving product after image sync.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'image_id_before_save' => (int) $product->get_image_id(),
            'gallery_ids_before_save' => array_map('intval', $product->get_gallery_image_ids()),
        ]);
        $product->save();

        $part_number_result = $this->extract_part_number($offer);
        $existing_part_number = sanitize_text_field((string) get_post_meta($product_id, '_part_number', true));
        $part_number_to_save = 'Brak';

        if ($part_number_result['found']) {
            $part_number_to_save = $part_number_result['value'];
        } elseif ($existing_part_number !== '' && $existing_part_number !== 'Brak') {
            $part_number_to_save = $existing_part_number;
        }

        update_post_meta($product_id, '_part_number', $part_number_to_save);

        $this->logger->info('Part number mapping decision saved to product meta.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'found_in_offer' => (bool) $part_number_result['found'],
            'source' => (string) $part_number_result['source'],
            'existing_meta_before_save' => $existing_part_number,
            'saved_meta_value' => $part_number_to_save,
        ]);

        update_post_meta($product_id, '_allegro_offer_id', $offer_id);
        update_post_meta($product_id, '_allegro_offer_url', esc_url_raw($this->extract_offer_url($offer)));
        update_post_meta($product_id, '_allegro_category_id', sanitize_text_field((string) ($offer['category']['id'] ?? '')));
        update_post_meta($product_id, '_allegro_status', $publication_status);
        update_post_meta($product_id, '_allegro_currency', $currency);
        update_post_meta($product_id, '_allegro_imported_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($product_id, '_allegro_parameters', wp_json_encode($offer['parameters'] ?? []));

        $this->logger->info('Product import upsert completed.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'operation' => $existing_id ? 'updated' : 'created',
        ]);

        return ['result' => $existing_id ? 'updated' : 'created', 'product_id' => $product_id];
    }

    private function find_product_id_by_offer_id(string $offer_id): int
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_allegro_offer_id',
                    'value' => $offer_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function map_description(array $offer): string
    {
        if (!empty($offer['description']['sections']) && is_array($offer['description']['sections'])) {
            $html = '';
            foreach ($offer['description']['sections'] as $section) {
                foreach (($section['items'] ?? []) as $item) {
                    if (($item['type'] ?? '') === 'TEXT' && !empty($item['content'])) {
                        $html .= wp_kses_post((string) $item['content']);
                    }
                }
            }
            return $html;
        }

        if (!empty($offer['description']) && is_string($offer['description'])) {
            return wp_kses_post($offer['description']);
        }

        return '';
    }

    private function extract_price(array $offer): ?float
    {
        $amount = $offer['sellingMode']['price']['amount'] ?? ($offer['sellingMode']['startingPrice']['amount'] ?? null);
        if ($amount === null || $amount === '') {
            return null;
        }

        return (float) $amount;
    }

    private function extract_sku(array $offer): string
    {
        foreach (($offer['parameters'] ?? []) as $parameter) {
            $name = mb_strtolower((string) ($parameter['name'] ?? ''));
            if (in_array($name, ['numer części', 'nr części', 'sku', 'part number'], true) && !empty($parameter['values'])) {
                $value = $parameter['values'][0] ?? '';
                return sanitize_text_field((string) $value);
            }
        }

        return '';
    }

    private function extract_part_number(array $offer): array
    {
        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        $parameter_sources = $this->collect_part_number_parameter_sources($offer);

        $total_parameters = 0;
        $sources_preview = [];
        foreach ($parameter_sources as $source) {
            $parameters_in_source = is_array($source['parameters'] ?? null) ? count((array) $source['parameters']) : 0;
            $total_parameters += $parameters_in_source;
            $sources_preview[] = [
                'path' => (string) ($source['path'] ?? ''),
                'count' => $parameters_in_source,
                'preview' => is_array($source['parameters'] ?? null) ? array_slice((array) $source['parameters'], 0, 4) : [],
            ];
        }

        $this->logger->info('Inspecting Allegro parameters for part number mapping.', [
            'offer_id' => $offer_id,
            'parameters_count' => $total_parameters,
            'parameters_sources_preview' => $sources_preview,
        ]);

        foreach ($parameter_sources as $source) {
            $source_path = sanitize_text_field((string) ($source['path'] ?? 'unknown'));
            $parameters = is_array($source['parameters'] ?? null) ? (array) $source['parameters'] : [];

            foreach ($parameters as $parameter) {
                $raw_name = sanitize_text_field((string) ($parameter['name'] ?? ''));
                $name = $this->normalize_parameter_name($raw_name);
                if (!in_array($name, ['numer katalogowy części', 'numer katalogowy czesci'], true)) {
                    continue;
                }

                $this->logger->info('Found Allegro part number parameter by name match.', [
                    'offer_id' => $offer_id,
                    'raw_name' => $raw_name,
                    'normalized_name' => $name,
                    'source_path' => $source_path,
                ]);

                $source_map = [
                    'values' => (array) ($parameter['values'] ?? []),
                    'valuesLabels' => (array) ($parameter['valuesLabels'] ?? []),
                    'value' => isset($parameter['value']) ? [(string) $parameter['value']] : [],
                    'valueLabel' => isset($parameter['valueLabel']) ? [(string) $parameter['valueLabel']] : [],
                ];

                foreach ($source_map as $source_value_key => $candidates) {
                    foreach ($candidates as $candidate) {
                        $normalized = sanitize_text_field((string) $candidate);
                        if (trim($normalized) === '') {
                            continue;
                        }

                        $matched_source = $source_path . '.' . $source_value_key;
                        $this->logger->info('Part number extracted from Allegro parameter source.', [
                            'offer_id' => $offer_id,
                            'source' => $matched_source,
                            'value' => $normalized,
                        ]);

                        return [
                            'found' => true,
                            'value' => $normalized,
                            'source' => $matched_source,
                        ];
                    }
                }
            }
        }

        $this->logger->warning('Part number parameter not found in Allegro offer parameters.', [
            'offer_id' => $offer_id,
            'checked_sources' => array_map(
                static fn (array $source): string => (string) ($source['path'] ?? ''),
                $parameter_sources
            ),
        ]);

        return [
            'found' => false,
            'value' => 'Brak',
            'source' => 'fallback',
        ];
    }

    private function normalize_parameter_name(string $name): string
    {
        $name = mb_strtolower(trim(sanitize_text_field($name)));
        $name = str_replace(
            ['ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ż', 'ź'],
            ['a', 'c', 'e', 'l', 'n', 'o', 's', 'z', 'z'],
            $name
        );

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }

    private function collect_part_number_parameter_sources(array $offer): array
    {
        $sources = [];
        if (is_array($offer['parameters'] ?? null)) {
            $sources[] = [
                'path' => 'parameters',
                'parameters' => (array) $offer['parameters'],
            ];
        }

        $product_set = $offer['productSet'] ?? [];
        if (!is_array($product_set)) {
            return $sources;
        }

        foreach ($product_set as $index => $product_set_item) {
            if (!is_array($product_set_item)) {
                continue;
            }

            $product_parameters = $product_set_item['product']['parameters'] ?? null;
            if (is_array($product_parameters)) {
                $sources[] = [
                    'path' => sprintf('productSet[%d].product.parameters', (int) $index),
                    'parameters' => $product_parameters,
                ];
            }

            $item_parameters = $product_set_item['parameters'] ?? null;
            if (is_array($item_parameters)) {
                $sources[] = [
                    'path' => sprintf('productSet[%d].parameters', (int) $index),
                    'parameters' => $item_parameters,
                ];
            }
        }

        return $sources;
    }

    private function map_product_status(string $publication_status, array $settings): string
    {
        if ($publication_status === 'ACTIVE') {
            return 'publish';
        }

        $inactive = $settings['inactive_product_status'] ?? 'draft';
        return in_array($inactive, ['draft', 'private'], true) ? $inactive : 'draft';
    }

    private function assign_category(int $product_id, array $offer): void
    {
        $allegro_category_id = sanitize_text_field((string) ($offer['category']['id'] ?? ''));
        if ($allegro_category_id === '') {
            return;
        }

        $allegro_category_name = sanitize_text_field((string) ($offer['category']['name'] ?? ''));
        $category_path = $this->extract_allegro_category_path($offer, $allegro_category_id, $allegro_category_name);
        if ($category_path === []) {
            return;
        }

        $parent_term_id = 0;
        $leaf_term_id = 0;

        foreach ($category_path as $node) {
            $node_id = sanitize_text_field((string) ($node['id'] ?? ''));
            $node_name = sanitize_text_field((string) ($node['name'] ?? ''));
            if ($node_id === '' || $node_name === '') {
                continue;
            }

            $term_id = $this->find_or_create_category_term($node_id, $node_name, $parent_term_id);
            if ($term_id <= 0) {
                continue;
            }

            $parent_term_id = $term_id;
            $leaf_term_id = $term_id;
        }

        if ($leaf_term_id > 0) {
            wp_set_post_terms($product_id, [$leaf_term_id], 'product_cat', false);
        }
    }

    private function extract_allegro_category_path(array $offer, string $category_id, string $fallback_name): array
    {
        $raw_path = $offer['category']['path'] ?? null;
        if (is_array($raw_path) && $raw_path !== []) {
            $mapped = [];
            foreach ($raw_path as $item) {
                $id = sanitize_text_field((string) ($item['id'] ?? ''));
                $name = sanitize_text_field((string) ($item['name'] ?? ''));
                if ($id !== '' && $name !== '') {
                    $mapped[] = ['id' => $id, 'name' => $name];
                }
            }

            if ($mapped !== []) {
                return $mapped;
            }
        }

        $path = $this->client->get_category_path($category_id);
        if (is_wp_error($path)) {
            $this->logger->warning('Failed to fetch Allegro category path; using fallback category node.', [
                'category_id' => $category_id,
                'error' => $path->get_error_message(),
            ]);

            $name = $fallback_name !== '' ? $fallback_name : $category_id;
            return [['id' => $category_id, 'name' => $name]];
        }

        if (is_array($path) && $path !== []) {
            return $path;
        }

        $name = $fallback_name !== '' ? $fallback_name : $category_id;
        return [['id' => $category_id, 'name' => $name]];
    }

    private function find_or_create_category_term(string $allegro_category_id, string $name, int $parent_term_id): int
    {
        $existing = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 1,
            'meta_query' => [
                [
                    'key' => '_allegro_category_id',
                    'value' => $allegro_category_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if (is_array($existing) && !empty($existing[0]) && $existing[0] instanceof \WP_Term) {
            $term = $existing[0];
            $updates = [];
            if ((int) $term->parent !== $parent_term_id) {
                $updates['parent'] = $parent_term_id;
            }
            if ($term->name !== $name) {
                $updates['name'] = $name;
            }
            if ($updates !== []) {
                wp_update_term($term->term_id, 'product_cat', $updates);
            }

            update_term_meta($term->term_id, '_allegro_category_id', $allegro_category_id);
            return (int) $term->term_id;
        }

        $term = term_exists($name, 'product_cat', $parent_term_id);
        if ($term) {
            $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
            if ($term_id > 0) {
                update_term_meta($term_id, '_allegro_category_id', $allegro_category_id);
                return $term_id;
            }
        }

        $created = wp_insert_term($name, 'product_cat', [
            'parent' => $parent_term_id,
        ]);

        if (is_wp_error($created)) {
            $this->logger->error('Failed to create WooCommerce category for Allegro category.', [
                'allegro_category_id' => $allegro_category_id,
                'name' => $name,
                'parent_term_id' => $parent_term_id,
                'error' => $created->get_error_message(),
            ]);
            return 0;
        }

        $term_id = (int) ($created['term_id'] ?? 0);
        if ($term_id > 0) {
            update_term_meta($term_id, '_allegro_category_id', $allegro_category_id);
        }

        return $term_id;
    }

    private function map_attributes(WC_Product $product, array $offer): void
    {
        $attributes = [];

        foreach (($offer['parameters'] ?? []) as $parameter) {
            $name = sanitize_text_field((string) ($parameter['name'] ?? ''));
            $values = array_map('sanitize_text_field', $parameter['values'] ?? []);
            if ($name === '' || empty($values)) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name($name);
            $attribute->set_options($values);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }
    }

    private function extract_offer_url(array $offer): string
    {
        if (!empty($offer['external']['id'])) {
            return 'https://allegro.pl/oferta/' . rawurlencode((string) $offer['external']['id']);
        }

        if (!empty($offer['id'])) {
            return 'https://allegro.pl/oferta/' . rawurlencode((string) $offer['id']);
        }

        return '';
    }

    private function sync_product_images(WC_Product $product, array $offer, string $offer_id): void
    {
        $images_raw = $offer['images'] ?? [];
        $images = is_array($images_raw) ? $images_raw : [];

        $product_id = $product->get_id();
        if ($product_id <= 0) {
            $this->logger->error('Cannot sync images for unsaved product.', ['offer_id' => $offer_id]);
            return;
        }

        $this->logger->info('Starting product image sync.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'images_found' => count($images),
        ]);

        $image_urls = [];
        foreach ($images as $image) {
            $url = '';
            if (is_array($image)) {
                $url = (string) ($image['url'] ?? '');
            } elseif (is_string($image)) {
                $url = $image;
            }

            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $image_urls[] = $url;
        }

        $image_urls = array_values(array_unique($image_urls));

        $this->logger->info('Normalized image URLs count.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'normalized_image_urls_count' => count($image_urls),
        ]);

        if (empty($image_urls)) {
            $this->logger->warning('No valid image URLs found after normalization.', ['offer_id' => $offer_id, 'product_id' => $product_id]);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $gallery_ids = [];

        foreach ($image_urls as $index => $url) {
            $image_no = (int) $index + 1;
            $this->logger->info('Image import started.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
            ]);
            $this->persist_last_image_checkpoint([
                'stage' => 'before_attachment_lookup',
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
            ]);
            $existing_attachment_id = $this->find_existing_attachment_by_source($url);
            if ($existing_attachment_id > 0) {
                $this->logger->info('Reusing existing attachment for image URL.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => $existing_attachment_id]);
                $this->logger->info('Sideload/reuse result attachment ID.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => (int) $existing_attachment_id, 'source' => 'reuse']);
                $gallery_ids[] = $existing_attachment_id;
                continue;
            }

            $attachment_id = $this->sideload_image_attachment($url, $product_id);
            if (is_wp_error($attachment_id)) {
                $this->logger->error('Image sideload failed.', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'image_index' => $image_no,
                    'images_total' => count($image_urls),
                    'url' => $url,
                    'error_code' => $attachment_id->get_error_code(),
                    'error_message' => $attachment_id->get_error_message(),
                    'error_data' => $attachment_id->get_error_data(),
                ]);
                continue;
            }
            $this->logger->info('Image sideload succeeded.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
                'attachment_id' => (int) $attachment_id,
                'source' => 'sideload',
            ]);

            update_post_meta($attachment_id, '_awi_source_url', $url);
            $gallery_ids[] = (int) $attachment_id;
            $this->logger->info('Attachment created for image URL.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
                'attachment_id' => (int) $attachment_id,
            ]);
        }

        $gallery_ids = array_values(array_unique(array_map('intval', $gallery_ids)));

        if (empty($gallery_ids)) {
            $this->logger->warning('No valid image attachments were created for offer.', ['offer_id' => $offer_id, 'product_id' => $product_id]);
            return;
        }

        $featured_id = (int) $gallery_ids[0];
        $gallery_only = array_values(array_filter(array_map('intval', array_slice($gallery_ids, 1))));

        $product->set_image_id($featured_id);
        $product->set_gallery_image_ids($gallery_only);
        update_post_meta($product_id, '_thumbnail_id', $featured_id);
        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_only));

        $this->logger->info('Final product image ID.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'final_image_id' => (int) $product->get_image_id(),
            'thumbnail_meta' => (int) get_post_meta($product_id, '_thumbnail_id', true),
        ]);
        $this->logger->info('Final product gallery image IDs.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'final_gallery_image_ids' => array_map('intval', $product->get_gallery_image_ids()),
            'gallery_meta' => sanitize_text_field((string) get_post_meta($product_id, '_product_image_gallery', true)),
        ]);

        $this->logger->info('Product image sync completed.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'featured_attachment_id' => $featured_id,
            'gallery_count' => count($gallery_only),
        ]);
    }

    /**
     * @return int|\WP_Error
     */
    private function sideload_image_attachment(string $image_url, int $product_id)
    {
        $tmp_file = download_url($image_url, 30);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        if (!is_string($tmp_file) || $tmp_file === '') {
            return new \WP_Error('image_download_failed', __('Nie udało się pobrać obrazka.', 'allegro-woo-importer'));
        }

        $skip_reason = $this->get_heavy_image_skip_reason($tmp_file);
        if ($skip_reason !== null) {
            $this->logger->warning('Skipping heavy image before sideload metadata generation.', [
                'product_id' => $product_id,
                'url' => $image_url,
                'skip_reason' => $skip_reason,
            ]);
            if (file_exists($tmp_file)) {
                @unlink($tmp_file);
            }

            return new \WP_Error('image_skipped_heavy', $skip_reason);
        }

        $filename = $this->build_sideload_filename_from_url_and_headers($image_url, $tmp_file);
        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp_file,
        ];

        $this->persist_last_image_checkpoint([
            'stage' => 'before_media_handle_sideload',
            'product_id' => $product_id,
            'url' => $image_url,
            'filename' => $filename,
            'tmp_file' => $tmp_file,
            'file_size_bytes' => (int) @filesize($tmp_file),
        ]);

        $this->begin_image_import_runtime_limits([
            'product_id' => $product_id,
            'url' => $image_url,
            'filename' => $filename,
        ]);
        try {
            $attachment_id = media_handle_sideload($file_array, $product_id);
        } finally {
            $this->end_image_import_runtime_limits();
        }

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp_file)) {
                @unlink($tmp_file);
            }
            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    private function build_sideload_filename_from_url_and_headers(string $url, string $tmp_file): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = sanitize_file_name((string) basename($path));
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'allegro-image';
        }

        $name_without_ext = pathinfo($base, PATHINFO_FILENAME);
        if ($name_without_ext === '') {
            $name_without_ext = 'allegro-image';
        }

        $extension = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if ($extension === '') {
            $mime = $this->detect_mime_from_headers($url);
            if ($mime !== '') {
                $extension = $this->map_mime_to_extension($mime);
            }
        }

        if ($extension === '') {
            $extension = $this->detect_file_extension($tmp_file);
        }

        if ($extension === '') {
            $extension = 'jpg';
        }

        return sanitize_file_name($name_without_ext . '.' . $extension);
    }

    private function detect_mime_from_headers(string $url): string
    {
        $response = wp_safe_remote_head($url, ['timeout' => 10, 'redirection' => 5]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            $response = wp_safe_remote_get($url, [
                'timeout' => 10,
                'redirection' => 5,
                'headers' => ['Range' => 'bytes=0-0'],
            ]);
        }

        if (is_wp_error($response)) {
            return '';
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ($content_type === '') {
            return '';
        }

        $parts = explode(';', $content_type);
        return strtolower(trim((string) ($parts[0] ?? '')));
    }

    private function map_mime_to_extension(string $mime): string
    {
        $mime_to_extension = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tif',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
        ];

        return $mime_to_extension[strtolower($mime)] ?? '';
    }

    private function detect_file_extension(string $tmp_file): string
    {
        $mime = '';

        if (function_exists('wp_get_image_mime')) {
            $mime = (string) wp_get_image_mime($tmp_file);
        }

        if ($mime === '' && function_exists('mime_content_type')) {
            $detected = mime_content_type($tmp_file);
            if (is_string($detected)) {
                $mime = $detected;
            }
        }

        if ($mime === '' && function_exists('getimagesize')) {
            $image_info = @getimagesize($tmp_file);
            if (is_array($image_info) && !empty($image_info['mime']) && is_string($image_info['mime'])) {
                $mime = $image_info['mime'];
            }
        }

        $extension = $this->map_mime_to_extension($mime);
        return $extension !== '' ? $extension : 'jpg';
    }

    private function find_existing_attachment_by_source(string $url): int
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_awi_source_url',
                    'value' => $url,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function collect_missing_fields(array $offer, string $title, string $description, ?float $price): array
    {
        $missing = [];

        if ($title === '' || $title === __('Oferta Allegro', 'allegro-woo-importer')) {
            $missing[] = 'name';
        }

        if ($description === '') {
            $missing[] = 'description';
        }

        if ($price === null) {
            $missing[] = 'sellingMode.price.amount';
        }

        if (empty($offer['images']) || !is_array($offer['images'])) {
            $missing[] = 'images';
        }

        if (empty($offer['publication']['status'])) {
            $missing[] = 'publication.status';
        }

        if (!isset($offer['stock']['available'])) {
            $missing[] = 'stock.available';
        }

        if (empty($offer['external']['id'])) {
            $missing[] = 'external.id';
        }

        return $missing;
    }

    private function get_heavy_image_skip_reason(string $tmp_file): ?string
    {
        $file_size = @filesize($tmp_file);
        if (is_int($file_size) && $file_size > self::MAX_IMAGE_FILE_SIZE_BYTES) {
            return sprintf(
                'Plik obrazu pominięty: %d B > %d B limitu.',
                $file_size,
                self::MAX_IMAGE_FILE_SIZE_BYTES
            );
        }

        $image_size = @getimagesize($tmp_file);
        if (!is_array($image_size)) {
            return 'Plik obrazu pominięty: nie udało się odczytać wymiarów.';
        }

        $width = isset($image_size[0]) ? (int) $image_size[0] : 0;
        $height = isset($image_size[1]) ? (int) $image_size[1] : 0;
        if ($width <= 0 || $height <= 0) {
            return 'Plik obrazu pominięty: nieprawidłowe wymiary.';
        }

        $total_pixels = $width * $height;
        if ($total_pixels > self::MAX_IMAGE_TOTAL_PIXELS) {
            return sprintf(
                'Plik obrazu pominięty: %d px > %d px limitu.',
                $total_pixels,
                self::MAX_IMAGE_TOTAL_PIXELS
            );
        }

        return null;
    }

    private function begin_image_import_runtime_limits(array $context): void
    {
        $this->image_import_context = [
            'active' => true,
            'context' => $context,
        ];

        add_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_image_sizes_advanced'], 9999, 2);
        add_filter('fallback_intermediate_image_sizes', [$this, 'filter_fallback_intermediate_image_sizes'], 9999, 2);
        add_filter('big_image_size_threshold', [$this, 'disable_big_image_size_threshold_for_import'], 9999, 4);
        add_filter('wp_image_editors', [$this, 'prefer_gd_image_editor_for_import'], 9999, 1);
    }

    private function end_image_import_runtime_limits(): void
    {
        remove_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_image_sizes_advanced'], 9999);
        remove_filter('fallback_intermediate_image_sizes', [$this, 'filter_fallback_intermediate_image_sizes'], 9999);
        remove_filter('big_image_size_threshold', [$this, 'disable_big_image_size_threshold_for_import'], 9999);
        remove_filter('wp_image_editors', [$this, 'prefer_gd_image_editor_for_import'], 9999);
        $this->image_import_context = [];
    }

    public function filter_intermediate_image_sizes_advanced(array $sizes): array
    {
        if (!$this->is_image_import_context_active()) {
            return $sizes;
        }

        return array_intersect_key($sizes, array_flip(self::IMPORT_ALLOWED_SUBSIZES));
    }

    public function filter_fallback_intermediate_image_sizes(array $fallback_sizes): array
    {
        if (!$this->is_image_import_context_active()) {
            return $fallback_sizes;
        }

        return self::IMPORT_ALLOWED_SUBSIZES;
    }

    public function disable_big_image_size_threshold_for_import($threshold)
    {
        if (!$this->is_image_import_context_active()) {
            return $threshold;
        }

        return false;
    }

    public function prefer_gd_image_editor_for_import(array $editors): array
    {
        if (!$this->is_image_import_context_active()) {
            return $editors;
        }

        if (!in_array('WP_Image_Editor_GD', $editors, true)) {
            return $editors;
        }

        $filtered = array_values(array_filter($editors, static fn($editor): bool => $editor !== 'WP_Image_Editor_GD'));
        array_unshift($filtered, 'WP_Image_Editor_GD');

        return $filtered;
    }

    private function is_image_import_context_active(): bool
    {
        return !empty($this->image_import_context['active']);
    }

    private function persist_last_image_checkpoint(array $checkpoint): void
    {
        $checkpoint['logged_at'] = gmdate('Y-m-d H:i:s');
        update_option('awi_last_image_import_checkpoint', wp_json_encode($checkpoint), false);
        $this->logger->info('Image import checkpoint.', $checkpoint);
    }
}
