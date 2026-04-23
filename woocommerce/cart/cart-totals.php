<?php
/**
 * Cart totals (custom).
 */

defined('ABSPATH') || exit;
?>
<div class="cart_totals <?php echo WC()->customer->has_calculated_shipping() ? 'calculated_shipping' : ''; ?> gp-cart-totals">
    <h2><?php esc_html_e('Razem w koszyku', 'gp-clone'); ?></h2>

    <table cellspacing="0" class="shop_table shop_table_responsive">
        <tr class="cart-subtotal">
            <th><?php esc_html_e('Razem', 'gp-clone'); ?></th>
            <td data-title="<?php esc_attr_e('Razem', 'gp-clone'); ?>"><?php wc_cart_totals_subtotal_html(); ?></td>
        </tr>

        <tr class="shipping">
            <th><?php esc_html_e('Dostawa', 'gp-clone'); ?></th>
            <td data-title="<?php esc_attr_e('Dostawa', 'gp-clone'); ?>">0 zł</td>
        </tr>

        <?php foreach (WC()->cart->get_coupons() as $code => $coupon) : ?>
            <tr class="cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
                <th><?php esc_html_e('Rabat', 'gp-clone'); ?></th>
                <td data-title="<?php esc_attr_e('Rabat', 'gp-clone'); ?>"><?php wc_cart_totals_coupon_html($coupon); ?></td>
            </tr>
        <?php endforeach; ?>

        <tr class="order-total">
            <th><?php esc_html_e('Suma', 'gp-clone'); ?></th>
            <td data-title="<?php esc_attr_e('Suma', 'gp-clone'); ?>"><?php wc_cart_totals_order_total_html(); ?></td>
        </tr>
    </table>

    <div class="wc-proceed-to-checkout">
        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="checkout-button button alt wc-forward" data-gp-order-cta>
            <?php esc_html_e('Zamówienie', 'gp-clone'); ?>
        </a>
    </div>
</div>
