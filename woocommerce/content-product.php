<?php
/**
 * Shared product card for WooCommerce loop items.
 */

defined('ABSPATH') || exit;

global $product;

if (!$product instanceof WC_Product || !$product->is_visible()) {
    return;
}
?>
<li <?php wc_product_class('gp-product-item', $product); ?>>
    <?php get_template_part('template-parts/product/product-card', null, ['product' => $product, 'wrapper_classes' => 'gp-product--loop']); ?>
</li>
