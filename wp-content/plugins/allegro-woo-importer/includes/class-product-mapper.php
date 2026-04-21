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
        $images = $offer['images'] ?? [];
        $image_urls = [];

        if (is_array($images)) {
            foreach ($images as $image) {
                if (is_array($image) && !empty($image['url'])) {
                    $url = esc_url_raw((string) $image['url']);
                    if ($url !== '') {
                        $image_urls[] = $url;
                    }
                    continue;
                }

                if (is_string($image)) {
                    $url = esc_url_raw($image);
                    if ($url !== '') {
                        $image_urls[] = $url;
                    }
                }
            }
        }

        if (empty($image_urls)) {
            $image_urls = $this->extract_image_urls($offer);
        }

        $image_urls = array_values(array_unique(array_filter($image_urls)));
        $this->logger->info('Normalized image urls count.', ['offer_id' => $offer_id, 'count' => count($image_urls)]);
        if (empty($image_urls)) {
            $this->logger->warning('Offer has no images to sync.', ['offer_id' => $offer_id, 'product_id' => $product->get_id(), 'checked_fields' => ['images', 'productSet[*].product.images']]);
            return;
        }

        $product_id = $product->get_id();
        if ($product_id <= 0) {
            $this->logger->error('Cannot sync images for unsaved product.', ['offer_id' => $offer_id]);
            return;
        }

        $this->logger->info('Starting product image sync.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'images_found' => count($image_urls),
        ]);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $gallery_ids = [];

        foreach ($image_urls as $url) {
            $this->logger->info('Image URL found for offer.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url]);

            $existing_attachment_id = $this->find_existing_attachment_by_source($url);
            if ($existing_attachment_id > 0) {
                $this->logger->info('Reusing existing attachment for image URL.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => $existing_attachment_id]);
                $gallery_ids[] = $existing_attachment_id;
                continue;
            }

            $this->logger->info('Starting image download.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url]);
            $tmp = $this->download_image_to_temp($url);
            if (is_wp_error($tmp)) {
                $this->logger->error('Image download failed.', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'url' => $url,
                    'error_code' => $tmp->get_error_code(),
                    'error_message' => $tmp->get_error_message(),
                    'error_data' => $tmp->get_error_data(),
                ]);
                continue;
            }

            $this->logger->info('Image downloaded to temporary file.', ['url' => $url, 'tmp' => $tmp]);

            $file = [
                'name' => $this->build_image_filename_from_url($url),
                'tmp_name' => $tmp,
            ];

            $attachment_id = media_handle_sideload($file, $product_id);
            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                $this->logger->error('Image sideload failed.', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'url' => $url,
                    'file' => $file,
                    'error_code' => $attachment_id->get_error_code(),
                    'error_message' => $attachment_id->get_error_message(),
                    'error_data' => $attachment_id->get_error_data(),
                ]);
                continue;
            }
            $this->logger->info('media_handle_sideload succeeded.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'url' => $url,
                'attachment_id' => (int) $attachment_id,
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

        $this->logger->info('Product image sync completed.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'featured_attachment_id' => $featured_id,
            'gallery_count' => count($gallery_only),
            'thumbnail_set' => $featured_id > 0,
        ]);
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

    private function extract_image_urls(array $offer): array
    {
        $urls = [];

        $direct_images = $offer['images'] ?? [];
        if (is_array($direct_images)) {
            foreach ($direct_images as $image) {
                $url = $this->normalize_image_url($image);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $product_set = $offer['productSet'] ?? [];
        if (is_array($product_set)) {
            foreach ($product_set as $product_set_entry) {
                $product_images = $product_set_entry['product']['images'] ?? [];
                if (!is_array($product_images)) {
                    continue;
                }

                foreach ($product_images as $product_image) {
                    $url = $this->normalize_image_url($product_image);
                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function build_image_filename_from_url(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = sanitize_file_name(wp_basename($path));
        if ($basename === '' || $basename === '.' || $basename === '/') {
            return 'allegro-image-' . md5($url) . '.jpg';
        }

        if (!preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $basename)) {
            $basename .= '.jpg';
        }

        return $basename;
    }

    private function download_image_to_temp(string $url)
    {
        $tmp = download_url($url, 30);
        if (!is_wp_error($tmp)) {
            $this->logger->info('download_url succeeded.', ['url' => $url, 'tmp' => $tmp]);
            return $tmp;
        }

        $this->logger->warning('download_url failed, trying HTTP API fallback.', [
            'url' => $url,
            'error_code' => $tmp->get_error_code(),
            'error_message' => $tmp->get_error_message(),
        ]);

        $response = wp_remote_get($url, [
            'timeout' => 45,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => 'WordPress/Allegro-Woo-Importer',
                'Accept' => 'image/*,*/*;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('wp_remote_get fallback failed.', [
                'url' => $url,
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
            ]);
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status > 299) {
            $this->logger->warning('wp_remote_get fallback returned non-2xx.', ['url' => $url, 'status' => $status]);
            return new \WP_Error('awi_image_http_error', 'Image HTTP request failed.', ['status' => $status]);
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return new \WP_Error('awi_image_empty_body', 'Image response body is empty.');
        }

        $tmp_path = wp_tempnam($this->build_image_filename_from_url($url));
        if ($tmp_path === false || $tmp_path === '') {
            return new \WP_Error('awi_image_tempnam_failed', 'Could not create temporary file for image.');
        }

        $written = file_put_contents($tmp_path, $body);
        if ($written === false) {
            @unlink($tmp_path);
            return new \WP_Error('awi_image_write_failed', 'Could not write downloaded image to temporary file.');
        }

        $this->logger->info('wp_remote_get fallback succeeded.', ['url' => $url, 'status' => $status, 'tmp' => $tmp_path, 'bytes' => $written]);

        return $tmp_path;
    }

    private function normalize_image_url($image): string
    {
        if (is_string($image)) {
            return esc_url_raw($image);
        }

        if (is_array($image) && isset($image['url'])) {
            return esc_url_raw((string) $image['url']);
        }

        return '';
    }
}
