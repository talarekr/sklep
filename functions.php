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
    wp_enqueue_style('gp-clone-style', get_stylesheet_uri(), [], '1.2.0');
    wp_enqueue_script('gp-clone-home', get_template_directory_uri() . '/assets/js/home.js', ['jquery'], '1.2.0', true);

    if (class_exists('WooCommerce')) {
        wp_enqueue_style('gp-clone-woo', get_template_directory_uri() . '/assets/css/woocommerce.css', ['gp-clone-style'], '1.2.0');
        wp_enqueue_script('wc-cart-fragments');
    }
});

add_filter('woocommerce_show_page_title', '__return_true');
add_filter('loop_shop_columns', static fn() => 4);
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
    echo '<p class="gp-delivery-note gp-delivery-note--single">Darmowa dostawa: 23–24 kwi jeśli zapłacisz do 14:00</p>';
}, 26);

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
