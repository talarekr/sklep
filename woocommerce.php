<?php
if (!defined('ABSPATH')) {
    exit;
}
get_header();
?>
<main class="gp-woo-layout">
    <?php
    $part_number_query = isset($_GET['part_number']) ? sanitize_text_field((string) wp_unslash($_GET['part_number'])) : '';
    $current_category = get_queried_object();
    $shop_search_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    ?>
    <?php if (is_tax('product_cat') && $current_category instanceof WP_Term) : ?>
        <section class="gp-category-search-hero">
            <div class="gp-category-search-hero__media">
                <div class="gp-container">
                    <h1 class="gp-category-search-hero__title"><?php echo esc_html($current_category->name); ?></h1>
                    <p class="gp-category-search-hero__description">
                        <?php esc_html_e('W sklepie motoryzacyjnym GP Swiss znajdziesz szeroki wybór oryginalnych, używanych części samochodowych do wielu popularnych marek, takich jak BMW, Mini, Mercedes, Audi czy Volkswagen. Każdy oferowany przez nas produkt jest dokładnie sprawdzany pod kątem jakości i sprawności, dzięki czemu masz pewność, że wybierasz sprawdzony i solidny produkt.', 'gp-clone'); ?>
                    </p>
                </div>
            </div>
            <div class="gp-container">
                <div class="gp-category-search-hero__panel">
                    <div class="gp-category-search-hero__modes" aria-hidden="true">
                        <span class="is-active"><?php esc_html_e('Numer części', 'gp-clone'); ?></span>
                        <span><?php esc_html_e('Model pojazdu', 'gp-clone'); ?></span>
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
