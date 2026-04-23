<?php
/**
 * Email Header (GPSWISS override)
 *
 * @package gp-clone
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo esc_html(get_bloginfo('name')); ?></title>
</head>
<body marginwidth="0" topmargin="0" marginheight="0" offset="0" style="background:#f4f6fb;margin:0;padding:16px 8px;">
    <div style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">
        <?php echo isset($email_heading) ? esc_html(wp_strip_all_tags((string) $email_heading)) : ''; ?>
    </div>
    <table border="0" cellpadding="0" cellspacing="0" width="100%" id="outer_wrapper" style="background:#f4f6fb;">
        <tr>
            <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" style="max-width:680px;background:#ffffff;border:1px solid #dbe1ec;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td align="left" valign="top" id="template_header" style="background:#122a66;color:#ffffff;padding:20px 24px;font-size:22px;font-weight:700;">
                            GPSWISS
                        </td>
                    </tr>
                    <tr>
                        <td id="header_wrapper" style="padding:24px 24px 0;">
                            <h1 style="font-family:Arial,sans-serif;font-size:24px;line-height:1.3;margin:0;color:#0f172a;"><?php echo esc_html($email_heading); ?></h1>
                        </td>
                    </tr>
                    <tr>
                        <td id="body_content" style="padding:12px 24px 0;font-family:Arial,sans-serif;color:#1f2937;font-size:15px;line-height:1.6;">
