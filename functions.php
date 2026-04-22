<?php
/**
 * Theme bootstrap for Global Parts Clone.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');

    register_nav_menus([
        'top_bar' => __('Top bar menu', 'gp-clone'),
        'footer_1' => __('Footer menu 1', 'gp-clone'),
        'footer_2' => __('Footer menu 2', 'gp-clone'),
    ]);
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'gp-clone-inter-font',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        [],
        null
    );
    wp_enqueue_style('gp-clone-style', get_stylesheet_uri(), [], '1.3.3');
    wp_enqueue_script('gp-clone-home', get_template_directory_uri() . '/assets/js/home.js', ['jquery'], '1.3.3', true);

    if (class_exists('WooCommerce')) {
        wp_enqueue_style('gp-clone-woo', get_template_directory_uri() . '/assets/css/woocommerce.css', ['gp-clone-style'], '1.3.3');
        wp_enqueue_script('wc-cart-fragments');
    }
});

add_action('wp_head', function (): void {
    $favicon_url = get_template_directory_uri() . '/assets/images/favicon.png';
    echo '<link rel="icon" type="image/png" href="' . esc_url($favicon_url) . '" />';
    echo '<link rel="apple-touch-icon" href="' . esc_url($favicon_url) . '" />';
}, 1);

add_filter('woocommerce_show_page_title', '__return_false');

add_action('after_switch_theme', function (): void {
    if (get_page_by_path('kontakt', OBJECT, 'page') instanceof WP_Post) {
        return;
    }

    wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Kontakt',
        'post_name' => 'kontakt',
        'post_content' => '',
    ]);
});

add_action('admin_post_nopriv_gp_contact_form', 'gp_handle_contact_form_submit');
add_action('admin_post_gp_contact_form', 'gp_handle_contact_form_submit');

function gp_handle_contact_form_submit(): void
{
    $redirect_url = home_url('/kontakt/');
    if (!empty($_POST['_wp_http_referer'])) {
        $redirect_url = esc_url_raw(wp_unslash((string) $_POST['_wp_http_referer']));
    }

    if (!isset($_POST['gp_contact_nonce']) || !wp_verify_nonce((string) $_POST['gp_contact_nonce'], 'gp_contact_form')) {
        wp_safe_redirect(add_query_arg('contact_status', 'nonce_error', $redirect_url));
        exit;
    }

    $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
    $email = sanitize_email((string) ($_POST['email'] ?? ''));
    $message = sanitize_textarea_field((string) ($_POST['message'] ?? ''));

    if ($name === '' || $email === '' || $message === '' || !is_email($email)) {
        wp_safe_redirect(add_query_arg('contact_status', 'validation_error', $redirect_url));
        exit;
    }

    $subject = sprintf('Formularz kontaktowy GP Swiss - %s', $name);
    $body = "Imię i nazwisko: {$name}\n";
    $body .= "E-mail: {$email}\n\n";
    $body .= "Wiadomość:\n{$message}\n";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $name . ' <' . $email . '>',
    ];

    $sent = wp_mail('biuro@gpswiss.pl', $subject, $body, $headers);

    wp_safe_redirect(add_query_arg('contact_status', $sent ? 'sent' : 'send_error', $redirect_url));
    exit;
}

function gp_shop_loop_toolbar_start(): void
{
    if (!is_shop() && !is_tax('product_cat')) {
        return;
    }

    echo '<div class="gp-shop-toolbar" aria-label="' . esc_attr__('Opcje listy produktów', 'gp-clone') . '">';
}

function gp_shop_loop_toolbar_end(): void
{
    if (!is_shop() && !is_tax('product_cat')) {
        return;
    }

    echo '</div>';
}

add_action('wp', function (): void {
    remove_action('woocommerce_shop_loop_header', 'woocommerce_product_taxonomy_archive_header', 10);
    remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
    remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);

    add_action('woocommerce_before_shop_loop', 'gp_shop_loop_toolbar_start', 19);
    add_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
    add_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);
    add_action('woocommerce_before_shop_loop', 'gp_shop_loop_toolbar_end', 31);
}, 20);
add_filter('loop_shop_columns', static fn() => 3);
add_filter('loop_shop_per_page', static fn() => 20);

add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    ob_start();
    ?>
    <span class="gp-mini-cart-count"><?php echo absint(WC()->cart ? WC()->cart->get_cart_contents_count() : 0); ?></span>
    <?php
    $fragments['span.gp-mini-cart-count'] = ob_get_clean();

    return $fragments;
});

add_action('woocommerce_after_shop_loop_item_title', function () {
    echo '<p class="gp-delivery-note product-shipping">Darmowa dostawa: 23–24 kwi</p><p class="gp-delivery-note product-shipping-sub">Jeśli zapłacisz do 14:00</p>';
}, 15);

add_action('woocommerce_single_product_summary', function () {
    echo '<p class="gp-delivery-note gp-delivery-note--single product-shipping">Darmowa dostawa: 23–24 kwi</p><p class="gp-delivery-note gp-delivery-note--single product-shipping-sub">Jeśli zapłacisz do 14:00</p>';
}, 26);

add_filter('woocommerce_get_breadcrumb', function (array $crumbs): array {
    if (!is_product()) {
        return $crumbs;
    }

    return array_values(array_filter($crumbs, static function ($crumb): bool {
        $label = isset($crumb[0]) ? (string) $crumb[0] : '';
        return stripos($label, 'Allegro ') !== 0;
    }));
}, 20);

add_filter('woocommerce_product_tabs', function (array $tabs): array {
    if (isset($tabs['description'])) {
        $tabs['description']['title'] = __('Opis', 'gp-clone');
        $tabs['description']['priority'] = 10;
    }

    $tabs['compatibility'] = [
        'title' => __('Kompatybilność pojazdu', 'gp-clone'),
        'priority' => 20,
        'callback' => 'gp_product_tab_compatibility',
    ];
    $tabs['warranty'] = [
        'title' => __('Gwarancja', 'gp-clone'),
        'priority' => 30,
        'callback' => 'gp_product_tab_warranty',
    ];
    $tabs['seller'] = [
        'title' => __('O sprzedającym', 'gp-clone'),
        'priority' => 40,
        'callback' => 'gp_product_tab_seller',
    ];

    return $tabs;
});

function gp_get_product_category_term_map(): array
{
    static $term_map = null;

    if (is_array($term_map)) {
        return $term_map;
    }

    $all_terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (!is_array($all_terms)) {
        $term_map = [];
        return $term_map;
    }

    $term_map = [];
    foreach ($all_terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $parent_id = (int) $term->parent;
        if (!isset($term_map[$parent_id])) {
            $term_map[$parent_id] = [];
        }

        $term_map[$parent_id][] = $term;
    }

    return $term_map;
}

function gp_get_product_category_tree(int $parent_term_id = 0): array
{
    $term_map = gp_get_product_category_term_map();
    return $term_map[$parent_term_id] ?? [];
}

function gp_render_product_category_tree(int $parent_term_id = 0, int $current_term_id = 0, array $active_path_ids = []): void
{
    $terms = gp_get_product_category_tree($parent_term_id);
    if ($terms === []) {
        if ($parent_term_id === 0) {
            echo '<p class="gp-cat-tree__empty">' . esc_html__('Brak kategorii produktów.', 'gp-clone') . '</p>';
        }
        return;
    }

    echo '<ul class="gp-cat-tree__level gp-cat-tree__level--' . esc_attr((string) $parent_term_id) . '">';

    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $term_id = (int) $term->term_id;
        $is_current = $term_id === $current_term_id;
        $is_active = in_array($term_id, $active_path_ids, true);
        $child_terms = gp_get_product_category_tree($term_id);
        $has_children = $child_terms !== [];
        $is_expanded = $is_current || $is_active;
        $term_link = get_term_link($term);
        if (is_wp_error($term_link)) {
            continue;
        }

        $item_classes = ['gp-cat-tree__item'];
        if ($is_current) {
            $item_classes[] = 'is-current';
        } elseif ($is_active) {
            $item_classes[] = 'is-active-path';
        }

        echo '<li class="' . esc_attr(implode(' ', $item_classes)) . '">';

        if ($has_children) {
            echo '<details class="gp-cat-tree__details"' . ($is_expanded ? ' open' : '') . '>';
            echo '<summary class="gp-cat-tree__summary">';
            echo '<a class="gp-cat-tree__link" href="' . esc_url($term_link) . '">' . esc_html($term->name) . '</a>';
            echo '</summary>';
            gp_render_product_category_tree($term_id, $current_term_id, $active_path_ids);
            echo '</details>';
        } else {
            echo '<a class="gp-cat-tree__link" href="' . esc_url($term_link) . '">' . esc_html($term->name) . '</a>';
        }

        echo '</li>';
    }

    echo '</ul>';
}

function gp_render_product_category_sidebar(): void
{
    if (!taxonomy_exists('product_cat')) {
        echo '<p class="gp-cat-tree__empty">' . esc_html__('Brak kategorii produktów.', 'gp-clone') . '</p>';
        return;
    }

    $current_term_id = 0;
    if (is_tax('product_cat')) {
        $current_term = get_queried_object();
        if ($current_term instanceof WP_Term && $current_term->taxonomy === 'product_cat') {
            $current_term_id = (int) $current_term->term_id;
        }
    }

    $active_path_ids = [];
    if ($current_term_id > 0) {
        $ancestor_ids = get_ancestors($current_term_id, 'product_cat', 'taxonomy');
        if (is_array($ancestor_ids)) {
            $active_path_ids = array_values(array_unique(array_map('intval', $ancestor_ids)));
        }
    }

    ob_start();
    gp_render_product_category_tree(0, $current_term_id, $active_path_ids);
    $tree_markup = trim((string) ob_get_clean());

    if ($tree_markup !== '') {
        echo $tree_markup;
        return;
    }

    $fallback_terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'parent' => 0,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (!is_array($fallback_terms) || $fallback_terms === []) {
        echo '<p class="gp-cat-tree__empty">' . esc_html__('Brak kategorii produktów.', 'gp-clone') . '</p>';
        return;
    }

    echo '<ul class="gp-cat-tree__level gp-cat-tree__level--fallback">';
    foreach ($fallback_terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $term_link = get_term_link($term);
        if (is_wp_error($term_link)) {
            continue;
        }

        $classes = ['gp-cat-tree__item'];
        if ((int) $term->term_id === $current_term_id) {
            $classes[] = 'is-current';
        }

        echo '<li class="' . esc_attr(implode(' ', $classes)) . '">';
        echo '<a class="gp-cat-tree__link" href="' . esc_url($term_link) . '">' . esc_html($term->name) . '</a>';
        echo '</li>';
    }
    echo '</ul>';
}

function gp_product_tab_compatibility(): void
{
    global $product;
    if (!$product instanceof WC_Product) {
        return;
    }

    $raw = get_post_meta($product->get_id(), '_allegro_parameters', true);
    $params = json_decode((string) $raw, true);
    $matched = [];
    if (is_array($params)) {
        foreach ($params as $param) {
            $name = mb_strtolower((string) ($param['name'] ?? ''));
            if (str_contains($name, 'model') || str_contains($name, 'pojazd') || str_contains($name, 'marka')) {
                $values = array_filter(array_map('sanitize_text_field', (array) ($param['values'] ?? [])));
                if ($values !== []) {
                    $matched[] = '<li><strong>' . esc_html((string) ($param['name'] ?? 'Parametr')) . ':</strong> ' . esc_html(implode(', ', $values)) . '</li>';
                }
            }
        }
    }

    if ($matched === []) {
        echo '<p>' . esc_html__('Brak pełnych danych kompatybilności dla tego produktu. Skontaktuj się z nami i podaj VIN/OEM, aby potwierdzić dopasowanie.', 'gp-clone') . '</p>';
        return;
    }

    echo '<ul>' . wp_kses_post(implode('', $matched)) . '</ul>';
}

function gp_product_tab_warranty(): void
{
    echo '<p>' . esc_html__('Produkt objęty jest gwarancją rozruchową. Szczegóły okresu i warunków gwarancji przekazujemy w opisie oferty oraz przy potwierdzeniu zamówienia.', 'gp-clone') . '</p>';
}

function gp_product_tab_seller(): void
{
    echo '<p>' . esc_html__('Global Parts / GP Swiss - wyspecjalizowany sklep z częściami samochodowymi. Oferujemy wsparcie w doborze części po numerze OEM i szybki kontakt z działem sprzedaży.', 'gp-clone') . '</p>';
}

function gp_get_product_part_number($product): string
{
    static $logged_product_ids = [];

    $product_id = 0;
    if ($product instanceof WC_Product) {
        $product_id = $product->get_id();
    } elseif (is_numeric($product)) {
        $product_id = (int) $product;
    }

    if ($product_id <= 0) {
        return 'Brak';
    }

    $part_number = sanitize_text_field((string) get_post_meta($product_id, '_part_number', true));
    $resolved_part_number = $part_number === '' ? 'Brak' : $part_number;

    if (!isset($logged_product_ids[$product_id]) && class_exists('AWI\Logger')) {
        $logged_product_ids[$product_id] = true;
        $logger = new AWI\Logger();
        $logger->info('Frontend part number read from product meta.', [
            'product_id' => $product_id,
            'meta_key' => '_part_number',
            'raw_meta_value' => $part_number,
            'resolved_value' => $resolved_part_number,
        ]);
    }

    return $resolved_part_number;
}

/**
 * Demo products fallback for homepage section.
 */
