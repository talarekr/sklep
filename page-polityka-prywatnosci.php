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
            <?php
            $privacy_post_id = get_the_ID();
            $privacy_raw_content = get_post_field('post_content', $privacy_post_id);
            $privacy_content_length = is_string($privacy_raw_content) ? strlen(trim($privacy_raw_content)) : 0;
            ?>
            <!-- privacy-debug post_id=<?php echo esc_html((string) $privacy_post_id); ?> post_status=<?php echo esc_html((string) get_post_status($privacy_post_id)); ?> post_name=<?php echo esc_html((string) get_post_field('post_name', $privacy_post_id)); ?> post_content_length=<?php echo esc_html((string) $privacy_content_length); ?> -->
            <article <?php post_class('gp-privacy-policy'); ?>>
                <h1><?php the_title(); ?></h1>
                <p>TEST_PRIVACY_CONTENT</p>

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
