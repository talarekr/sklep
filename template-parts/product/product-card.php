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
?>
<article class="gp-product product-card<?php echo esc_attr($wrapper_class_attr); ?>">
    <button type="button" class="gp-product__fav" aria-label="<?php echo esc_attr(sprintf(__('Dodaj %s do obserwowanych', 'gp-clone'), $product->get_name())); ?>">
        &#9825;
    </button>
    <?php if ($product->is_on_sale()) : ?>
        <span class="gp-product__badge">
            <?php
            $regular = (float) $product->get_regular_price();
            $sale = (float) $product->get_sale_price();
            $discount = $regular > 0 ? round((($regular - $sale) / $regular) * 100) : 0;
            echo esc_html('-' . $discount . '%');
            ?>
        </span>
    <?php endif; ?>
    <a href="<?php echo esc_url(get_permalink($product->get_id())); ?>" aria-label="<?php echo esc_attr($product->get_name()); ?>">
        <?php echo $product->get_image('woocommerce_thumbnail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </a>
    <div class="gp-product__sku part-number">Numer części: <strong><span><?php echo esc_html(function_exists('gp_get_product_part_number') ? gp_get_product_part_number($product) : 'Brak'); ?></span></strong></div>
    <h3 class="gp-product__name product-title"><a href="<?php echo esc_url(get_permalink($product->get_id())); ?>"><?php echo esc_html(gp_format_product_display_name($product->get_name())); ?></a></h3>
    <p class="gp-product__price">
        <?php if ($product->get_regular_price() && $product->is_on_sale()) : ?><span class="gp-product__promo-label">Cena promocyjna</span><span class="gp-product__old price-old"><?php echo esc_html(wc_price($product->get_regular_price())); ?></span><?php endif; ?>
        <span class="<?php echo $product->is_on_sale() ? 'gp-product__current gp-product__current--sale price price-sale' : 'gp-product__current price'; ?>"><?php echo wp_kses_post(wc_price($product->get_price())); ?></span>
    </p>
    <div class="gp-product__delivery product-shipping shipping">Darmowa dostawa: 23–24 kwi</div>
    <div class="gp-product__delivery-note product-shipping-sub shipping-note">Jeśli zapłacisz do 14:00</div>
</article>