function gp_clone_demo_popular_products(): array
{
    return [
        [
            'image' => 'https://images.unsplash.com/photo-1487754180451-c456f719a1fc?auto=format&fit=crop&w=600&q=80',
            'sku' => 'OEM: 11002463585',
            'name' => 'Silnik BMW 3.0D M57N2 286KM kompletny z osprzętem E60 E61 E65 X5 E70 numer części 11002463585',
            'price' => '7 999,00 zł',
            'old_price' => '9 399,00 zł',
            'discount' => '-15%',
            'delivery' => 'Darmowa dostawa: 22–23 kwi jeśli zapłacisz do 14:00',
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1635774855536-972e8f261024?auto=format&fit=crop&w=600&q=80',
            'sku' => 'OEM: A6510308901',
            'name' => 'Skrzynia biegów automatyczna Mercedes W212 2.2 CDI 7G-Tronic 722.9 A6510308901 po regeneracji',
            'price' => '5 490,00 zł',
            'old_price' => '5 699,00 zł',
            'discount' => '-4%',
            'delivery' => 'Dostawa: 22 kwi jeśli zapłacisz do 14:00',
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1615906655593-ad0386982a0f?auto=format&fit=crop&w=600&q=80',
            'sku' => 'OEM: 0CK300041K',
            'name' => 'Dyferencjał tylny Audi Q5 FY 2.0 TDI quattro 0CK300041K z przebiegiem 68 tys. km, gwarancja 90 dni',
            'price' => '3 199,00 zł',
            'old_price' => '',
            'discount' => '',
            'delivery' => 'Darmowa dostawa: 22–23 kwi jeśli zapłacisz do 14:00',
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1558537348-c0f8e733989d?auto=format&fit=crop&w=600&q=80',
            'sku' => 'OEM: 5Q0419091AQ',
            'name' => 'Maglownica Volkswagen Golf VII 1.6 TDI 5Q0419091AQ elektryczna przekładnia kierownicza OE',
            'price' => '1 249,00 zł',
            'old_price' => '1 499,00 zł',
            'discount' => '-17%',
            'delivery' => 'Dostawa: 22 kwi jeśli zapłacisz do 14:00',
        ],
    ];
}

