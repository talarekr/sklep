<?php
/**
 * Order Review
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/review-order.php.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 5.2.0
 */

defined('ABSPATH') || exit;

$cart = function_exists('gpswiss_wc_cart_safe') ? gpswiss_wc_cart_safe() : null;
?>
<table class="shop_table woocommerce-checkout-review-order-table">
	<thead>
		<tr>
			<th class="product-name"><?php esc_html_e('Produkt', 'woocommerce'); ?></th>
			<th class="product-total"><?php esc_html_e('Kwota', 'woocommerce'); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		do_action('woocommerce_review_order_before_cart_contents');

		$cart_items = $cart ? $cart->get_cart() : [];
		foreach ($cart_items as $cart_item_key => $cart_item) {
			$_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);

			if ($_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters('woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key)) {
				?>
				<tr class="<?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
					<td class="product-name">
						<div class="gp-checkout-review-item">
							<div class="gp-checkout-review-item__thumb" aria-hidden="true">
								<?php echo wp_kses_post($_product->get_image('woocommerce_thumbnail', ['class' => 'gp-checkout-review-item__image'])); ?>
							</div>
							<div class="gp-checkout-review-item__content">
								<?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key)); ?>
								<strong class="product-quantity"><?php echo esc_html(sprintf('× %s', $cart_item['quantity'])); ?></strong>
								<?php echo wc_get_formatted_cart_item_data($cart_item); ?>
							</div>
						</div>
					</td>
					<td class="product-total">
						<?php
						$item_subtotal = $cart ? $cart->get_product_subtotal($_product, $cart_item['quantity']) : '';
						echo wp_kses_post(apply_filters('woocommerce_cart_item_subtotal', $item_subtotal, $cart_item, $cart_item_key));
						?>
					</td>
				</tr>
				<?php
			}
		}

		do_action('woocommerce_review_order_after_cart_contents');
		?>
	</tbody>
	<tfoot>
		<tr class="cart-subtotal">
			<th><?php esc_html_e('Kwota', 'gp-clone'); ?></th>
			<td><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<tr class="shipping gp-checkout-shipping-total">
			<th><?php esc_html_e('Koszt dostawy', 'gp-clone'); ?></th>
			<td data-title="<?php esc_attr_e('Koszt dostawy', 'gp-clone'); ?>">0 zł</td>
		</tr>

		<?php foreach (($cart ? $cart->get_fees() : []) as $fee) : ?>
			<tr class="fee">
				<th><?php echo esc_html($fee->name); ?></th>
				<td><?php wc_cart_totals_fee_html($fee); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ($cart && wc_tax_enabled() && !$cart->display_prices_including_tax()) : ?>
			<?php if ('itemized' === get_option('woocommerce_tax_total_display')) : ?>
				<?php foreach ($cart->get_tax_totals() as $code => $tax) : ?>
					<tr class="tax-rate tax-rate-<?php echo esc_attr(sanitize_title($code)); ?>">
						<th><?php echo esc_html($tax->label); ?></th>
						<td><?php echo wp_kses_post($tax->formatted_amount); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php else : ?>
					<tr class="tax-total">
						<th><?php echo esc_html((function_exists('WC') && WC() && WC()->countries) ? WC()->countries->tax_or_vat() : __('VAT', 'woocommerce')); ?></th>
						<td><?php wc_cart_totals_taxes_total_html(); ?></td>
					</tr>
				<?php endif; ?>
		<?php endif; ?>

		<tr class="order-total">
			<th><?php esc_html_e('Łącznie', 'gp-clone'); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>
	</tfoot>
</table>
