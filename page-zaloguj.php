<?php
if (!defined('ABSPATH')) {
    exit;
}
get_header();
?>
<main class="gp-auth-page">
    <div class="gp-auth-card">
        <h1><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></h1>
        <form class="gp-auth-form" method="post" action="#" data-gp-auth-form novalidate>
            <button type="button" class="gp-auth-social" aria-label="Kontynuuj z Google">G <span><?php esc_html_e('Kontynuuj z Google', 'gp-clone'); ?></span></button>

            <div class="gp-auth-separator"><span><?php esc_html_e('lub', 'gp-clone'); ?></span></div>

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
                <a href="#"><?php esc_html_e('Zapomniałeś hasła?', 'gp-clone'); ?></a>
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