/**
 * Formats product name for minimalist product cards:
 * - vehicle brand/model prefix in uppercase
 * - part name section in sentence case (no full caps lock)
 */
function gp_format_product_display_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name == '') {
        return '';
    }

    $tokens = preg_split('/\s+/u', $name) ?: [];
    if ($tokens === []) {
        return $name;
    }

    $part_keywords = [
        'silnik', 'zestaw', 'komplet', 'ślizg', 'dyferencjał', 'skrzynia', 'maglownica', 'lampa',
        'zderzaka', 'zderzak', 'wtryskiwaczy', 'wtryskowy', 'wtryskowa', 'fotel', 'fotele', 'listwa',
        'pompa', 'chłodnica', 'alternator', 'sprężarka', 'turbina', 'wahacz', 'amortyzator', 'błotnik',
    ];

    $split_at = null;
    foreach ($tokens as $index => $token) {
        $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}\-]/u', '', $token) ?? $token);
        if (in_array($normalized, $part_keywords, true)) {
            $split_at = $index;
            break;
        }
    }

    if ($split_at === null) {
        foreach ($tokens as $index => $token) {
            if (preg_match('/^(19|20)\d{2}$/', $token) === 1) {
                $split_at = $index + 1;
                break;
            }
        }
    }

    if ($split_at === null || $split_at <= 0 || $split_at >= count($tokens)) {
        $normalized = mb_strtolower($name);
        return mb_strtoupper(mb_substr($normalized, 0, 1)) . mb_substr($normalized, 1);
    }

    $vehicle_prefix = implode(' ', array_slice($tokens, 0, $split_at));
    $part_suffix = implode(' ', array_slice($tokens, $split_at));

    $vehicle_prefix = mb_strtoupper($vehicle_prefix);
    $part_suffix = mb_strtolower($part_suffix);
    $part_suffix = mb_strtoupper(mb_substr($part_suffix, 0, 1)) . mb_substr($part_suffix, 1);

    return trim($vehicle_prefix . ' ' . $part_suffix);
}

