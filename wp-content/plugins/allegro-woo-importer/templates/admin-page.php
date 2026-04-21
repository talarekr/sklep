<?php
/**
 * @var array  $settings
 * @var array  $history
 * @var string $oauth_url
 * @var string $callback_uri
 * @var string $log_tail
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Allegro Woo Importer', 'allegro-woo-importer'); ?></h1>

    <?php settings_errors('awi_messages'); ?>

    <h2><?php esc_html_e('1. Ustawienia połączenia Allegro', 'allegro-woo-importer'); ?></h2>
    <form method="post" action="options.php">
        <?php settings_fields('awi_settings_group'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="awi-client-id">Client ID</label></th>
                <td><input id="awi-client-id" class="regular-text" name="<?php echo esc_attr($option_key); ?>[client_id]" value="<?php echo esc_attr($settings['client_id']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="awi-client-secret">Client Secret</label></th>
                <td><input id="awi-client-secret" class="regular-text" name="<?php echo esc_attr($option_key); ?>[client_secret]" value="<?php echo esc_attr($settings['client_secret']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="awi-redirect-uri">Redirect URI</label></th>
                <td><input id="awi-redirect-uri" class="regular-text" name="<?php echo esc_attr($option_key); ?>[redirect_uri]" value="<?php echo esc_attr($settings['redirect_uri']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Środowisko', 'allegro-woo-importer'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[environment]">
                        <option value="production" <?php selected($settings['environment'], 'production'); ?>>Production</option>
                        <option value="sandbox" <?php selected($settings['environment'], 'sandbox'); ?>>Sandbox</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Tryb synchronizacji', 'allegro-woo-importer'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[sync_mode]">
                        <option value="create_only" <?php selected($settings['sync_mode'], 'create_only'); ?>><?php esc_html_e('Tylko twórz nowe', 'allegro-woo-importer'); ?></option>
                        <option value="update_only" <?php selected($settings['sync_mode'], 'update_only'); ?>><?php esc_html_e('Tylko aktualizuj istniejące', 'allegro-woo-importer'); ?></option>
                        <option value="create_update" <?php selected($settings['sync_mode'], 'create_update'); ?>><?php esc_html_e('Twórz i aktualizuj', 'allegro-woo-importer'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Status produktu dla nieaktywnej oferty', 'allegro-woo-importer'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[inactive_product_status]">
                        <option value="draft" <?php selected($settings['inactive_product_status'], 'draft'); ?>>Draft</option>
                        <option value="private" <?php selected($settings['inactive_product_status'], 'private'); ?>>Private</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Filtr statusu ofert', 'allegro-woo-importer'); ?></th>
                <td><input class="regular-text" name="<?php echo esc_attr($option_key); ?>[offer_status]" value="<?php echo esc_attr($settings['offer_status']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Auto-sync (WP-Cron)', 'allegro-woo-importer'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[cron_interval]">
                        <option value="manual" <?php selected($settings['cron_interval'], 'manual'); ?>><?php esc_html_e('Tylko ręcznie', 'allegro-woo-importer'); ?></option>
                        <option value="awi_15_minutes" <?php selected($settings['cron_interval'], 'awi_15_minutes'); ?>><?php esc_html_e('Co 15 minut', 'allegro-woo-importer'); ?></option>
                        <option value="hourly" <?php selected($settings['cron_interval'], 'hourly'); ?>><?php esc_html_e('Co godzinę', 'allegro-woo-importer'); ?></option>
                        <option value="daily" <?php selected($settings['cron_interval'], 'daily'); ?>><?php esc_html_e('Raz dziennie', 'allegro-woo-importer'); ?></option>
                    </select>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Zapisz ustawienia', 'allegro-woo-importer')); ?>
    </form>

    <h2><?php esc_html_e('2. Połączenie OAuth i ręczny import', 'allegro-woo-importer'); ?></h2>
    <p>
        <a class="button button-primary" href="<?php echo esc_url($oauth_url); ?>"><?php esc_html_e('Połącz z Allegro', 'allegro-woo-importer'); ?></a>
        <strong style="margin-left: 12px;"><?php esc_html_e('Status połączenia:', 'allegro-woo-importer'); ?></strong>
        <?php echo !empty($settings['access_token']) ? esc_html__('Połączono', 'allegro-woo-importer') : esc_html__('Brak połączenia', 'allegro-woo-importer'); ?>
    </p>
    <p>
        <?php esc_html_e('Callback OAuth (ustaw ten sam URI w aplikacji Allegro):', 'allegro-woo-importer'); ?>
        <code><?php echo esc_html($callback_uri); ?></code>
    </p>
    <p>
        <?php esc_html_e('Wygaśnięcie access tokena:', 'allegro-woo-importer'); ?>
        <code><?php echo esc_html((string) ($settings['token_expires_at'] ?: '—')); ?></code>
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('awi_manual_import'); ?>
        <input type="hidden" name="action" value="awi_manual_import">
        <?php submit_button(__('Importuj teraz', 'allegro-woo-importer'), 'secondary', 'submit', false); ?>
    </form>

    <h2><?php esc_html_e('3. Historia importów / log', 'allegro-woo-importer'); ?></h2>
    <table class="widefat striped" style="max-width:1000px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Data', 'allegro-woo-importer'); ?></th>
                <th><?php esc_html_e('Oferty', 'allegro-woo-importer'); ?></th>
                <th><?php esc_html_e('Utworzone', 'allegro-woo-importer'); ?></th>
                <th><?php esc_html_e('Zaktualizowane', 'allegro-woo-importer'); ?></th>
                <th><?php esc_html_e('Błędy', 'allegro-woo-importer'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($history)) : ?>
                <tr><td colspan="5"><?php esc_html_e('Brak historii importów.', 'allegro-woo-importer'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($history as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($row['date'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['offers'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) ($row['created'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) ($row['updated'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) ($row['errors'] ?? 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3><?php esc_html_e('Tail logu (uploads/allegro-import.log)', 'allegro-woo-importer'); ?></h3>
    <textarea readonly style="width:100%; min-height:220px; font-family: monospace;"><?php echo esc_textarea($log_tail); ?></textarea>

    <h2><?php esc_html_e('4. Statystyki', 'allegro-woo-importer'); ?></h2>
    <ul>
        <li><?php esc_html_e('Ostatnia synchronizacja:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ($settings['last_sync_at'] ?: '—')); ?></strong></li>
        <li><?php esc_html_e('Liczba zaimportowanych produktów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) $settings['last_sync_created']); ?></strong></li>
        <li><?php esc_html_e('Liczba zaktualizowanych produktów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) $settings['last_sync_updated']); ?></strong></li>
        <li><?php esc_html_e('Liczba błędów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) $settings['last_sync_errors']); ?></strong></li>
    </ul>
</div>
