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

function gp_enqueue_fonts(): void
{
    wp_enqueue_style(
        'gp-poppins',
        'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap',
        [],
        null
    );
}
add_action('wp_enqueue_scripts', 'gp_enqueue_fonts');

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('gp-clone-style', get_stylesheet_uri(), ['gp-poppins'], '1.3.3');
    wp_enqueue_script('gp-clone-home', get_template_directory_uri() . '/assets/js/home.js', ['jquery'], '1.3.3', true);
    wp_enqueue_script('gp-clone-language-switcher', get_template_directory_uri() . '/assets/js/language-switcher.js', [], '1.0.0', true);

    if (class_exists('WooCommerce')) {
        wp_enqueue_style('gp-clone-woo', get_template_directory_uri() . '/assets/css/woocommerce.css', ['gp-clone-style'], '1.3.3');
        wp_enqueue_script('wc-cart-fragments');

        if (is_product()) {
            wp_enqueue_script(
                'gp-clone-single-product',
                get_template_directory_uri() . '/assets/js/single-product.js',
                [],
                '1.0.0',
                true
            );
        }
    }
});

add_action('wp_footer', function (): void {
    echo '<div id="gp-google-translate-element" class="gp-google-translate-element" aria-hidden="true"></div>';
});

function gp_get_selected_language(): string
{
    $allowed_languages = ['pl', 'en', 'fr', 'uk', 'de'];
    $lang = isset($_COOKIE['gp_selected_language']) ? sanitize_key(wp_unslash((string) $_COOKIE['gp_selected_language'])) : 'pl';

    if (!in_array($lang, $allowed_languages, true)) {
        return 'pl';
    }

    return $lang;
}

function gp_should_use_eur_currency(): bool
{
    if (is_admin() && !wp_doing_ajax()) {
        return false;
    }

    return gp_get_selected_language() !== 'pl';
}

function gp_fetch_eur_rate_from_nbp(): ?float
{
    $response = wp_remote_get('https://api.nbp.pl/api/exchangerates/rates/A/EUR/?format=json', [
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        return null;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    $rate = isset($decoded['rates'][0]['mid']) ? (float) $decoded['rates'][0]['mid'] : 0.0;

    if ($rate <= 0) {
        return null;
    }

    return $rate;
}

function gp_update_eur_exchange_rate(): void
{
    $rate = gp_fetch_eur_rate_from_nbp();
    if ($rate === null) {
        return;
    }

    update_option('gp_eur_exchange_rate', $rate, false);
    update_option('gp_eur_exchange_rate_updated_at', gmdate('c'), false);
}

add_action('init', function (): void {
    if (!wp_next_scheduled('gp_update_eur_rate_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gp_update_eur_rate_daily');
    }

    if ((float) get_option('gp_eur_exchange_rate', 0) <= 0) {
        gp_update_eur_exchange_rate();
    }
});

add_action('gp_update_eur_rate_daily', 'gp_update_eur_exchange_rate');

add_filter('woocommerce_currency', function (string $currency): string {
    if (!gp_should_use_eur_currency()) {
        return $currency;
    }

    return 'EUR';
});

function gp_convert_price_to_current_currency($price)
{
    if (!gp_should_use_eur_currency()) {
        return $price;
    }

    if ($price === '' || $price === null) {
        return $price;
    }

    $rate = (float) get_option('gp_eur_exchange_rate', 0);
    if ($rate <= 0) {
        return $price;
    }

    $numeric_price = (float) $price;
    $converted = $numeric_price / $rate;

    return (string) round($converted, wc_get_price_decimals());
}

add_filter('woocommerce_product_get_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_product_get_regular_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_product_get_sale_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_product_variation_get_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_product_variation_get_regular_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_product_variation_get_sale_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_variation_prices_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_variation_prices_regular_price', 'gp_convert_price_to_current_currency', 99);
add_filter('woocommerce_variation_prices_sale_price', 'gp_convert_price_to_current_currency', 99);

function gp_extract_brand_from_product_name(string $name): string
{
    $name = trim(wp_strip_all_tags($name));
    if ($name === '') {
        return '';
    }

    $tokens = preg_split('/\s+/u', $name);
    if (!is_array($tokens) || $tokens === []) {
        return '';
    }

    $first_word = preg_replace('/[^\p{L}\p{N}\-]/u', '', (string) ($tokens[0] ?? '')) ?? '';
    if ($first_word === '') {
        return '';
    }

    $normalized = mb_strtolower($first_word);
    return mb_strtoupper(mb_substr($normalized, 0, 1)) . mb_substr($normalized, 1);
}

add_action('init', function (): void {
    register_taxonomy('gp_car_brand', ['product'], [
        'labels' => [
            'name' => __('Marki', 'gp-clone'),
            'singular_name' => __('Marka', 'gp-clone'),
        ],
        'public' => false,
        'show_ui' => true,
        'show_admin_column' => true,
        'hierarchical' => false,
        'rewrite' => false,
        'query_var' => 'brand',
    ]);
}, 11);

function gp_sync_product_brand_term(int $product_id): void
{
    if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
        return;
    }

    $title = get_the_title($product_id);
    if (!is_string($title) || $title === '') {
        return;
    }

    $brand = gp_extract_brand_from_product_name($title);
    if ($brand === '') {
        return;
    }

    wp_set_object_terms($product_id, [$brand], 'gp_car_brand', false);
    update_post_meta($product_id, '_car_brand', $brand);
}

add_action('save_post_product', function (int $post_id, WP_Post $post, bool $update): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    gp_sync_product_brand_term($post_id);
}, 20, 3);

