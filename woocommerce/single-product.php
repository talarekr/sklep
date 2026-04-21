<?php
/**
 * Single product template.
 */
if (!defined('ABSPATH')) {
    exit;
}

get_header('shop');
?>
<main class="gp-woo-layout">
    <div class="gp-container">
        <?php
        while (have_posts()) :
            the_post();
            wc_get_template_part('content', 'single-product');
        endwhile;
        ?>
    </div>
</main>
<?php get_footer('shop'); ?>
