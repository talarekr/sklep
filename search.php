<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header('shop');

$search_query = get_search_query();
?>
<main class="gp-woo-layout">
    <div class="gp-container">
        <h1 class="gp-section-title">
            <?php
            echo wp_kses_post(sprintf(
                __('Wyniki wyszukiwania: %s', 'gp-clone'),
                '<strong>' . esc_html($search_query) . '</strong>'
            ));
            ?>
        </h1>

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
                <?php if (have_posts()) : ?>
                    <ul class="products columns-3">
                        <?php while (have_posts()) : the_post(); ?>
                            <?php wc_get_template_part('content', 'product'); ?>
                        <?php endwhile; ?>
                    </ul>

                    <?php the_posts_pagination(); ?>
                <?php else : ?>
                    <div class="woocommerce-info">
                        <?php esc_html_e('Brak wyników dla podanej frazy. Spróbuj innej nazwy, modelu lub numeru części.', 'gp-clone'); ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
<?php get_footer('shop'); ?>
