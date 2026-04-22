<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();

$contact_status = isset($_GET['contact_status']) ? sanitize_key((string) $_GET['contact_status']) : '';
$status_config = [
    'sent' => [
        'class' => 'is-success',
        'text' => __('Dziękujemy! Twoja wiadomość została wysłana.', 'gp-clone'),
    ],
    'validation_error' => [
        'class' => 'is-error',
        'text' => __('Uzupełnij poprawnie wszystkie pola formularza.', 'gp-clone'),
    ],
    'nonce_error' => [
        'class' => 'is-error',
        'text' => __('Nie udało się zweryfikować formularza. Spróbuj ponownie.', 'gp-clone'),
    ],
    'send_error' => [
        'class' => 'is-error',
        'text' => __('Nie udało się wysłać wiadomości. Spróbuj ponownie za chwilę.', 'gp-clone'),
    ],
];
?>
<main class="gp-contact-page">
    <div class="gp-container">
        <h1 class="gp-contact-page__title"><?php esc_html_e('Kontakt', 'gp-clone'); ?></h1>
        <p class="gp-contact-page__intro"><?php esc_html_e('Jeśli masz pytania, zapraszamy do kontaktu.', 'gp-clone'); ?></p>

        <div class="gp-contact-page__grid">
            <section class="gp-contact-card">
                <h2><?php esc_html_e('Dane firmy', 'gp-clone'); ?></h2>
                <p><strong>GREGOR swiss GRZEGORZ PACIOREK</strong></p>
                <p>Milanowska 137</p>
                <p>08-460 Sobolew</p>
                <p>NIP: 8262157853</p>
                <p>REGON: 368948917</p>
                <p>Tel: <a href="tel:+48504266984">504 266 984</a></p>
                <p>E-mail: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
            </section>

            <section class="gp-contact-form">
                <h2><?php esc_html_e('Formularz kontaktowy', 'gp-clone'); ?></h2>

                <?php if (isset($status_config[$contact_status])) : ?>
                    <p class="gp-contact-form__notice <?php echo esc_attr($status_config[$contact_status]['class']); ?>">
                        <?php echo esc_html($status_config[$contact_status]['text']); ?>
                    </p>
                <?php endif; ?>

                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="gp-contact-form__fields">
                    <input type="hidden" name="action" value="gp_contact_form">
                    <?php wp_nonce_field('gp_contact_form', 'gp_contact_nonce'); ?>

                    <label for="gp-contact-name">
                        <?php esc_html_e('Imię i nazwisko', 'gp-clone'); ?>
                        <input id="gp-contact-name" type="text" name="name" required>
                    </label>

                    <label for="gp-contact-email">
                        <?php esc_html_e('E-mail', 'gp-clone'); ?>
                        <input id="gp-contact-email" type="email" name="email" required>
                    </label>

                    <label for="gp-contact-message">
                        <?php esc_html_e('Wiadomość', 'gp-clone'); ?>
                        <textarea id="gp-contact-message" name="message" required></textarea>
                    </label>

                    <button type="submit"><?php esc_html_e('Wyślij wiadomość', 'gp-clone'); ?></button>
                </form>
            </section>
        </div>
    </div>
</main>
<?php get_footer(); ?>
