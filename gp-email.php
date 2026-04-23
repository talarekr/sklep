<?php
if (!defined('ABSPATH')) {
    exit;
}

function gp_get_mail_setting(string $key, string $default = ''): string
{
    $constant_name = 'GP_' . strtoupper($key);
    if (defined($constant_name)) {
        return (string) constant($constant_name);
    }

    $env_value = getenv('GP_' . strtoupper($key));
    if (is_string($env_value) && $env_value !== '') {
        return $env_value;
    }

    return $default;
}

function gp_is_smtp_ready(): bool
{
    return gp_get_mail_setting('smtp_password') !== '';
}

add_action('phpmailer_init', function (PHPMailer\PHPMailer\PHPMailer $phpmailer): void {
    if (!gp_is_smtp_ready()) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = gp_get_mail_setting('smtp_host', 'thecamels.org');
    $phpmailer->SMTPAuth = true;
    $phpmailer->Port = (int) gp_get_mail_setting('smtp_port', '587');
    $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $phpmailer->Username = gp_get_mail_setting('smtp_login', 'biuro@gpswiss.pl');
    $phpmailer->Password = gp_get_mail_setting('smtp_password');
    $phpmailer->Timeout = 20;
}, 20);

add_filter('wp_mail_from', function (string $from): string {
    return gp_is_smtp_ready() ? gp_get_mail_setting('mail_from', 'biuro@gpswiss.pl') : $from;
});

add_filter('wp_mail_from_name', function (string $from_name): string {
    return gp_is_smtp_ready() ? gp_get_mail_setting('mail_from_name', 'GPSWISS') : $from_name;
});

add_filter('wp_mail', function (array $args): array {
    if (!gp_is_smtp_ready()) {
        return $args;
    }

    $reply_to = sanitize_email(gp_get_mail_setting('mail_reply_to', 'biuro@gpswiss.pl'));
    if ($reply_to === '') {
        return $args;
    }

    $headers = (array) ($args['headers'] ?? []);
    $headers[] = 'Reply-To: GPSWISS <' . $reply_to . '>';
    $args['headers'] = $headers;

    return $args;
});

add_action('wp_mail_failed', function (WP_Error $error): void {
    $message = 'Błąd wysyłki e-mail: ' . $error->get_error_message();

    if (function_exists('wc_get_logger')) {
        wc_get_logger()->error($message, ['source' => 'gp-mailer', 'data' => $error->get_error_data()]);
        return;
    }

    error_log($message);
});

function gp_get_company_footer_lines(): array
{
    return [
        'GREGOR swiss GRZEGORZ PACIOREK',
        'ul. Milanowska 137, 08-460 Sobolew',
        'NIP: 8262157853, REGON: 368948917',
        'e-mail: biuro@gpswiss.pl | https://gpswiss.pl',
    ];
}

function gp_mail_layout(string $title, string $content_html, string $cta_label = '', string $cta_url = '', string $preheader = ''): string
{
    $preheader_text = $preheader !== '' ? $preheader : $title;
    $cta_html = '';

    if ($cta_label !== '' && $cta_url !== '') {
        $cta_html = '<p style="margin:24px 0 0;"><a href="' . esc_url($cta_url) . '" style="display:inline-block;background:#122a66;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600;">' . esc_html($cta_label) . '</a></p>';
    }

    $footer_lines = array_map(static fn(string $line): string => '<div>' . esc_html($line) . '</div>', gp_get_company_footer_lines());

    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#1f2937;">'
        . '<span style="display:none!important;visibility:hidden;opacity:0;height:0;width:0;overflow:hidden;">' . esc_html($preheader_text) . '</span>'
        . '<table width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:20px 10px;"><tr><td align="center">'
        . '<table width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="background:#122a66;color:#fff;padding:20px 24px;font-size:20px;font-weight:700;">GPSWISS</td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.6;"><h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#0f172a;">' . esc_html($title) . '</h1>' . $content_html . $cta_html . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f8fafc;color:#475569;font-size:13px;line-height:1.5;">' . implode('', $footer_lines) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function gp_render_order_summary_html(WC_Order $order): string
{
    $rows = '';
    foreach ($order->get_items() as $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $rows .= '<tr><td style="padding:6px 0;">' . esc_html($item->get_name()) . ' × ' . (int) $item->get_quantity() . '</td></tr>';
    }

    $shipping = $order->get_shipping_method() ?: '—';
    $payment = $order->get_payment_method_title() ?: '—';

    return '<p><strong>Numer zamówienia:</strong> #' . esc_html($order->get_order_number()) . '<br>'
        . '<strong>Data zamówienia:</strong> ' . esc_html(wc_format_datetime($order->get_date_created())) . '<br>'
        . '<strong>Dostawa:</strong> ' . esc_html($shipping) . '<br>'
        . '<strong>Płatność:</strong> ' . esc_html($payment) . '<br>'
        . '<strong>Wartość:</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>'
        . '<table width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #e5e7eb;padding-top:8px;">' . $rows . '</table>';
}

function gp_send_customer_email_once(WC_Order $order, string $meta_flag, string $subject, string $title, string $content, string $cta_label = '', string $cta_url = ''): void
{
    if ($order->get_meta($meta_flag) === '1') {
        return;
    }

    $recipient = $order->get_billing_email();
    if (!is_email($recipient)) {
        return;
    }

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $html = gp_mail_layout($title, $content, $cta_label, $cta_url, $subject);

    if (wp_mail($recipient, $subject, $html, $headers)) {
        $order->update_meta_data($meta_flag, '1');
        $order->save_meta_data();
    }
}

add_action('woocommerce_order_status_failed', function (int $order_id): void {
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return;
    }

    $subject = 'Problem z płatnością za zamówienie nr ' . $order->get_order_number();
    $content = '<p>Nie udało się potwierdzić płatności za Twoje zamówienie.</p>'
        . '<p>Możesz ponowić płatność albo skontaktować się z nami: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a>.</p>'
        . gp_render_order_summary_html($order);

    gp_send_customer_email_once($order, '_gp_failed_payment_email_sent', $subject, 'Problem z płatnością', $content, 'Opłać zamówienie ponownie', $order->get_checkout_payment_url());
});

