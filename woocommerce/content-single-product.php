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
$sku = $product->get_sku();
$delivery_window = __('Dostawa: 23–24 kwi', 'gp-clone');
$payment_methods = __('BLIK, szybki przelew, karta, przelew tradycyjny.', 'gp-clone');
$returns_info = __('Zwrot do 14 dni zgodnie z regulaminem.', 'gp-clone');
$shipping_from = __('Wysyłka z magazynu GP Swiss.', 'gp-clone');
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class('gp-product-page', $product); ?>>
    <div class="gp-product-page__breadcrumb">
        <?php woocommerce_breadcrumb(); ?>
    </div>

    <section class="gp-product-page__hero">
        <div class="gp-product-page__gallery">
            <?php woocommerce_show_product_images(); ?>
        </div>

        <section class="gp-product-page__info">
            <header class="gp-product-info-card">
                <h1 class="product_title entry-title gp-product-info-card__title"><?php the_title(); ?></h1>
                <ul class="gp-product-info-card__meta">
                    <li>
                        <span><?php esc_html_e('Numer części:', 'gp-clone'); ?></span>
                        <strong><?php echo esc_html($part_number); ?></strong>
                    </li>
                    <?php if ($sku !== '') : ?>
                        <li>
                            <span><?php esc_html_e('SKU:', 'gp-clone'); ?></span>
                            <strong><?php echo esc_html($sku); ?></strong>
                        </li>
                    <?php endif; ?>
                    <li>
                        <span><?php esc_html_e('Stan:', 'gp-clone'); ?></span>
                        <strong><?php esc_html_e('Używany / sprawdzony', 'gp-clone'); ?></strong>
                    </li>
                    <li>
                        <span><?php esc_html_e('Dostępność:', 'gp-clone'); ?></span>
                        <strong class="<?php echo esc_attr($availability_class); ?>"><?php echo wp_kses_post($availability_text); ?></strong>
                    </li>
                </ul>
                <?php if ($product->get_short_description() !== '') : ?>
                    <div class="gp-product-info-card__short-description">
                        <?php echo wp_kses_post(wpautop($product->get_short_description())); ?>
                    </div>
                <?php endif; ?>
            </header>

            <div class="gp-product-trust">
                <div class="gp-product-trust__item">
                    <h3><?php esc_html_e('Czas dostawy', 'gp-clone'); ?></h3>
                    <p><?php echo esc_html($delivery_window); ?></p>
                </div>
                <div class="gp-product-trust__item">
                    <h3><?php esc_html_e('Metody płatności', 'gp-clone'); ?></h3>
                    <p><?php echo esc_html($payment_methods); ?></p>
                </div>
                <div class="gp-product-trust__item">
                    <h3><?php esc_html_e('Zwroty', 'gp-clone'); ?></h3>
                    <p><?php echo esc_html($returns_info); ?></p>
                </div>
                <div class="gp-product-trust__item">
                    <h3><?php esc_html_e('Dostawa od', 'gp-clone'); ?></h3>
                    <p><?php echo esc_html($shipping_from); ?></p>
                </div>
            </div>
        </section>

        <aside class="gp-product-page__purchase">
            <div class="gp-purchase-box">
                <p class="gp-purchase-box__label"><?php esc_html_e('Cena produktu', 'gp-clone'); ?></p>
                <div class="gp-purchase-box__price">
                    <?php woocommerce_template_single_price(); ?>
                    <p class="gp-purchase-box__price-note"><?php esc_html_e('Cena brutto. Najniższa cena z 30 dni dostępna przy finalizacji zamówienia.', 'gp-clone'); ?></p>
                </div>
                <div class="gp-purchase-box__cart" data-gp-single-cart>
                    <?php woocommerce_template_single_add_to_cart(); ?>
                </div>
                <a class="gp-purchase-box__contact" href="<?php echo esc_url(home_url('/kontakt')); ?>">
                    <?php esc_html_e('Masz pytanie? Skontaktuj się', 'gp-clone'); ?>
                </a>
                <p class="gp-purchase-box__helper"><?php esc_html_e('Pomagamy w doborze części po numerze VIN / OEM.', 'gp-clone'); ?></p>
            </div>
        </aside>
    </section>

    <section class="gp-product-page__details">
        <?php woocommerce_output_product_data_tabs(); ?>
    </section>
</div>