function gp_backfill_missing_product_brands(): void
{
    $last_run = (int) get_transient('gp_brand_backfill_last_run');
    if ($last_run > (time() - HOUR_IN_SECONDS)) {
        return;
    }

    $products = get_posts([
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 150,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => 'gp_car_brand',
                'operator' => 'NOT EXISTS',
            ],
        ],
    ]);

    if (is_array($products)) {
        foreach ($products as $product_id) {
            gp_sync_product_brand_term((int) $product_id);
        }
    }

    set_transient('gp_brand_backfill_last_run', time(), HOUR_IN_SECONDS);
}

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
    remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
    remove_action('woocommerce_shop_loop_header', 'woocommerce_product_taxonomy_archive_header', 10);
    remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
    remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);

    add_action('woocommerce_before_shop_loop', 'gp_shop_loop_toolbar_start', 19);
    add_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
    add_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);
    add_action('woocommerce_before_shop_loop', 'gp_shop_loop_toolbar_end', 31);
}, 20);
add_filter('loop_shop_columns', static fn() => 3);
add_filter('loop_shop_per_page', static fn() => 60);

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

    if (isset($tabs['additional_information'])) {
        $tabs['additional_information']['title'] = __('Informacje dodatkowe', 'gp-clone');
        $tabs['additional_information']['priority'] = 20;
    }

    unset($tabs['reviews'], $tabs['compatibility']);

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

function gp_get_current_product_category_term(): ?WP_Term
{
    if (!is_tax('product_cat')) {
        return null;
    }

    $term = get_queried_object();
    if ($term instanceof WP_Term && $term->taxonomy === 'product_cat') {
        return $term;
    }

    return null;
}

function gp_get_product_cat_children(int $parent_id): array
{
    static $runtime_tree = null;

    if (!is_array($runtime_tree)) {
        $runtime_tree = get_transient('gp_product_cat_tree_v1');
    }

    if (!is_array($runtime_tree)) {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        $runtime_tree = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }

                $term_parent_id = (int) $term->parent;
                if (!isset($runtime_tree[$term_parent_id])) {
                    $runtime_tree[$term_parent_id] = [];
                }

                $runtime_tree[$term_parent_id][] = $term;
            }
        }

        set_transient('gp_product_cat_tree_v1', $runtime_tree, 10 * MINUTE_IN_SECONDS);
    }

    $terms_for_parent = $runtime_tree[$parent_id] ?? [];
    if (!is_array($terms_for_parent)) {
        return [];
    }

    return array_values(array_filter($terms_for_parent, static fn($term) => $term instanceof WP_Term));
}

add_action('created_product_cat', static function (): void {
    delete_transient('gp_product_cat_tree_v1');
});

