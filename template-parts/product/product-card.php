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
$product_id = (int) $product->get_id();
$thumbnail_id = (int) $product->get_image_id();
$awi_listing_image_id = (int) get_post_meta($product_id, '_awi_listing_image_id', true);
$product_image_id = class_exists('\\AWI\\Plugin') ? \AWI\Plugin::get_listing_image_id_for_product($product_id) : $thumbnail_id;
$product_image_source = 'placeholder';
if ($product_image_id > 0) {
    if ($awi_listing_image_id > 0 && $product_image_id === $awi_listing_image_id) {
        $product_image_source = 'awi_listing';
    } elseif ($thumbnail_id > 0 && $product_image_id === $thumbnail_id) {
        $product_image_source = 'thumbnail';
    } else {
        $product_image_source = 'attachment';
    }
}
$awi_image_debug_enabled = (isset($_GET['awi_image_debug']) && (string) wp_unslash($_GET['awi_image_debug']) === '1') || current_user_can('manage_options');
?>
<article class="gp-product product-card<?php echo esc_attr($wrapper_class_attr); ?>">
    <?php if ($awi_image_debug_enabled) : ?>
        <?php echo "\n" . sprintf(
            '<!-- AWI image debug: product_id=%d image_id=%d source=%s image_url=%s thumbnail_id=%d awi_listing_image_id=%d -->',
            $product_id,
            (int) $product_image_id,
            esc_attr($product_image_source),
            esc_url($product_image_id > 0 ? (string) wp_get_attachment_url($product_image_id) : ''),
            (int) $thumbnail_id,
            (int) $awi_listing_image_id
        ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php endif; ?>
    <button type="button" class="gp-product__fav product-wishlist" aria-label="<?php echo esc_attr(sprintf(__('Dodaj %s do obserwowanych', 'gp-clone'), $product->get_name())); ?>">
        &#9825;
    </button>
    <a class="product-image" href="<?php echo esc_url(get_permalink($product_id)); ?>" aria-label="<?php echo esc_attr($product->get_name()); ?>">
        <?php
        if ($product_image_id > 0) {
            echo wp_get_attachment_image($product_image_id, 'large', false, [
                'class' => 'product-image__img',
                'loading' => 'lazy',
                'decoding' => 'async',
            ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            $fallback_featured_id = $thumbnail_id;
            if ($fallback_featured_id > 0) {
                echo wp_get_attachment_image($fallback_featured_id, 'large', false, [
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
        }
        ?>
    </a>
    <p class="part-number">Numer części: <strong class="part-number-value"><?php echo esc_html(function_exists('gp_get_product_part_number') ? gp_get_product_part_number($product) : 'Brak'); ?></strong></p>
    <h3 class="gp-product__name product-title"><a href="<?php echo esc_url(get_permalink($product_id)); ?>"><?php echo esc_html(gp_format_product_display_name($product->get_name())); ?></a></h3>
    <p class="gp-product__price product-price">
        <span class="gp-product__current price"><?php echo wp_kses_post(wc_price($product->get_price())); ?></span>
    </p>
    <div class="gp-product__delivery product-shipping shipping"><?php echo esc_html(gp_get_delivery_text()); ?></div>
    <div class="gp-product__delivery-note product-shipping-note shipping-note"><?php echo esc_html(gp_get_delivery_cutoff_text()); ?></div>
</article>