add_action('woocommerce_order_status_cancelled', function (int $order_id): void {
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return;
    }

    $subject = 'Zamówienie nr ' . $order->get_order_number() . ' zostało anulowane';
    $content = '<p>Informujemy, że Twoje zamówienie zostało anulowane.</p>'
        . '<p>Jeśli płatność została wcześniej zrealizowana, zwrot środków zostanie wykonany zgodnie z procedurą operatora płatności.</p>'
        . gp_render_order_summary_html($order)
        . '<p>W razie wątpliwości napisz do nas: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a>.</p>';

    gp_send_customer_email_once($order, '_gp_cancelled_email_sent', $subject, 'Zamówienie anulowane', $content, 'Skontaktuj się z nami', 'mailto:biuro@gpswiss.pl');
});

add_filter('retrieve_password_title', function (): string {
    return 'Reset hasła do konta GPSWISS';
});

add_filter('retrieve_password_message', function (string $message, string $key, string $user_login): string {
    $reset_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user_login), 'login');

    return "Otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta w GPSWISS.\n\n"
        . "Aby ustawić nowe hasło, kliknij link:\n" . $reset_url . "\n\n"
        . "Jeśli to nie Ty wysłałeś prośbę o zmianę hasła, zignoruj tę wiadomość.\n\n"
        . "GPSWISS\nbiuro@gpswiss.pl";
}, 10, 3);

add_filter('woocommerce_email_subject_customer_new_account', function (): string {
    return 'Witamy w GPSWISS – Twoje konto zostało utworzone';
});

add_filter('woocommerce_email_subject_customer_on_hold_order', function (string $subject, WC_Order $order): string {
    return 'Przyjęliśmy Twoje zamówienie nr ' . $order->get_order_number();
}, 10, 2);

add_filter('woocommerce_email_subject_customer_processing_order', function (string $subject, WC_Order $order): string {
    return 'Płatność za zamówienie nr ' . $order->get_order_number() . ' została potwierdzona';
}, 10, 2);

add_filter('woocommerce_email_subject_customer_completed_order', function (string $subject, WC_Order $order): string {
    return 'Twoje zamówienie nr ' . $order->get_order_number() . ' zostało wysłane';
}, 10, 2);

add_filter('woocommerce_email_subject_customer_reset_password', function (): string {
    return 'Reset hasła do konta GPSWISS';
});

add_filter('woocommerce_email_heading_customer_new_account', function (): string {
    return 'Witamy w GPSWISS';
});

add_filter('woocommerce_email_heading_customer_on_hold_order', function (): string {
    return 'Przyjęliśmy Twoje zamówienie';
});

add_filter('woocommerce_email_heading_customer_processing_order', function (): string {
    return 'Płatność potwierdzona';
});

add_filter('woocommerce_email_heading_customer_completed_order', function (): string {
    return 'Zamówienie wysłane';
});

add_filter('woocommerce_email_additional_content_customer_new_account', function (): string {
    return "Dziękujemy za rejestrację konta w GPSWISS.\n\nW razie pytań jesteśmy do dyspozycji: biuro@gpswiss.pl";
});

add_filter('woocommerce_email_additional_content_customer_on_hold_order', function (): string {
    return "Dziękujemy za złożenie zamówienia. Po zaksięgowaniu płatności przejdziemy do realizacji.\n\nW przypadku pytań napisz: biuro@gpswiss.pl";
});

add_filter('woocommerce_email_additional_content_customer_processing_order', function (): string {
    return "Otrzymaliśmy płatność i przekazaliśmy zamówienie do realizacji. O wysyłce poinformujemy osobną wiadomością.";
});

add_filter('woocommerce_email_additional_content_customer_completed_order', function (): string {
    return "Dobre wiadomości — zamówienie zostało wysłane. W razie pytań dotyczących dostawy napisz: biuro@gpswiss.pl";
});