add_action('edited_product_cat', static function (): void {
    delete_transient('gp_product_cat_tree_v1');
});

add_action('delete_product_cat', static function (): void {
    delete_transient('gp_product_cat_tree_v1');
});

function gp_get_product_category_root_id(array $ancestor_ids, int $current_term_id): int
{
    if ($ancestor_ids === []) {
        return $current_term_id;
    }

    return (int) end($ancestor_ids);
}

function gp_get_product_category_lineage(int $current_term_id, array $ancestor_ids): array
{
    if ($current_term_id <= 0) {
        return [];
    }

    return array_reverse(array_merge([$current_term_id], $ancestor_ids));
}

function gp_is_technical_product_category(WP_Term $term): bool
{
    static $technical_slugs = [
        'motoryzacja',
        'czesci-samochodowe',
    ];
    static $technical_names = [
        'motoryzacja',
        'części samochodowe',
    ];

    if (in_array(sanitize_title($term->slug), $technical_slugs, true)) {
        return true;
    }

    return in_array(mb_strtolower(wp_strip_all_tags((string) $term->name)), $technical_names, true);
}

function gp_get_user_facing_category(?WP_Term $current_term): ?WP_Term
{
    if (!$current_term instanceof WP_Term) {
        return null;
    }

    $current_term_id = (int) $current_term->term_id;
    if ($current_term_id <= 0) {
        return null;
    }

    $ancestor_ids = array_map('intval', get_ancestors($current_term_id, 'product_cat', 'taxonomy'));
    $lineage_ids = array_reverse(array_merge([$current_term_id], $ancestor_ids));

    foreach ($lineage_ids as $lineage_id) {
        $lineage_term = get_term($lineage_id, 'product_cat');
        if (!$lineage_term instanceof WP_Term) {
            continue;
        }

        if (!gp_is_technical_product_category($lineage_term)) {
            return $lineage_term;
        }
    }

    return $current_term;
}

function gp_get_user_facing_root_categories(): array
{
    $resolved = [];
    $queue = gp_get_product_cat_children(0);

    while ($queue !== []) {
        $term = array_shift($queue);
        if (!$term instanceof WP_Term) {
            continue;
        }

        if (gp_is_technical_product_category($term)) {
            $queue = array_merge($queue, gp_get_product_cat_children((int) $term->term_id));
            continue;
        }

        $resolved[] = $term;
    }

    return $resolved;
}

function gp_render_category_links_list(array $categories, int $active_term_id = 0): void
{
    if ($categories === []) {
        return;
    }

    echo '<ul class="gp-cat-filter__list">';
    foreach ($categories as $category) {
        if (!$category instanceof WP_Term) {
            continue;
        }

        $term_link = get_term_link($category);
        if (is_wp_error($term_link)) {
            continue;
        }

        $classes = ['gp-cat-filter__link'];
        if ((int) $category->term_id === $active_term_id) {
            $classes[] = 'is-active';
        }

        echo '<li><a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($term_link) . '">' . esc_html($category->name) . '</a></li>';
    }
    echo '</ul>';
}

function gp_render_category_tree(array $categories, array $lineage_ids, array $query_args = []): void
{
    if ($categories === []) {
        return;
    }

    echo '<ul class="gp-cat-filter__list gp-cat-filter__list--tree">';
    foreach ($categories as $category) {
        if (!$category instanceof WP_Term) {
            continue;
        }

        $term_link = get_term_link($category);
        if (is_wp_error($term_link)) {
            continue;
        }
        if ($query_args !== []) {
            $term_link = add_query_arg($query_args, $term_link);
        }

        $term_id = (int) $category->term_id;
        $is_active = !empty($lineage_ids) && $term_id === (int) end($lineage_ids);
        $is_in_lineage = in_array($term_id, $lineage_ids, true);

        $classes = ['gp-cat-filter__link'];
        if ($is_active) {
            $classes[] = 'is-active';
        } elseif ($is_in_lineage) {
            $classes[] = 'is-parent-active';
        }

        echo '<li>';
        echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($term_link) . '">' . esc_html($category->name) . '</a>';

        $children = gp_get_product_cat_children($term_id);
        if ($children !== [] && $is_in_lineage) {
            gp_render_category_tree($children, $lineage_ids, $query_args);
        }

        echo '</li>';
    }
    echo '</ul>';
}

