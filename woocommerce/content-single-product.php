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

$part_number = function_exists('gp_get_product_part_number') ? gp_get_product_part_number($product) : 'Brak';
$delivery_window = gp_get_delivery_text();
$returns_info = __('Zwrot do 14 dni zgodnie z regulaminem.', 'gp-clone');
$is_ovoko_product = function_exists('gp_is_ovoko_product') ? gp_is_ovoko_product($product) : false;
$ovoko_car_id = function_exists('gp_get_ovoko_car_id_for_product') ? gp_get_ovoko_car_id_for_product($product->get_id()) : trim((string) get_post_meta($product->get_id(), '_ovoko_car_id', true));
$same_vehicle_url = function_exists('gp_get_vehicle_parts_url_for_product') ? gp_get_vehicle_parts_url_for_product($product->get_id()) : '';
if ($same_vehicle_url === '' && $ovoko_car_id !== '') {
    $same_vehicle_url = add_query_arg('ovoko_car_id', rawurlencode($ovoko_car_id), get_post_type_archive_link('product'));
}
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class('gp-product-page', $product); ?>>
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
                    <li>
                        <span><?php esc_html_e('Stan:', 'gp-clone'); ?></span>
                        <strong><?php esc_html_e('Używany / sprawdzony', 'gp-clone'); ?></strong>
                    </li>
                </ul>
                <?php if ($is_ovoko_product && $same_vehicle_url !== '') : ?>
                    <div class="gpswiss-same-vehicle-button-wrap">
                        <a class="gpswiss-same-vehicle-button" href="<?php echo esc_url($same_vehicle_url); ?>">
                            <?php esc_html_e('Pokaż więcej części z tego pojazdu', 'gp-clone'); ?>
                        </a>
                    </div>
                <?php elseif (!$is_ovoko_product && $product->get_short_description() !== '') : ?>
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
                <div class="gp-product-trust__item gp-payment-box">
                    <h3><?php esc_html_e('Metody płatności', 'gp-clone'); ?></h3>
                    <img src="/wp-content/uploads/payments.jpg" alt="Metody płatności" class="gp-payments-image">
                </div>
                <div class="gp-product-trust__item">
                    <h3><?php esc_html_e('Zwroty', 'gp-clone'); ?></h3>
                    <p><?php echo esc_html($returns_info); ?></p>
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
