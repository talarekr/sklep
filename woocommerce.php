<?php
if (!defined('ABSPATH')) {
    exit;
}
get_header();
?>
<main class="gp-woo-layout">
    <div class="gp-container">
        <?php woocommerce_breadcrumb(); ?>

        <?php if (is_shop() || is_tax('product_cat')) : ?>
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
                    <?php woocommerce_content(); ?>
                </section>
            </div>
        <?php else : ?>
            <?php woocommerce_content(); ?>
        <?php endif; ?>
    </div>
</main>
<?php get_footer(); ?>