function gp_render_category_filter_section(string $title, callable $content_renderer, bool $open = true): void
{
    echo '<details class="gp-cat-filter__section"' . ($open ? ' open' : '') . '>';
    echo '<summary class="gp-cat-filter__summary">' . esc_html($title) . '</summary>';
    echo '<div class="gp-cat-filter__section-content">';
    $content_renderer();
    echo '</div>';
    echo '</details>';
}

function gp_render_category_select(array $categories, int $selected_category_id, array $query_args = []): void
{
    echo '<label class="screen-reader-text" for="gp-category-filter-select">' . esc_html__('Kategoria', 'gp-clone') . '</label>';
    echo '<select id="gp-category-filter-select" class="gp-cat-filter__select" data-gp-sidebar-category-select>';
    echo '<option value="0">' . esc_html__('Wybierz kategorię', 'gp-clone') . '</option>';

    foreach ($categories as $category) {
        if (!$category instanceof WP_Term) {
            continue;
        }

        $term_link = get_term_link($category);
        if (is_wp_error($term_link)) {
            continue;
        }

        echo '<option value="' . esc_url($term_link) . '" data-category-id="' . esc_attr((string) $category->term_id) . '"' . selected((int) $category->term_id, $selected_category_id, false) . '>' . esc_html($category->name) . '</option>';
    }

    echo '</select>';
}

function gp_render_brand_select(array $brands, string $selected_brand_slug): void
{
    if ($brands === []) {
        return;
    }

    echo '<label class="screen-reader-text" for="gp-brand-filter-select">' . esc_html__('Marka', 'gp-clone') . '</label>';
    echo '<select id="gp-brand-filter-select" name="brand" class="gp-cat-filter__select">';
    echo '<option value="">' . esc_html__('Wszystkie marki', 'gp-clone') . '</option>';

    foreach ($brands as $brand) {
        if (!$brand instanceof WP_Term) {
            continue;
        }

        echo '<option value="' . esc_attr($brand->slug) . '"' . selected($brand->slug, $selected_brand_slug, false) . '>' . esc_html($brand->name) . '</option>';
    }

    echo '</select>';
}

function gp_build_subcategory_map(array $categories): array
{
    $map = [];
    foreach ($categories as $category) {
        if (!$category instanceof WP_Term) {
            continue;
        }

        $subcategory_items = [];
        foreach (gp_get_product_cat_children((int) $category->term_id) as $child) {
            if (!$child instanceof WP_Term) {
                continue;
            }

            $child_link = get_term_link($child);
            if (is_wp_error($child_link)) {
                continue;
            }

            $subcategory_items[] = [
                'id' => (int) $child->term_id,
                'name' => $child->name,
                'url' => $child_link,
            ];
        }

        $map[(string) $category->term_id] = $subcategory_items;
    }

    return $map;
}

