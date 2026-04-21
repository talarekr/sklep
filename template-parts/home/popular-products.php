<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="gp-popular">
    <div class="gp-container">
        <div class="gp-popular__head">
            <h2 class="gp-section-title">Popularne części</h2>
            <a href="<?php echo esc_url(function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : '#'); ?>">Pokaż wszystkie</a>
        </div>

        <?php if (class_exists('WooCommerce')) : ?>
            <?php
            $products = wc_get_products([
                'status' => 'publish',
                'limit' => 30,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            ?>
            <?php if (!empty($products)) : ?>
                <div class="gp-products">
                    <?php foreach ($products as $product) : ?>
                        <article class="gp-product">
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
                            <?php echo $product->get_image('woocommerce_thumbnail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <div class="gp-product__sku product-meta">Numer części: <strong><?php echo esc_html($product->get_sku() ?: 'BRAK'); ?></strong></div>
                            <h3 class="gp-product__name product-title"><a href="<?php echo esc_url(get_permalink($product->get_id())); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
                            <p class="gp-product__price product-price">
                                <?php if ($product->get_regular_price() && $product->is_on_sale()) : ?><span class="gp-product__promo-label">Cena promocyjna</span><span class="gp-product__old"><?php echo esc_html(wc_price($product->get_regular_price())); ?></span><?php endif; ?>
                                <span class="<?php echo $product->is_on_sale() ? 'gp-product__current gp-product__current--sale' : 'gp-product__current'; ?>"><?php echo wp_kses_post(wc_price($product->get_price())); ?></span>
                            </p>
                            <div class="gp-product__delivery product-shipping">Darmowa dostawa: 23–24 kwi</div>
                            <div class="gp-product__delivery-note product-shipping-sub">Jeśli zapłacisz do 14:00</div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p>Brak produktów. Użyj importera Allegro lub dodaj produkty ręcznie.</p>
            <?php endif; ?>
        <?php else : ?>
            <?php $demo_products = function_exists('gp_clone_demo_popular_products') ? gp_clone_demo_popular_products() : []; ?>
                <div class="gp-products">
                    <?php foreach ($demo_products as $product) : ?>
                        <article class="gp-product">
                            <button type="button" class="gp-product__fav" aria-label="<?php echo esc_attr(sprintf(__('Dodaj %s do obserwowanych', 'gp-clone'), $product['name'])); ?>">
                                &#9825;
                            </button>
                            <?php if (!empty($product['discount'])) : ?><span class="gp-product__badge"><?php echo esc_html($product['discount']); ?></span><?php endif; ?>
                            <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($product['name']); ?>">
                            <div class="gp-product__sku product-meta">Numer części: <strong><?php echo esc_html($product['sku']); ?></strong></div>
                            <h3 class="gp-product__name product-title"><a href="#"><?php echo esc_html($product['name']); ?></a></h3>
                            <p class="gp-product__price product-price">
                                <?php if (!empty($product['old_price'])) : ?><span class="gp-product__promo-label">Cena promocyjna</span><span class="gp-product__old"><?php echo esc_html($product['old_price']); ?></span><?php endif; ?>
                                <span class="gp-product__current<?php echo !empty($product['old_price']) ? ' gp-product__current--sale' : ''; ?>"><?php echo esc_html($product['price']); ?></span>
                            </p>
                            <div class="gp-product__delivery product-shipping">Darmowa dostawa: 23–24 kwi</div>
                            <div class="gp-product__delivery-note product-shipping-sub">Jeśli zapłacisz do 14:00</div>
                        </article>
                    <?php endforeach; ?>
                </div>
        <?php endif; ?>
    </div>
</section>
