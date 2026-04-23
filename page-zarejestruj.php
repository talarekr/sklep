<?php
if (!defined('ABSPATH')) {
    exit;
}
get_header();
?>
<main class="gp-auth-page">
    <div class="gp-auth-card">
        <h1><?php esc_html_e('Utwórz konto', 'gp-clone'); ?></h1>

        <form class="gp-auth-form" method="post" action="#" data-gp-auth-form novalidate>
            <fieldset class="gp-account-types">
                <legend class="screen-reader-text"><?php esc_html_e('Typ konta', 'gp-clone'); ?></legend>
                <label class="gp-account-type" for="gp-account-customer">
                    <input id="gp-account-customer" type="radio" name="account_type" value="customer" checked>
                    <span><?php esc_html_e('Konto klienta (osoba prywatna lub firma)', 'gp-clone'); ?></span>
                </label>
            </fieldset>

            <button type="button" class="gp-auth-social" aria-label="Kontynuuj z Google">G <span><?php esc_html_e('Kontynuuj z Google', 'gp-clone'); ?></span></button>

            <div class="gp-auth-separator"><span><?php esc_html_e('lub', 'gp-clone'); ?></span></div>

            <div class="gp-auth-grid gp-auth-grid--two">
                <div>
                    <label for="gp-register-nip"><?php esc_html_e('Numer NIP', 'gp-clone'); ?></label>
                    <input id="gp-register-nip" type="text" name="nip" inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" required>
                </div>

                <div>
                    <label for="gp-register-company"><?php esc_html_e('Nazwa firmy', 'gp-clone'); ?></label>
                    <input id="gp-register-company" type="text" name="company" required>
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

            <label class="gp-auth-checkbox" for="gp-accept-all">
                <input id="gp-accept-all" type="checkbox" name="accept_all" required>
                <span><?php esc_html_e('Akceptuję wszystkie warunki', 'gp-clone'); ?></span>
            </label>

            <label class="gp-auth-checkbox" for="gp-marketing-consent">
                <input id="gp-marketing-consent" type="checkbox" name="marketing_consent" required>
                <span><?php esc_html_e('Zgoda marketingowa + polityka prywatności', 'gp-clone'); ?></span>
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