function gp_render_product_category_sidebar(): void
{
    if (!taxonomy_exists('product_cat')) {
        echo '<p class="gp-cat-filter__empty">' . esc_html__('Brak kategorii produktów.', 'gp-clone') . '</p>';
        return;
    }

    $current_term = gp_get_current_product_category_term();
    $current_term_id = $current_term instanceof WP_Term ? (int) $current_term->term_id : 0;
    $ancestor_ids = $current_term_id > 0 ? array_map('intval', get_ancestors($current_term_id, 'product_cat', 'taxonomy')) : [];
    $lineage = gp_get_product_category_lineage($current_term_id, $ancestor_ids);

    $active_category = gp_get_user_facing_category($current_term);
    $active_category_id = $active_category instanceof WP_Term ? (int) $active_category->term_id : 0;
    $category_terms = gp_get_user_facing_root_categories();
    $subcategories = $active_category_id > 0 ? gp_get_product_cat_children($active_category_id) : [];
    $subcategories_map = gp_build_subcategory_map($category_terms);
    $selected_brand_slug = isset($_GET['brand']) ? sanitize_title((string) wp_unslash($_GET['brand'])) : '';
    $selected_price_min = isset($_GET['price_min']) ? wc_clean(wp_unslash((string) $_GET['price_min'])) : '';
    $selected_price_max = isset($_GET['price_max']) ? wc_clean(wp_unslash((string) $_GET['price_max'])) : '';
    $persistent_query_args = [];
    if ($selected_brand_slug !== '') {
        $persistent_query_args['brand'] = $selected_brand_slug;
    }
    if ($selected_price_min !== '') {
        $persistent_query_args['price_min'] = $selected_price_min;
    }
    if ($selected_price_max !== '') {
        $persistent_query_args['price_max'] = $selected_price_max;
    }
    $brand_terms = [];
    if (taxonomy_exists('gp_car_brand')) {
        $brand_term_query = get_terms([
            'taxonomy' => 'gp_car_brand',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (!is_wp_error($brand_term_query) && is_array($brand_term_query)) {
            $brand_terms = array_values(array_filter($brand_term_query, static function ($term): bool {
                return $term instanceof WP_Term;
            }));
        }
    }

    if ($category_terms === []) {
        echo '<p class="gp-cat-filter__empty">' . esc_html__('Brak kategorii produktów.', 'gp-clone') . '</p>';
        return;
    }

    echo '<div class="gp-cat-filter">';
    echo '<form method="get" class="gp-cat-filter__form" data-gp-sidebar-filter-form>';

    if (function_exists('gp_render_brand_select') && $brand_terms !== []) {
        gp_render_category_filter_section(__('Marka', 'gp-clone'), static function () use ($brand_terms, $selected_brand_slug): void {
            gp_render_brand_select($brand_terms, $selected_brand_slug);
        });
    }

    gp_render_category_filter_section(__('Kategoria', 'gp-clone'), static function () use ($category_terms, $active_category_id, $persistent_query_args): void {
        gp_render_category_select($category_terms, $active_category_id, $persistent_query_args);
    });

    gp_render_category_filter_section(__('Podkategorie', 'gp-clone'), static function () use ($subcategories, $lineage, $subcategories_map, $active_category_id, $persistent_query_args): void {
        echo '<div class="gp-cat-filter__subcategory-list" data-gp-subcategory-list data-gp-subcategory-map="' . esc_attr(wp_json_encode($subcategories_map)) . '" data-gp-active-category-id="' . esc_attr((string) $active_category_id) . '">';

        if ($subcategories !== []) {
            gp_render_category_tree($subcategories, $lineage, $persistent_query_args);
        } else {
            echo '<p class="gp-cat-filter__empty">' . esc_html__('Wybierz kategorię, aby zobaczyć podkategorie.', 'gp-clone') . '</p>';
        }

        echo '</div>';
        echo '<button type="button" class="gp-cat-filter__more" data-gp-subcategory-more hidden>' . esc_html__('Wyświetl więcej', 'gp-clone') . '</button>';
    }, $current_term_id > 0);

    gp_render_category_filter_section(__('Cena', 'gp-clone'), static function () use ($selected_price_min, $selected_price_max): void {
        echo '<div class="gp-cat-filter__price-row">';
        echo '<input type="number" min="0" step="1" name="price_min" class="gp-cat-filter__price-input" placeholder="' . esc_attr__('Cena od', 'gp-clone') . '" value="' . esc_attr($selected_price_min) . '">';
        echo '<input type="number" min="0" step="1" name="price_max" class="gp-cat-filter__price-input" placeholder="' . esc_attr__('Cena do', 'gp-clone') . '" value="' . esc_attr($selected_price_max) . '">';
        echo '</div>';
        echo '<button type="submit" class="gp-cat-filter__apply">' . esc_html__('Filtruj', 'gp-clone') . '</button>';
    }, true);

    echo '</form>';
    echo '</div>';
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
        || (is_post_type_archive('product') && !is_shop());
}

function gp_normalize_part_number(string $value): string
{
    $value = mb_strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]/u', '', $value) ?? '';
    return trim($value);
}

function gp_find_product_id_by_part_number(string $part_number_raw): int
{
    global $wpdb;

    $raw = sanitize_text_field($part_number_raw);
    $normalized = gp_normalize_part_number($raw);
    if ($raw === '' && $normalized === '') {
        return 0;
    }

    $raw_like = $raw !== '' ? '%' . $wpdb->esc_like($raw) . '%' : '';
    $normalized_like = $normalized !== '' ? '%' . $wpdb->esc_like($normalized) . '%' : '';

    $sql_conditions = [];
    if ($raw_like !== '') {
        $sql_conditions[] = $wpdb->prepare('pm.meta_value = %s', $raw);
        $sql_conditions[] = $wpdb->prepare('pm.meta_value LIKE %s', $raw_like);
    }
    if ($normalized_like !== '') {
        $sql_conditions[] = $wpdb->prepare("REPLACE(REPLACE(UPPER(pm.meta_value), ' ', ''), '-', '') = %s", $normalized);
        $sql_conditions[] = $wpdb->prepare("REPLACE(REPLACE(UPPER(pm.meta_value), ' ', ''), '-', '') LIKE %s", $normalized_like);
    }

    if ($sql_conditions === []) {
        return 0;
    }

    $product_id = (int) $wpdb->get_var(
        "SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
        WHERE p.post_type = 'product'
          AND p.post_status = 'publish'
          AND pm.meta_key = '_part_number'
          AND (" . implode(' OR ', $sql_conditions) . ")
        ORDER BY p.ID ASC
        LIMIT 1"
    );

    return $product_id > 0 ? $product_id : 0;
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
    $query->set('tax_query', []);
    $query->set('product_cat', '');
}, 20);