function gp_should_render_part_number_search_box(): bool
{
    if (!class_exists('WooCommerce')) {
        return false;
    }

    if (function_exists('is_cart') && is_cart()) {
        return false;
    }

    if (function_exists('is_checkout') && is_checkout()) {
        return false;
    }

    return is_front_page()
        || is_shop()
        || is_post_type_archive('product')
        || is_tax('product_cat');
}

function gp_normalize_part_number(string $value): string
{
    $value = mb_strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]/u', '', $value) ?? '';
    return trim($value);
}

add_action('wp_footer', function (): void {
    if (!gp_should_render_part_number_search_box()) {
        return;
    }

    get_template_part('template-parts/shared/part-number-search-box');
}, 20);

add_action('pre_get_posts', function (WP_Query $query): void {
    if (is_admin() || !$query->is_main_query() || !class_exists('WooCommerce')) {
        return;
    }

    $part_number_raw = isset($_GET['part_number']) ? sanitize_text_field((string) wp_unslash($_GET['part_number'])) : '';
    if ($part_number_raw === '') {
        return;
    }

    if (
        !is_shop()
        && !$query->is_post_type_archive('product')
        && !is_tax('product_cat')
    ) {
        return;
    }

    $query->set('post_type', 'product');
    $query->set('part_number_search_active', true);
    $query->set('part_number_search_raw', $part_number_raw);
    $query->set('part_number_search_normalized', gp_normalize_part_number($part_number_raw));
}, 20);

