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
    $model_query = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
    $current_category = get_queried_object();
    $category_search_mode = $model_query !== '' ? 'model' : 'part';
    $search_mode_value = $category_search_mode === 'model' ? 'vehicle_model' : 'part_number';
    $search_value = $category_search_mode === 'model' ? $model_query : $part_number_query;
    $category_search_action = ($current_category instanceof WP_Term && is_product_category()) ? get_term_link($current_category) : wc_get_page_permalink('shop');
    if (is_wp_error($category_search_action) || !is_string($category_search_action) || $category_search_action === '') {
        $category_search_action = wc_get_page_permalink('shop');
    }
    ?>
    <div class="gp-container">
        <?php if (is_product_category() && $current_category instanceof WP_Term) : ?>
            <section class="gp-category-search-hero">
                <div class="gp-category-search-hero__inner">
                    <div class="gp-category-search-hero__media">
                        <h1 class="gp-category-search-hero__title"><?php echo esc_html($current_category->name); ?></h1>
                        <p class="gp-category-search-hero__description">
                            <?php esc_html_e('W sklepie motoryzacyjnym GP Swiss znajdziesz szeroki wybór oryginalnych, używanych części samochodowych do wielu popularnych marek, takich jak BMW, Mini, Mercedes, Audi czy Volkswagen. Każdy oferowany przez nas produkt jest dokładnie sprawdzany pod kątem jakości i sprawności, dzięki czemu masz pewność, że wybierasz sprawdzony i solidny produkt.', 'gp-clone'); ?>
                        </p>
                    </div>
                    <div class="gp-category-search-hero__panel">
                        <div class="gp-search-tabs" data-search-switch>
                            <button type="button" data-mode="part" data-search-mode="part_number" data-input-name="part_number" data-placeholder="<?php echo esc_attr__('Wprowadź numer części', 'gp-clone'); ?>" class="<?php echo $category_search_mode === 'part' ? 'is-active' : ''; ?>"><?php esc_html_e('Numer części', 'gp-clone'); ?></button>
                            <button type="button" data-mode="model" data-search-mode="vehicle_model" data-input-name="s" data-placeholder="<?php echo esc_attr__('Wprowadź model pojazdu', 'gp-clone'); ?>" class="<?php echo $category_search_mode === 'model' ? 'is-active' : ''; ?>"><?php esc_html_e('Model pojazdu', 'gp-clone'); ?></button>
                        </div>
                        <form method="get" action="<?php echo esc_url($category_search_action); ?>" class="gp-category-search-hero__form" data-category-search-form>
                            <input type="hidden" name="search_mode" value="<?php echo esc_attr($search_mode_value); ?>" data-category-search-mode>
                            <input
                                type="search"
                                name="<?php echo $category_search_mode === 'model' ? 's' : 'part_number'; ?>"
                                value="<?php echo esc_attr($search_value); ?>"
                                placeholder="<?php echo esc_attr($category_search_mode === 'model' ? __('Wprowadź model pojazdu', 'gp-clone') : __('Wprowadź numer części', 'gp-clone')); ?>"
                                required
                                data-category-search-input
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
