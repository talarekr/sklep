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
    <?php
    $part_number_query = isset($_GET['part_number']) ? sanitize_text_field((string) wp_unslash($_GET['part_number'])) : '';
    $current_category = get_queried_object();
    $shop_search_url = wc_get_page_permalink('shop');
    ?>
    <?php if (is_tax('product_cat') && $current_category instanceof WP_Term) : ?>
        <section class="gp-category-search-hero">
            <div class="gp-container">
                <div class="gp-category-search-hero__inner">
                    <div class="gp-category-search-hero__media">
                    <h1 class="gp-category-search-hero__title"><?php echo esc_html($current_category->name); ?></h1>
                    <p class="gp-category-search-hero__description">
                        <?php esc_html_e('W sklepie motoryzacyjnym GP Swiss znajdziesz szeroki wybór oryginalnych, używanych części samochodowych do wielu popularnych marek, takich jak BMW, Mini, Mercedes, Audi czy Volkswagen. Każdy oferowany przez nas produkt jest dokładnie sprawdzany pod kątem jakości i sprawności, dzięki czemu masz pewność, że wybierasz sprawdzony i solidny produkt.', 'gp-clone'); ?>
                    </p>
                    </div>
                    <div class="gp-category-search-hero__panel">
                        <div class="gp-search-tabs" data-search-switch>
                            <button type="button" class="is-active" data-mode="part"><?php esc_html_e('Numer części', 'gp-clone'); ?></button>
                            <button type="button" data-mode="model"><?php esc_html_e('Model pojazdu', 'gp-clone'); ?></button>
                        </div>
                        <form method="get" action="<?php echo esc_url($shop_search_url); ?>" class="gp-category-search-hero__form">
                            <input
                                type="search"
                                name="part_number"
                                value="<?php echo esc_attr($part_number_query); ?>"
                                placeholder="<?php esc_attr_e('Wprowadź numer części', 'gp-clone'); ?>"
                                required
                            >
                            <button type="submit"><?php esc_html_e('Szukaj', 'gp-clone'); ?></button>
                        </form>
                    </div>
                </div>
            </section>
        <?php endif; ?>
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
                <?php if ($part_number_query !== '') : ?>
                    <p class="gp-part-search-results-note">
                        <?php
                        echo wp_kses_post(sprintf(
                            __('Wyniki wyszukiwania dla numeru części: %s', 'gp-clone'),
                            '<strong>' . esc_html($part_number_query) . '</strong>'
                        ));
                        ?>
                    </p>
                <?php endif; ?>
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
