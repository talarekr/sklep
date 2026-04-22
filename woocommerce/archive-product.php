<?php
/**
 * Shop archive template.
 */
if (!defined('ABSPATH')) {
    exit;
}

get_header('shop');
?>
<main class="gp-woo-layout">
    <div class="gp-container">
        <?php woocommerce_breadcrumb(); ?>

        <div class="gp-shop-grid">
            <aside class="gp-shop-sidebar">
                <h3 class="gp-shop-sidebar__title"><?php esc_html_e('Kategorie', 'gp-clone'); ?></h3>
                <?php
                if (function_exists('gp_render_product_category_sidebar')) {
                    gp_render_product_category_sidebar();
                }
                ?>
            </aside>

            <section class="gp-shop-content">
                <?php if (woocommerce_product_loop()) : ?>
                    <?php do_action('woocommerce_before_shop_loop'); ?>
                    <?php woocommerce_product_loop_start(); ?>
                    <?php if (wc_get_loop_prop('total')) : ?>
                        <?php while (have_posts()) : ?>
                            <?php the_post(); ?>
                            <?php do_action('woocommerce_shop_loop'); ?>
                            <?php wc_get_template_part('content', 'product'); ?>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    <?php woocommerce_product_loop_end(); ?>
                    <?php do_action('woocommerce_after_shop_loop'); ?>
                <?php else : ?>
                    <?php do_action('woocommerce_no_products_found'); ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
<?php get_footer('shop'); ?>
