<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<!-- privacy-template-loaded -->
<main class="gp-woo-layout">
    <div class="gp-container">
        <?php while (have_posts()) : the_post(); ?>
            <article <?php post_class('gp-privacy-policy'); ?>>
                <h1><?php the_title(); ?></h1>

                <?php if (trim((string) get_the_content()) !== '') : ?>
                    <div class="gp-privacy-policy__content">
                        <?php the_content(); ?>
                    </div>
                <?php else : ?>
                    <div class="gp-privacy-policy__content">
                        <?php echo wp_kses_post(gp_get_privacy_policy_fallback_html()); ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endwhile; ?>
    </div>
</main>
<?php get_footer(); ?>
