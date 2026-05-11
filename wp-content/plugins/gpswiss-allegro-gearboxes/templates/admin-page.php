<?php
/**
 * @var array  $settings
 * @var array  $history
 * @var string $oauth_url
 * @var string $callback_uri
 * @var array  $listing_regen_checkpoint
 * @var array  $listing_last_batch
 * @var array  $import_lock_status
 * @var array  $missing_import_checkpoint
 * @var array  $event_sync_status
 * @var string $log_tail
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!isset($option_key) || !is_string($option_key) || $option_key == '') {
    $option_key = \GAG\Plugin::OPTION_KEY;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('GPSwiss Allegro Gearboxes', 'gpswiss-allegro-gearboxes'); ?></h1>

    <?php settings_errors('gag_messages'); ?>

    <h2><?php esc_html_e('1. Ustawienia połączenia Allegro', 'gpswiss-allegro-gearboxes'); ?></h2>
    <form method="post" action="options.php">
        <?php settings_fields('gag_settings_group'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="gag-client-id">Client ID</label></th>
                <td><input id="gag-client-id" class="regular-text" name="<?php echo esc_attr($option_key); ?>[client_id]" value="<?php echo esc_attr($settings['client_id']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="gag-client-secret">Client Secret</label></th>
                <td><input id="gag-client-secret" class="regular-text" name="<?php echo esc_attr($option_key); ?>[client_secret]" value="<?php echo esc_attr($settings['client_secret']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="gag-redirect-uri">Redirect URI</label></th>
                <td><input id="gag-redirect-uri" class="regular-text" name="<?php echo esc_attr($option_key); ?>[redirect_uri]" value="<?php echo esc_attr($settings['redirect_uri']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Środowisko', 'gpswiss-allegro-gearboxes'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[environment]">
                        <option value="production" <?php selected($settings['environment'], 'production'); ?>>Production</option>
                        <option value="sandbox" <?php selected($settings['environment'], 'sandbox'); ?>>Sandbox</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Tryb synchronizacji', 'gpswiss-allegro-gearboxes'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[sync_mode]">
                        <option value="create_only" <?php selected($settings['sync_mode'], 'create_only'); ?>><?php esc_html_e('Tylko twórz nowe', 'gpswiss-allegro-gearboxes'); ?></option>
                        <option value="update_only" <?php selected($settings['sync_mode'], 'update_only'); ?>><?php esc_html_e('Tylko aktualizuj istniejące', 'gpswiss-allegro-gearboxes'); ?></option>
                        <option value="create_update" <?php selected($settings['sync_mode'], 'create_update'); ?>><?php esc_html_e('Twórz i aktualizuj', 'gpswiss-allegro-gearboxes'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Status produktu dla nieaktywnej oferty', 'gpswiss-allegro-gearboxes'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[inactive_product_status]">
                        <option value="draft" <?php selected($settings['inactive_product_status'], 'draft'); ?>>Draft</option>
                        <option value="private" <?php selected($settings['inactive_product_status'], 'private'); ?>>Private</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Filtr statusu ofert', 'gpswiss-allegro-gearboxes'); ?></th>
                <td><input class="regular-text" name="<?php echo esc_attr($option_key); ?>[offer_status]" value="<?php echo esc_attr($settings['offer_status']); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Reconciliation (ukrywanie niewidzianych ofert)', 'gpswiss-allegro-gearboxes'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[reconciliation_enabled]" value="1" <?php checked(!empty($settings['reconciliation_enabled'])); ?> />
                        <?php esc_html_e('Włącz reconciliation (domyślnie WYŁĄCZONE dla bezpieczeństwa)', 'gpswiss-allegro-gearboxes'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Auto-sync (WP-Cron)', 'gpswiss-allegro-gearboxes'); ?></th>
                <td>
                    <select name="<?php echo esc_attr($option_key); ?>[cron_interval]">
                        <option value="manual" <?php selected($settings['cron_interval'], 'manual'); ?>><?php esc_html_e('Tylko ręcznie', 'gpswiss-allegro-gearboxes'); ?></option>
                        <option value="gag_15_minutes" <?php selected($settings['cron_interval'], 'gag_15_minutes'); ?>><?php esc_html_e('Co 15 minut', 'gpswiss-allegro-gearboxes'); ?></option>
                        <option value="hourly" <?php selected($settings['cron_interval'], 'hourly'); ?>><?php esc_html_e('Co godzinę', 'gpswiss-allegro-gearboxes'); ?></option>
                        <option value="daily" <?php selected($settings['cron_interval'], 'daily'); ?>><?php esc_html_e('Raz dziennie', 'gpswiss-allegro-gearboxes'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Tryb awaryjny', 'gpswiss-allegro-gearboxes'); ?></th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr(\GAG\Plugin::SAFE_MODE_OPTION_KEY); ?>" value="0" />
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(\GAG\Plugin::SAFE_MODE_OPTION_KEY); ?>" value="1" <?php checked(\GAG\Plugin::is_safe_mode_enabled()); ?> />
                        <?php esc_html_e('Włącz tryb awaryjny (blokuje import, cron i diagnostykę)', 'gpswiss-allegro-gearboxes'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Zapisz ustawienia', 'gpswiss-allegro-gearboxes')); ?>
    </form>

    <h2><?php esc_html_e('2. Połączenie OAuth i ręczny import', 'gpswiss-allegro-gearboxes'); ?></h2>
    <p>
        <a class="button button-primary" href="<?php echo esc_url($oauth_url); ?>"><?php esc_html_e('Połącz z Allegro', 'gpswiss-allegro-gearboxes'); ?></a>
        <strong style="margin-left: 12px;"><?php esc_html_e('Status połączenia:', 'gpswiss-allegro-gearboxes'); ?></strong>
        <?php echo !empty($settings['access_token']) ? esc_html__('Połączono', 'gpswiss-allegro-gearboxes') : esc_html__('Brak połączenia', 'gpswiss-allegro-gearboxes'); ?>
    </p>
    <p>
        <?php esc_html_e('Callback OAuth (ustaw ten sam URI w aplikacji Allegro):', 'gpswiss-allegro-gearboxes'); ?>
        <code><?php echo esc_html($callback_uri); ?></code>
    </p>
    <p>
        <?php esc_html_e('Wygaśnięcie access tokena:', 'gpswiss-allegro-gearboxes'); ?>
        <code><?php echo esc_html((string) (($settings['expires_at'] ?? $settings['token_expires_at']) ?: '—')); ?></code>
    </p>
    <?php if (!empty($settings['gag_order_events_access_denied_notice'])) : ?>
        <p style="color:#b32d2e; font-weight:600;">
            <?php esc_html_e('Brak uprawnień Allegro do odczytu zamówień. Sprzedaże z Allegro nie będą wykrywane przez order-events.', 'gpswiss-allegro-gearboxes'); ?>
            <?php esc_html_e('Kliknij "Połącz z Allegro", aby wymusić ponowną autoryzację konta z właściwymi scope.', 'gpswiss-allegro-gearboxes'); ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gag_manual_import'); ?>
        <input type="hidden" name="action" value="gag_manual_import">
        <label for="gag-start-offset"><?php esc_html_e('Start offset:', 'gpswiss-allegro-gearboxes'); ?></label>
        <input id="gag-start-offset" type="number" min="0" name="gag_start_offset" placeholder="np. 5100" style="width:110px; margin-right:10px;">
        <label for="gag-start-page"><?php esc_html_e('Start page:', 'gpswiss-allegro-gearboxes'); ?></label>
        <input id="gag-start-page" type="number" min="1" name="gag_start_page" placeholder="np. 171" style="width:100px; margin-right:10px;">
        <label for="gag-start-offer-index"><?php esc_html_e('Start index:', 'gpswiss-allegro-gearboxes'); ?></label>
        <input id="gag-start-offer-index" type="number" min="0" name="gag_start_offer_index" value="0" style="width:90px; margin-right:12px;">
        <?php submit_button(__('Importuj teraz', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
        <?php wp_nonce_field('gag_restore_active_offers'); ?>
        <input type="hidden" name="action" value="gag_restore_active_offers">
        <?php submit_button(__('Recovery: przywróć ACTIVE do instock', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <?php if (current_user_can('manage_options')) : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
        <?php wp_nonce_field('gag_manual_sync_trigger'); ?>
        <input type="hidden" name="action" value="gag_manual_sync_trigger">
        <?php submit_button(__('Uruchom synchronizację teraz', 'gpswiss-allegro-gearboxes'), 'primary', 'submit', false); ?>
    </form>
    <?php endif; ?>

    <h3><?php esc_html_e('Import missing Allegro offers', 'gpswiss-allegro-gearboxes'); ?></h3>
    <p><?php esc_html_e('Tryb skanuje aktywne oferty Allegro batchami i importuje wyłącznie brakujące produkty.', 'gpswiss-allegro-gearboxes'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
        <?php wp_nonce_field('gag_missing_import_start'); ?>
        <input type="hidden" name="action" value="gag_missing_import_start">
        <?php submit_button(__('Start import missing', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
        <?php wp_nonce_field('gag_missing_import_continue'); ?>
        <input type="hidden" name="action" value="gag_missing_import_continue">
        <?php submit_button(__('Continue import missing', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
        <?php wp_nonce_field('gag_missing_import_pause'); ?>
        <input type="hidden" name="action" value="gag_missing_import_pause">
        <?php submit_button(__('Stop/Pause', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
        <?php wp_nonce_field('gag_missing_import_reset'); ?>
        <input type="hidden" name="action" value="gag_missing_import_reset">
        <?php submit_button(__('Reset missing import checkpoint', 'gpswiss-allegro-gearboxes'), 'delete', 'submit', false); ?>
    </form>
    <ul>
        <li><?php esc_html_e('Status:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ($missing_import_checkpoint['status'] ?? 'paused')); ?></strong></li>
        <li><?php esc_html_e('Offset / Total:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($missing_import_checkpoint['current_offset'] ?? 0))); ?> / <?php echo esc_html(isset($missing_import_checkpoint['total_count']) && $missing_import_checkpoint['total_count'] !== null ? (string) ((int) $missing_import_checkpoint['total_count']) : '—'); ?></strong></li>
        <li><?php esc_html_e('Total checked:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($missing_import_checkpoint['total_checked'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Skipped existing:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($missing_import_checkpoint['existing_skipped'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Imported missing:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($missing_import_checkpoint['missing_imported'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Errors:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($missing_import_checkpoint['errors'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Last checked offer_id:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($missing_import_checkpoint['last_checked_offer_id'] ?? '') !== '' ? $missing_import_checkpoint['last_checked_offer_id'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Last imported offer_id:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($missing_import_checkpoint['last_imported_offer_id'] ?? '') !== '' ? $missing_import_checkpoint['last_imported_offer_id'] : '—')); ?></strong></li>
    </ul>

    <h3><?php esc_html_e('Event sync status', 'gpswiss-allegro-gearboxes'); ?></h3>
    <ul>
        <li><?php esc_html_e('Last event sync run:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['last_run_at'] ?? '') !== '' ? $event_sync_status['last_run_at'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Last run mode:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['last_run_mode'] ?? '') !== '' ? $event_sync_status['last_run_mode'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Last status:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['last_status'] ?? '') !== '' ? $event_sync_status['last_status'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Last event sync error:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['last_error'] ?? '') !== '' ? $event_sync_status['last_error'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Checkpoint last_offer_event_id:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['checkpoint']['last_offer_event_id'] ?? '') !== '' ? $event_sync_status['checkpoint']['last_offer_event_id'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Checkpoint last_order_event_id:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['checkpoint']['last_order_event_id'] ?? '') !== '' ? $event_sync_status['checkpoint']['last_order_event_id'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Checkpoint last_success_at:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($event_sync_status['checkpoint']['last_success_at'] ?? '') !== '' ? $event_sync_status['checkpoint']['last_success_at'] : '—')); ?></strong></li>
    </ul>

    <h3><?php esc_html_e('Status głównego import locka', 'gpswiss-allegro-gearboxes'); ?></h3>
    <ul>
        <li><?php esc_html_e('Lock option key:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ($import_lock_status['option_key'] ?? 'gag_import_lock')); ?></strong></li>
        <li><?php esc_html_e('Lock obecny:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo !empty($import_lock_status['has_lock']) ? 'true' : 'false'; ?></strong></li>
        <li><?php esc_html_e('Status:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo !empty($import_lock_status['is_active']) ? esc_html__('active', 'gpswiss-allegro-gearboxes') : (!empty($import_lock_status['is_stale']) ? esc_html__('stale', 'gpswiss-allegro-gearboxes') : esc_html__('none', 'gpswiss-allegro-gearboxes')); ?></strong></li>
        <li><?php esc_html_e('Locked at (GMT):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($import_lock_status['locked_at'] ?? '') !== '' ? $import_lock_status['locked_at'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Expires at (GMT):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) (($import_lock_status['expires_at_gmt'] ?? '') !== '' ? $import_lock_status['expires_at_gmt'] : '—')); ?></strong></li>
        <li><?php esc_html_e('Expires at (timestamp):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($import_lock_status['expires_at_ts'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Now (GMT):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ($import_lock_status['now_gmt'] ?? '—')); ?></strong></li>
        <li><?php esc_html_e('Sekundy do wygaśnięcia:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($import_lock_status['seconds_to_expiry'] ?? 0))); ?></strong></li>
    </ul>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
        <?php wp_nonce_field('gag_clear_import_lock'); ?>
        <input type="hidden" name="action" value="gag_clear_import_lock">
        <?php submit_button(__('Clear importer lock', 'gpswiss-allegro-gearboxes'), 'delete', 'submit', false); ?>
    </form>

    <h2><?php esc_html_e('3. Regeneracja zdjęć listingowych (lokalny batch)', 'gpswiss-allegro-gearboxes'); ?></h2>
    <p><?php esc_html_e('Ta operacja działa wyłącznie na lokalnych attachmentach i plikach z uploads (bez zewnętrznych requestów HTTP).', 'gpswiss-allegro-gearboxes'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gag_listing_images_regenerate_batch'); ?>
        <input type="hidden" name="action" value="gag_listing_images_regenerate_batch">
        <label for="gag-listing-batch-size"><?php esc_html_e('Batch size:', 'gpswiss-allegro-gearboxes'); ?></label>
        <input id="gag-listing-batch-size" type="number" min="1" max="400" name="gag_listing_batch_size" value="10" style="width:80px; margin-right:12px;">
        <label style="margin-right:12px;">
            <input type="checkbox" name="gag_listing_reset_checkpoint" value="1">
            <?php esc_html_e('Reset checkpoint (start od początku)', 'gpswiss-allegro-gearboxes'); ?>
        </label>
        <label style="margin-right:12px;">
            <input type="checkbox" name="gag_listing_force_regenerate" value="1">
            <?php esc_html_e('Force regenerate (ignoruj istniejący listing image)', 'gpswiss-allegro-gearboxes'); ?>
        </label>
        <?php submit_button(__('Uruchom batch regeneracji', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <ul>
        <li><?php esc_html_e('Ostatni produkt (checkpoint):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['last_product_id'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie przetworzono:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['processed_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie utworzono listing image:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['created_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie pominięto:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['skipped_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie błędów:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['error_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Aktualizacja checkpointu:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ($listing_regen_checkpoint['updated_at'] ?? '—')); ?></strong></li>
    </ul>

    <h2><?php esc_html_e('4. Diagnostyka renderingu zdjęć listingowych (ostatni batch)', 'gpswiss-allegro-gearboxes'); ?></h2>
    <p><?php esc_html_e('Uruchamia diagnostykę dokładnie dla produktów z ostatniego batcha regeneracji i zapisuje szczegóły do logu.', 'gpswiss-allegro-gearboxes'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gag_listing_images_inspect_front'); ?>
        <input type="hidden" name="action" value="gag_listing_images_inspect_front">
        <?php submit_button(__('Sprawdź ostatni batch', 'gpswiss-allegro-gearboxes'), 'secondary', 'submit', false); ?>
    </form>
    <ul>
        <li><?php esc_html_e('Produkty w ostatnim batchu:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['processed'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Pierwszy product_id (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['first_product_id'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Ostatni product_id (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['last_product_id'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Batch size (ustawiony):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['batch_size'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Utworzono listing image (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['created'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Pominięto (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['skipped'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Błędy (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['errors'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Preferred (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['preferred_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Acceptable (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['acceptable_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Degraded (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['degraded_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Last resort (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['last_resort_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Requires better source (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['requires_better_source_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Force regenerate (ostatni batch):', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html(!empty($listing_last_batch['force_regenerate']) ? 'true' : 'false'); ?></strong></li>
        <li><?php esc_html_e('Timestamp ostatniego batcha:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ($listing_last_batch['updated_at'] ?? '—')); ?></strong></li>
    </ul>
    <p><em><?php esc_html_e('W logu pojawią się pola: product_id, product_name, permalink, rendered_source, helper_selected_image_id, listing_image_id, featured_image_id, candidate_source_image_ids, selected_source_image_id, selected_source_aspect_ratio, selected_source_selection_reason, standard_quality_tier_before_boost, final_quality_tier_after_boost, listing_quality_tier, listing_quality_score, best_available_source_quality_tier, requires_better_source, quality_boost_applied, quality_boost_upgraded, fill_ratio, render_profile, gallery_images_count, listing_file_exists, listing_attachment_scale_factor, listing_attachment_target_fill_ratio, aspect_ratio, is_extreme_aspect_ratio, fit_limited_by.', 'gpswiss-allegro-gearboxes'); ?></em></p>

    <h2><?php esc_html_e('5. Historia importów / log', 'gpswiss-allegro-gearboxes'); ?></h2>
    <table class="widefat striped" style="max-width:1000px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Data', 'gpswiss-allegro-gearboxes'); ?></th>
                <th><?php esc_html_e('Oferty', 'gpswiss-allegro-gearboxes'); ?></th>
                <th><?php esc_html_e('Utworzone', 'gpswiss-allegro-gearboxes'); ?></th>
                <th><?php esc_html_e('Zaktualizowane', 'gpswiss-allegro-gearboxes'); ?></th>
                <th><?php esc_html_e('Błędy', 'gpswiss-allegro-gearboxes'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($history)) : ?>
                <tr><td colspan="5"><?php esc_html_e('Brak historii importów.', 'gpswiss-allegro-gearboxes'); ?></td></tr>
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

    <h3><?php esc_html_e('Tail logu (uploads/gpswiss-allegro-gearboxes.log)', 'gpswiss-allegro-gearboxes'); ?></h3>
    <textarea readonly style="width:100%; min-height:220px; font-family: monospace;"><?php echo esc_textarea($log_tail); ?></textarea>

    <h2><?php esc_html_e('6. Statystyki', 'gpswiss-allegro-gearboxes'); ?></h2>
    <ul>
        <li><?php esc_html_e('Ostatnia synchronizacja:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) ($settings['last_sync_at'] ?: '—')); ?></strong></li>
        <li><?php esc_html_e('Liczba zaimportowanych produktów:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) $settings['last_sync_created']); ?></strong></li>
        <li><?php esc_html_e('Liczba zaktualizowanych produktów:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) $settings['last_sync_updated']); ?></strong></li>
        <li><?php esc_html_e('Liczba błędów:', 'gpswiss-allegro-gearboxes'); ?> <strong><?php echo esc_html((string) $settings['last_sync_errors']); ?></strong></li>
    </ul>
</div>
