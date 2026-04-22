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
                $current_term_id = 0;
                $active_path_ids = [];

                if (is_tax('product_cat')) {
                    $current_term = get_queried_object();
                    if ($current_term instanceof WP_Term) {
                        $current_term_id = (int) $current_term->term_id;
                        $active_path_ids = array_map('intval', array_filter(array_merge(
                            [$current_term_id],
                            get_ancestors($current_term_id, 'product_cat', 'taxonomy')
                        )));
                    }
                }

                if (function_exists('gp_get_product_category_tree') && function_exists('gp_render_product_category_tree') && gp_get_product_category_tree(0) !== []) {
                    gp_render_product_category_tree(0, $current_term_id, $active_path_ids);
                } else {
                    echo '<p class="gp-shop-sidebar__empty">' . esc_html__('Brak kategorii do wyświetlenia.', 'gp-clone') . '</p>';
                }
                ?>
            </aside>

            <section class="gp-shop-content">
                <?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
                    <h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
                <?php endif; ?>

                <?php do_action('woocommerce_archive_description'); ?>

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
