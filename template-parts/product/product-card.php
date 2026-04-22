<?php
/**
 * Shared product card markup (homepage + WooCommerce archives).
 *
 * @var array $args
 */

if (!defined('ABSPATH')) {
    exit;
}

$product = $args['product'] ?? null;
if (!$product instanceof WC_Product) {
    return;
}

$wrapper_classes = trim((string) ($args['wrapper_classes'] ?? ''));
$wrapper_class_attr = $wrapper_classes !== '' ? ' ' . $wrapper_classes : '';
$product_image_id = class_exists('\\AWI\\Plugin') ? \AWI\Plugin::get_listing_image_id_for_product((int) $product->get_id()) : 0;
?>
<article class="gp-product product-card<?php echo esc_attr($wrapper_class_attr); ?>">
    <button type="button" class="gp-product__fav product-wishlist" aria-label="<?php echo esc_attr(sprintf(__('Dodaj %s do obserwowanych', 'gp-clone'), $product->get_name())); ?>">
        &#9825;
    </button>
    <a class="product-image" href="<?php echo esc_url(get_permalink($product->get_id())); ?>" aria-label="<?php echo esc_attr($product->get_name()); ?>">
        <?php
        if ($product_image_id > 0) {
            echo wp_get_attachment_image($product_image_id, 'large', false, [
                'class' => 'product-image__img',
                'loading' => 'lazy',
                'decoding' => 'async',
            ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo wc_placeholder_img('large', [
                'class' => 'product-image__img',
                'loading' => 'lazy',
                'decoding' => 'async',
            ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        ?>
    </a>
    <p class="part-number">Numer części: <strong class="part-number-value"><?php echo esc_html(function_exists('gp_get_product_part_number') ? gp_get_product_part_number($product) : 'Brak'); ?></strong></p>
    <h3 class="gp-product__name product-title"><a href="<?php echo esc_url(get_permalink($product->get_id())); ?>"><?php echo esc_html(gp_format_product_display_name($product->get_name())); ?></a></h3>
    <p class="gp-product__price product-price">
        <span class="gp-product__current price"><?php echo wp_kses_post(wc_price($product->get_price())); ?></span>
    </p>
    <div class="gp-product__delivery product-shipping shipping">Darmowa dostawa: 23–24 kwi</div>
    <div class="gp-product__delivery-note product-shipping-note shipping-note">Jeśli zapłacisz do 14:00</div>
</article>