add_filter('posts_where', function (string $where, WP_Query $query): string {
    if (!($query->get('part_number_search_active'))) {
        return $where;
    }

    global $wpdb;

    $raw = sanitize_text_field((string) $query->get('part_number_search_raw'));
    $normalized = sanitize_text_field((string) $query->get('part_number_search_normalized'));

    if ($raw === '' && $normalized === '') {
        return $where;
    }

    $raw_like = $raw !== '' ? '%' . $wpdb->esc_like($raw) . '%' : '';
    $normalized_like = $normalized !== '' ? '%' . $wpdb->esc_like($normalized) . '%' : '';

    $conditions = [];
    if ($raw_like !== '') {
        $conditions[] = $wpdb->prepare('pm.meta_value LIKE %s', $raw_like);
    }
    if ($normalized_like !== '') {
        $conditions[] = $wpdb->prepare("REPLACE(REPLACE(UPPER(pm.meta_value), ' ', ''), '-', '') LIKE %s", $normalized_like);
    }

    if ($conditions === []) {
        return $where;
    }

    $where .= ' AND EXISTS (
        SELECT 1
        FROM ' . $wpdb->postmeta . " pm
        WHERE pm.post_id = {$wpdb->posts}.ID
          AND pm.meta_key = '_part_number'
          AND (" . implode(' OR ', $conditions) . ')
    )';

    return $where;
}, 20, 2);
