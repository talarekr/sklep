<?php
/**
 * Custom single product content layout.
 */

defined('ABSPATH') || exit;

global $product;

if (post_password_required()) {
    echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return;
}

if (!$product instanceof WC_Product) {
    return;
}

$availability = $product->get_availability();
$availability_text = $availability['availability'] ?? __('Dostępny', 'gp-clone');
$availability_class = !empty($availability['class']) ? sanitize_html_class($availability['class']) : 'in-stock';
$part_number = function_exists('gp_get_product_part_number') ? gp_get_product_part_number($product) : 'Brak';
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class('gp-product-page', $product); ?>>
    <div class="gp-product-page__breadcrumb">
        <?php woocommerce_breadcrumb(); ?>
    </div>

    <section class="gp-product-page__hero">
        <div class="gp-product-page__gallery">
            <?php do_action('woocommerce_before_single_product_summary'); ?>
        </div>

        <aside class="gp-product-page__summary">
            <header class="gp-purchase-panel__head">
                <h1 class="product_title entry-title"><?php the_title(); ?></h1>
                <ul class="gp-purchase-panel__meta">
                    <li>
                        <span><?php esc_html_e('Numer części:', 'gp-clone'); ?></span>
                        <strong><?php echo esc_html($part_number); ?></strong>
                    </li>
                    <li>
                        <span><?php esc_html_e('Stan:', 'gp-clone'); ?></span>
                        <strong><?php esc_html_e('Używany / sprawdzony', 'gp-clone'); ?></strong>
                    </li>
                    <li>
                        <span><?php esc_html_e('Dostępność:', 'gp-clone'); ?></span>
                        <strong class="<?php echo esc_attr($availability_class); ?>"><?php echo wp_kses_post($availability_text); ?></strong>
                    </li>
                </ul>
            </header>

            <div class="gp-purchase-box">
                <div class="gp-purchase-box__price">
                    <?php woocommerce_template_single_price(); ?>
                </div>
                <div class="gp-purchase-box__cart">
                    <?php woocommerce_template_single_add_to_cart(); ?>
                </div>
                <div class="gp-purchase-box__shipping">
                    <h3><?php esc_html_e('Dostawa i logistyka', 'gp-clone'); ?></h3>
                    <p><?php esc_html_e('Dostawa: 23–24 kwi', 'gp-clone'); ?></p>
                    <p><?php esc_html_e('Wysyłka dziś przy płatności do 14:00', 'gp-clone'); ?></p>
                </div>
            </div>

            <div class="gp-trust-grid">
                <article class="gp-trust-card">
                    <h3><?php esc_html_e('Płatności', 'gp-clone'); ?></h3>
                    <p><?php esc_html_e('BLIK, szybki przelew, karta, przelew tradycyjny.', 'gp-clone'); ?></p>
                </article>
                <article class="gp-trust-card">
                    <h3><?php esc_html_e('Zwroty', 'gp-clone'); ?></h3>
                    <p><?php esc_html_e('Bezpieczny zwrot do 14 dni zgodnie z regulaminem sklepu.', 'gp-clone'); ?></p>
                </article>
                <article class="gp-trust-card">
                    <h3><?php esc_html_e('Obsługa klienta', 'gp-clone'); ?></h3>
                    <p><?php esc_html_e('Pomagamy w dopasowaniu części po numerze VIN / OEM.', 'gp-clone'); ?></p>
                </article>
            </div>
        </aside>
    </section>

    <section class="gp-product-page__details">
        <?php woocommerce_output_product_data_tabs(); ?>
    </section>
</div>
