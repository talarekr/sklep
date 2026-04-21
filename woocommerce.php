<?php
if (!defined('ABSPATH')) {
    exit;
}
get_header();
?>
<main class="gp-woo-layout">
    <div class="gp-container">
        <?php woocommerce_breadcrumb(); ?>
        <?php woocommerce_content(); ?>
    </div>
</main>
<?php get_footer(); ?>
