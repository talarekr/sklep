<?php

namespace AWI;

use WC_Product;
use WC_Product_Attribute;

if (!defined('ABSPATH')) {
    exit;
}

class ProductMapper
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
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

        $term_name = 'Allegro ' . $allegro_category_id;
        $term = term_exists($term_name, 'product_cat');
        if (!$term) {
            $term = wp_insert_term($term_name, 'product_cat');
        }

        if (!is_wp_error($term)) {
            $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
            wp_set_post_terms($product_id, [$term_id], 'product_cat', false);
        }
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

        foreach ($image_urls as $url) {
            $this->logger->info('Image URL found.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url]);
            $existing_attachment_id = $this->find_existing_attachment_by_source($url);
            if ($existing_attachment_id > 0) {
                $this->logger->info('Reusing existing attachment for image URL.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => $existing_attachment_id]);
                $this->logger->info('Sideload/reuse result attachment ID.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => (int) $existing_attachment_id, 'source' => 'reuse']);
                $gallery_ids[] = $existing_attachment_id;
                continue;
            }

            $attachment_id = media_sideload_image($url, $product_id, null, 'id');
            if (is_wp_error($attachment_id)) {
                $this->logger->error('Image sideload failed.', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
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
                'url' => $url,
                'attachment_id' => (int) $attachment_id,
                'source' => 'sideload',
            ]);

            update_post_meta($attachment_id, '_awi_source_url', $url);
            $gallery_ids[] = (int) $attachment_id;
            $this->logger->info('Attachment created for image URL.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
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

    private function build_sideload_filename(string $url, string $tmp_file): string
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
            $extension = $this->detect_file_extension($tmp_file);
        }

        return sanitize_file_name($name_without_ext . '.' . $extension);
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

        return $mime_to_extension[strtolower($mime)] ?? 'jpg';
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
}
