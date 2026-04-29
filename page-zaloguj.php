<?php
if (!defined('ABSPATH')) {
    exit;
}

$lost_password_url = wp_lostpassword_url(home_url('/zaloguj'));

get_header();
?>
<main class="gp-auth-page">
    <div class="gp-auth-card">
        <h1><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></h1>
        <?php gp_render_auth_notice_from_query(); ?>
        <?php if (gp_is_google_oauth_available()) : ?>
            <div class="gp-auth-social" data-gp-google-button data-gp-context="login"></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-gp-google-form>
                <input type="hidden" name="action" value="gp_google_identity">
                <input type="hidden" name="gp_context" value="login">
                <input type="hidden" name="gp_google_nonce" value="<?php echo esc_attr(wp_create_nonce('gp_google_identity_nonce')); ?>">
                <input type="hidden" name="credential" value="" data-gp-google-credential>
                <button class="gp-auth-social" type="submit" data-gp-google-submit><?php esc_html_e('Kontynuuj z Google', 'gp-clone'); ?></button>
            </form>
            <div class="gp-auth-separator"><span><?php esc_html_e('lub', 'gp-clone'); ?></span></div>
        <?php endif; ?>
        <form class="gp-auth-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-gp-auth-form novalidate>
            <input type="hidden" name="action" value="gp_profile_login">
            <?php wp_nonce_field('gp_profile_login', 'gp_auth_nonce'); ?>

            <div>
                <label for="gp-login-email"><?php esc_html_e('Adres e-mail', 'gp-clone'); ?></label>
                <input id="gp-login-email" type="email" name="email" autocomplete="email" required>
            </div>

            <div>
                <label for="gp-login-password"><?php esc_html_e('Hasło', 'gp-clone'); ?></label>
                <div class="gp-password-wrap">
                    <input id="gp-login-password" type="password" name="password" autocomplete="current-password" required minlength="8">
                    <button type="button" class="gp-password-toggle" aria-label="Pokaż hasło" data-gp-password-toggle="gp-login-password">👁</button>
                </div>
            </div>

            <div class="gp-auth-row">
                <label class="gp-auth-checkbox" for="gp-remember-me">
                    <input id="gp-remember-me" type="checkbox" name="remember_me">
                    <span><?php esc_html_e('Zapamiętaj mnie', 'gp-clone'); ?></span>
                </label>
                <a href="<?php echo esc_url($lost_password_url); ?>"><?php esc_html_e('Zapomniałeś hasła?', 'gp-clone'); ?></a>
            </div>

            <button class="gp-auth-submit" type="submit"><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></button>

            <p class="gp-auth-footer">
                <?php esc_html_e('Nie masz konta?', 'gp-clone'); ?>
                <a href="<?php echo esc_url(home_url('/zarejestruj')); ?>"><?php esc_html_e('Zarejestruj się', 'gp-clone'); ?></a>
            </p>
        </form>
    </div>
</main>
<?php get_footer(); ?>
