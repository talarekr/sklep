<?php
/**
 * @var array  $settings
 * @var array  $history
 * @var string $oauth_url
 * @var string $callback_uri
 * @var array  $listing_regen_checkpoint
 * @var array  $listing_last_batch
 * @var string $log_tail
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!isset($option_key) || !is_string($option_key) || $option_key == '') {
    $option_key = \AWI\Plugin::OPTION_KEY;
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
                <th scope="row"><?php esc_html_e('Reconciliation (ukrywanie niewidzianych ofert)', 'allegro-woo-importer'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[reconciliation_enabled]" value="1" <?php checked(!empty($settings['reconciliation_enabled'])); ?> />
                        <?php esc_html_e('Włącz reconciliation (domyślnie WYŁĄCZONE dla bezpieczeństwa)', 'allegro-woo-importer'); ?>
                    </label>
                </td>
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
            <tr>
                <th scope="row"><?php esc_html_e('Tryb awaryjny', 'allegro-woo-importer'); ?></th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr(\AWI\Plugin::SAFE_MODE_OPTION_KEY); ?>" value="0" />
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(\AWI\Plugin::SAFE_MODE_OPTION_KEY); ?>" value="1" <?php checked(\AWI\Plugin::is_safe_mode_enabled()); ?> />
                        <?php esc_html_e('Włącz tryb awaryjny (blokuje import, cron i diagnostykę)', 'allegro-woo-importer'); ?>
                    </label>
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
        <code><?php echo esc_html((string) (($settings['expires_at'] ?? $settings['token_expires_at']) ?: '—')); ?></code>
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('awi_manual_import'); ?>
        <input type="hidden" name="action" value="awi_manual_import">
        <?php submit_button(__('Importuj teraz', 'allegro-woo-importer'), 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
        <?php wp_nonce_field('awi_restore_active_offers'); ?>
        <input type="hidden" name="action" value="awi_restore_active_offers">
        <?php submit_button(__('Recovery: przywróć ACTIVE do instock', 'allegro-woo-importer'), 'secondary', 'submit', false); ?>
    </form>

    <h2><?php esc_html_e('3. Regeneracja zdjęć listingowych (lokalny batch)', 'allegro-woo-importer'); ?></h2>
    <p><?php esc_html_e('Ta operacja działa wyłącznie na lokalnych attachmentach i plikach z uploads (bez zewnętrznych requestów HTTP).', 'allegro-woo-importer'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('awi_listing_images_regenerate_batch'); ?>
        <input type="hidden" name="action" value="awi_listing_images_regenerate_batch">
        <label for="awi-listing-batch-size"><?php esc_html_e('Batch size:', 'allegro-woo-importer'); ?></label>
        <input id="awi-listing-batch-size" type="number" min="1" max="50" name="awi_listing_batch_size" value="10" style="width:80px; margin-right:12px;">
        <label style="margin-right:12px;">
            <input type="checkbox" name="awi_listing_reset_checkpoint" value="1">
            <?php esc_html_e('Reset checkpoint (start od początku)', 'allegro-woo-importer'); ?>
        </label>
        <label style="margin-right:12px;">
            <input type="checkbox" name="awi_listing_force_regenerate" value="1">
            <?php esc_html_e('Force regenerate (ignoruj istniejący listing image)', 'allegro-woo-importer'); ?>
        </label>
        <?php submit_button(__('Uruchom batch regeneracji', 'allegro-woo-importer'), 'secondary', 'submit', false); ?>
    </form>
    <ul>
        <li><?php esc_html_e('Ostatni produkt (checkpoint):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['last_product_id'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie przetworzono:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['processed_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie utworzono listing image:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['created_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie pominięto:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['skipped_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Łącznie błędów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_regen_checkpoint['error_total'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Aktualizacja checkpointu:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ($listing_regen_checkpoint['updated_at'] ?? '—')); ?></strong></li>
    </ul>

    <h2><?php esc_html_e('4. Diagnostyka renderingu zdjęć listingowych (ostatni batch)', 'allegro-woo-importer'); ?></h2>
    <p><?php esc_html_e('Uruchamia diagnostykę dokładnie dla produktów z ostatniego batcha regeneracji i zapisuje szczegóły do logu.', 'allegro-woo-importer'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('awi_listing_images_inspect_front'); ?>
        <input type="hidden" name="action" value="awi_listing_images_inspect_front">
        <?php submit_button(__('Sprawdź ostatni batch', 'allegro-woo-importer'), 'secondary', 'submit', false); ?>
    </form>
    <ul>
        <li><?php esc_html_e('Produkty w ostatnim batchu:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['processed'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Pierwszy product_id (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['first_product_id'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Ostatni product_id (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['last_product_id'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Batch size (ustawiony):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['batch_size'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Utworzono listing image (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['created'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Pominięto (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['skipped'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Błędy (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['errors'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Preferred (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['preferred_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Acceptable (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['acceptable_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Degraded (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['degraded_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Last resort (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['last_resort_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Requires better source (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ((int) ($listing_last_batch['requires_better_source_count'] ?? 0))); ?></strong></li>
        <li><?php esc_html_e('Force regenerate (ostatni batch):', 'allegro-woo-importer'); ?> <strong><?php echo esc_html(!empty($listing_last_batch['force_regenerate']) ? 'true' : 'false'); ?></strong></li>
        <li><?php esc_html_e('Timestamp ostatniego batcha:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ($listing_last_batch['updated_at'] ?? '—')); ?></strong></li>
    </ul>
    <p><em><?php esc_html_e('W logu pojawią się pola: product_id, product_name, permalink, rendered_source, helper_selected_image_id, listing_image_id, featured_image_id, candidate_source_image_ids, selected_source_image_id, selected_source_aspect_ratio, selected_source_selection_reason, listing_quality_tier, listing_quality_score, best_available_source_quality_tier, requires_better_source, gallery_images_count, listing_file_exists, listing_attachment_scale_factor, listing_attachment_target_fill_ratio, aspect_ratio, is_extreme_aspect_ratio, fit_limited_by.', 'allegro-woo-importer'); ?></em></p>

    <h2><?php esc_html_e('5. Historia importów / log', 'allegro-woo-importer'); ?></h2>
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

    <h2><?php esc_html_e('6. Statystyki', 'allegro-woo-importer'); ?></h2>
    <ul>
        <li><?php esc_html_e('Ostatnia synchronizacja:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) ($settings['last_sync_at'] ?: '—')); ?></strong></li>
        <li><?php esc_html_e('Liczba zaimportowanych produktów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) $settings['last_sync_created']); ?></strong></li>
        <li><?php esc_html_e('Liczba zaktualizowanych produktów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) $settings['last_sync_updated']); ?></strong></li>
        <li><?php esc_html_e('Liczba błędów:', 'allegro-woo-importer'); ?> <strong><?php echo esc_html((string) $settings['last_sync_errors']); ?></strong></li>
    </ul>
</div>