add_action('pre_get_posts', function (WP_Query $query): void {
    if (is_admin() || !$query->is_main_query() || !class_exists('WooCommerce')) {
        return;
    }

    if (!is_shop() && !$query->is_post_type_archive('product') && !is_tax('product_cat')) {
        return;
    }

    if ($query->get('part_number_search_active')) {
        return;
    }

    $selected_brand = isset($_GET['brand']) ? sanitize_title((string) wp_unslash($_GET['brand'])) : '';
    $price_min_raw = isset($_GET['price_min']) ? wc_clean(wp_unslash((string) $_GET['price_min'])) : '';
    $price_max_raw = isset($_GET['price_max']) ? wc_clean(wp_unslash((string) $_GET['price_max'])) : '';
    $price_min = is_numeric($price_min_raw) ? (float) $price_min_raw : null;
    $price_max = is_numeric($price_max_raw) ? (float) $price_max_raw : null;

    if ($selected_brand !== '') {
        $tax_query = $query->get('tax_query');
        if (!is_array($tax_query)) {
            $tax_query = [];
        }

        $tax_query[] = [
            'taxonomy' => 'gp_car_brand',
            'field' => 'slug',
            'terms' => [$selected_brand],
        ];
        $query->set('tax_query', $tax_query);
    }

    if ($price_min !== null || $price_max !== null) {
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = [];
        }

        $range = ['key' => '_price', 'type' => 'DECIMAL'];
        if ($price_min !== null && $price_max !== null) {
            $range['value'] = [$price_min, $price_max];
            $range['compare'] = 'BETWEEN';
        } elseif ($price_min !== null) {
            $range['value'] = $price_min;
            $range['compare'] = '>=';
        } else {
            $range['value'] = $price_max;
            $range['compare'] = '<=';
        }

        $meta_query[] = $range;
        $query->set('meta_query', $meta_query);
    }
}, 25);

add_action('template_redirect', function (): void {
    if (is_admin() || !class_exists('WooCommerce') || is_singular('product')) {
        return;
    }

    if (!is_shop() && !is_tax('product_cat') && !is_post_type_archive('product')) {
        return;
    }

    $part_number_raw = isset($_GET['part_number']) ? sanitize_text_field((string) wp_unslash($_GET['part_number'])) : '';
    if ($part_number_raw === '') {
        return;
    }

    $product_id = gp_find_product_id_by_part_number($part_number_raw);
    if ($product_id <= 0) {
        return;
    }

    $product_url = get_permalink($product_id);
    if (!is_string($product_url) || $product_url === '') {
        return;
    }

    wp_safe_redirect($product_url);
    exit;
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
