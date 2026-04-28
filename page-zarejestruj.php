<?php
if (!defined('ABSPATH')) {
    exit;
}

$terms_url = home_url('/regulamin-platnosci');
$privacy_url = home_url('/polityka-prywatnosci');

get_header();
?>
<main class="gp-auth-page">
    <div class="gp-auth-card">
        <h1><?php esc_html_e('Utwórz konto', 'gp-clone'); ?></h1>
        <?php gp_render_auth_notice_from_query(); ?>
        <?php if (gp_is_google_oauth_available()) : ?>
            <div class="gp-auth-social" data-gp-google-button data-gp-context="register"></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-gp-google-form>
                <input type="hidden" name="action" value="gp_google_identity">
                <input type="hidden" name="gp_context" value="register">
                <input type="hidden" name="gp_google_nonce" value="<?php echo esc_attr(wp_create_nonce('gp_google_identity_nonce')); ?>">
                <input type="hidden" name="credential" value="" data-gp-google-credential>
                <button class="gp-auth-social" type="button" data-gp-google-submit><?php esc_html_e('Kontynuuj z Google', 'gp-clone'); ?></button>
            </form>
            <div class="gp-auth-separator"><span><?php esc_html_e('lub', 'gp-clone'); ?></span></div>
        <?php endif; ?>

        <form class="gp-auth-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-gp-auth-form novalidate>
            <input type="hidden" name="action" value="gp_profile_register">
            <?php wp_nonce_field('gp_profile_register', 'gp_auth_nonce'); ?>

            <fieldset class="gp-account-types">
                <legend class="screen-reader-text"><?php esc_html_e('Typ konta', 'gp-clone'); ?></legend>
                <label class="gp-account-type" for="gp-account-customer">
                    <input id="gp-account-customer" type="radio" name="account_type" value="customer" checked>
                    <span><?php esc_html_e('Konto klienta (osoba prywatna lub firma)', 'gp-clone'); ?></span>
                </label>
            </fieldset>

            <div class="gp-auth-grid gp-auth-grid--two">
                <div>
                    <label for="gp-register-nip"><?php esc_html_e('Numer NIP (opcjonalnie)', 'gp-clone'); ?></label>
                    <input id="gp-register-nip" type="text" name="nip" inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10">
                </div>

                <div>
                    <label for="gp-register-company"><?php esc_html_e('Nazwa firmy (opcjonalnie)', 'gp-clone'); ?></label>
                    <input id="gp-register-company" type="text" name="company">
                </div>

                <div>
                    <label for="gp-register-first-name"><?php esc_html_e('Imię', 'gp-clone'); ?></label>
                    <input id="gp-register-first-name" type="text" name="first_name" required>
                </div>

                <div>
                    <label for="gp-register-last-name"><?php esc_html_e('Nazwisko', 'gp-clone'); ?></label>
                    <input id="gp-register-last-name" type="text" name="last_name" required>
                </div>
            </div>

            <div>
                <label for="gp-register-phone"><?php esc_html_e('Numer telefonu', 'gp-clone'); ?></label>
                <input id="gp-register-phone" type="tel" name="phone" inputmode="tel" required>
            </div>

            <div>
                <label for="gp-register-email"><?php esc_html_e('Adres e-mail', 'gp-clone'); ?></label>
                <input id="gp-register-email" type="email" name="email" autocomplete="email" required>
            </div>

            <div>
                <label for="gp-register-password"><?php esc_html_e('Hasło', 'gp-clone'); ?></label>
                <div class="gp-password-wrap">
                    <input id="gp-register-password" type="password" name="password" autocomplete="new-password" required minlength="8">
                    <button type="button" class="gp-password-toggle" aria-label="Pokaż hasło" data-gp-password-toggle="gp-register-password">👁</button>
                </div>
            </div>

            <div>
                <label for="gp-register-password-confirm"><?php esc_html_e('Potwierdź hasło', 'gp-clone'); ?></label>
                <div class="gp-password-wrap">
                    <input id="gp-register-password-confirm" type="password" name="password_confirm" autocomplete="new-password" required minlength="8">
                    <button type="button" class="gp-password-toggle" aria-label="Pokaż hasło" data-gp-password-toggle="gp-register-password-confirm">👁</button>
                </div>
            </div>

            <label class="gp-auth-checkbox" for="gp-accept-terms">
                <input id="gp-accept-terms" type="checkbox" name="accept_terms" required>
                <span>
                    <?php esc_html_e('Akceptuję', 'gp-clone'); ?>
                    <a href="<?php echo esc_url($terms_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Regulamin', 'gp-clone'); ?></a>
                    <?php esc_html_e('oraz', 'gp-clone'); ?>
                    <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Politykę prywatności', 'gp-clone'); ?></a>
                </span>
            </label>

            <button class="gp-auth-submit" type="submit"><?php esc_html_e('Zarejestruj się', 'gp-clone'); ?></button>

            <p class="gp-auth-footer">
                <?php esc_html_e('Masz już konto?', 'gp-clone'); ?>
                <a href="<?php echo esc_url(home_url('/zaloguj')); ?>"><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></a>
            </p>
        </form>
    </div>
</main>
<?php get_footer(); ?>
