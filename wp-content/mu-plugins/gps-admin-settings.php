<?php
/**
 * Plugin Name: GPS Admin Settings Structure
 * Description: Adds a calm, placeholder-only administration settings structure for future system configuration work.
 * Version: 0.1.0
 * Author: GP System
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const GPS_ADMIN_SETTINGS_PARENT_SLUG = 'gps-admin-settings';

add_action('admin_menu', 'gps_admin_settings_register_menu');
add_action('admin_enqueue_scripts', 'gps_admin_settings_enqueue_assets');

/**
 * Registers a placeholder-only settings area in the WordPress admin sidebar.
 */
function gps_admin_settings_register_menu(): void
{
    add_menu_page(
        __('Administracja / Ustawienia', 'gp-clone'),
        __('Administracja', 'gp-clone'),
        'manage_options',
        GPS_ADMIN_SETTINGS_PARENT_SLUG,
        'gps_admin_settings_render_page',
        'dashicons-admin-generic',
        58
    );

    foreach (gps_admin_settings_sections() as $section) {
        add_submenu_page(
            GPS_ADMIN_SETTINGS_PARENT_SLUG,
            $section['title'],
            $section['menu'],
            'manage_options',
            $section['slug'],
            'gps_admin_settings_render_page'
        );
    }

    remove_submenu_page(GPS_ADMIN_SETTINGS_PARENT_SLUG, GPS_ADMIN_SETTINGS_PARENT_SLUG);
}

/**
 * Adds calm admin styling only for the new settings structure pages.
 */
function gps_admin_settings_enqueue_assets(string $hook_suffix): void
{
    $current_slug = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
    $allowed_slugs = array_merge([GPS_ADMIN_SETTINGS_PARENT_SLUG], array_column(gps_admin_settings_sections(), 'slug'));

    if (!in_array($current_slug, $allowed_slugs, true)) {
        return;
    }

    wp_register_style('gps-admin-settings-structure', false, [], '0.1.0');
    wp_enqueue_style('gps-admin-settings-structure');
    wp_add_inline_style('gps-admin-settings-structure', gps_admin_settings_css());
}

/**
 * @return array<int, array<string, mixed>>
 */
function gps_admin_settings_sections(): array
{
    return [
        [
            'slug' => 'gps-settings-general',
            'menu' => __('Ustawienia ogólne', 'gp-clone'),
            'title' => __('General Settings', 'gp-clone'),
            'group' => __('Core system', 'gp-clone'),
            'summary' => __('Podstawowe ustawienia działania panelu i sklepu.', 'gp-clone'),
            'later' => [
                __('Global defaults and operational preferences.', 'gp-clone'),
                __('Admin language, timezone, formatting and safe defaults.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-company-identity',
            'menu' => __('Firma / sklep', 'gp-clone'),
            'title' => __('Company / Shop Identity', 'gp-clone'),
            'group' => __('Core system', 'gp-clone'),
            'summary' => __('Dane identyfikacyjne firmy oraz sklepu do późniejszego użycia w dokumentach i kanałach.', 'gp-clone'),
            'later' => [
                __('Company name, shop display name and contact defaults.', 'gp-clone'),
                __('Legal and branding references used by integrations.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-users-roles',
            'menu' => __('Użytkownicy i role', 'gp-clone'),
            'title' => __('Users & Roles', 'gp-clone'),
            'group' => __('Core system', 'gp-clone'),
            'summary' => __('Miejsce na przyszłe role, uprawnienia i bezpieczny podział obowiązków.', 'gp-clone'),
            'later' => [
                __('Role matrix for warehouse, catalog, pricing and channel operations.', 'gp-clone'),
                __('Permission boundaries for risky actions and approvals.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-products',
            'menu' => __('Produkty', 'gp-clone'),
            'title' => __('Product Settings', 'gp-clone'),
            'group' => __('Catalog', 'gp-clone'),
            'summary' => __('Placeholder dla reguł katalogu produktów bez wdrażania logiki produktowej.', 'gp-clone'),
            'later' => [
                __('Product data requirements and default catalog behaviour.', 'gp-clone'),
                __('Rules for titles, identifiers and internal product statuses.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-product-intake',
            'menu' => __('Przyjęcie produktów', 'gp-clone'),
            'title' => __('Product Intake Settings', 'gp-clone'),
            'group' => __('Catalog', 'gp-clone'),
            'summary' => __('Struktura pod przyszłą konfigurację przyjęcia towaru, bez workflow i bez logiki intake.', 'gp-clone'),
            'later' => [
                __('Intake checklists, required photos and validation stages.', 'gp-clone'),
                __('Warehouse handoff rules for new or returned products.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-categories',
            'menu' => __('Kategorie', 'gp-clone'),
            'title' => __('Categories', 'gp-clone'),
            'group' => __('Catalog', 'gp-clone'),
            'summary' => __('Miejsce na przyszłe reguły kategorii i mapowania, bez zmiany istniejącego katalogu.', 'gp-clone'),
            'later' => [
                __('Internal category policy and required category metadata.', 'gp-clone'),
                __('Future channel category mapping entry points.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-attributes',
            'menu' => __('Atrybuty / parametry', 'gp-clone'),
            'title' => __('Attributes / Parameters', 'gp-clone'),
            'group' => __('Catalog', 'gp-clone'),
            'summary' => __('Placeholder na standard parametrów produktu oraz przyszłe wymagalności pól.', 'gp-clone'),
            'later' => [
                __('Attribute dictionaries, required parameters and display rules.', 'gp-clone'),
                __('Marketplace-specific parameter mapping placeholders.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-pricing',
            'menu' => __('Ceny', 'gp-clone'),
            'title' => __('Pricing Settings', 'gp-clone'),
            'group' => __('Commerce', 'gp-clone'),
            'summary' => __('Organizacja miejsca pod przyszłe reguły cenowe, bez kalkulacji cen.', 'gp-clone'),
            'later' => [
                __('Margins, rounding, channel adjustments and approval rules.', 'gp-clone'),
                __('Price safety limits and draft price review flows.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-stock-warehouse',
            'menu' => __('Magazyn / stock', 'gp-clone'),
            'title' => __('Stock / Warehouse Settings', 'gp-clone'),
            'group' => __('Commerce', 'gp-clone'),
            'summary' => __('Miejsce na przyszłą konfigurację stanów i magazynu, bez synchronizacji ani rezerwacji.', 'gp-clone'),
            'later' => [
                __('Stock policy, warehouse locations and internal availability states.', 'gp-clone'),
                __('Safety rules before any future channel stock updates.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-logistics-classes',
            'menu' => __('Klasy logistyczne', 'gp-clone'),
            'title' => __('Internal Logistics Classes', 'gp-clone'),
            'group' => __('Commerce', 'gp-clone'),
            'summary' => __('Centralne, wewnętrzne klasy logistyczne oddzielone od mapowań wysyłki marketplace.', 'gp-clone'),
            'later' => [
                __('Future internal classes: small, medium, large, oversize, pallet.', 'gp-clone'),
                __('Later mapping from internal logistics classes to channel-specific shipping policies.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-channels',
            'menu' => __('Kanały sprzedaży', 'gp-clone'),
            'title' => __('Channel Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Ogólny ekran organizacyjny dla kanałów sprzedaży, bez synchronizacji i publikowania.', 'gp-clone'),
            'later' => [
                __('Channel enablement, ownership and readiness overview.', 'gp-clone'),
                __('Safe entry points for WooCommerce, eBay, Allegro and Ovoko settings.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-woocommerce',
            'menu' => __('WooCommerce', 'gp-clone'),
            'title' => __('WooCommerce Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Placeholder pod konfigurację WooCommerce bez uruchamiania synchronizacji.', 'gp-clone'),
            'later' => [
                __('WooCommerce field ownership, catalog visibility and sync boundaries.', 'gp-clone'),
                __('Rules for future data flow between system and shop storefront.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-ebay',
            'menu' => __('eBay', 'gp-clone'),
            'title' => __('eBay Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Ogólne miejsce dla eBay i mapowań kanałowych. Istniejące grupy shipping_30, shipping_50 i shipping_130 pozostają eBay-specific.', 'gp-clone'),
            'later' => [
                __('eBay account readiness, category mapping and channel-specific shipping mapping.', 'gp-clone'),
                __('No marketplace publishing is enabled from this placeholder.', 'gp-clone'),
            ],
            'notice' => __('Shipping groups shipping_30, shipping_50 and shipping_130 are channel-specific eBay mappings, not global system shipping classes.', 'gp-clone'),
        ],
        [
            'slug' => 'gps-settings-ebay-de',
            'menu' => __('eBay DE', 'gp-clone'),
            'title' => __('eBay DE Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Placeholder pod konfigurację rynku eBay DE bez publikacji i bez wywołań API.', 'gp-clone'),
            'later' => [
                __('German marketplace category, policy and content mapping.', 'gp-clone'),
                __('Future readiness checks before any live eBay DE operation.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-ebay-fr',
            'menu' => __('eBay FR', 'gp-clone'),
            'title' => __('eBay FR Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Placeholder pod konfigurację rynku eBay FR bez publikacji i bez wywołań API.', 'gp-clone'),
            'later' => [
                __('French marketplace category, policy and content mapping.', 'gp-clone'),
                __('Future readiness checks before any live eBay FR operation.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-allegro',
            'menu' => __('Allegro', 'gp-clone'),
            'title' => __('Allegro Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Miejsce na przyszłą konfigurację Allegro, bez importu, synchronizacji i publikowania.', 'gp-clone'),
            'later' => [
                __('Allegro account boundaries, categories and parameter mapping.', 'gp-clone'),
                __('Safety controls before future offer actions.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-ovoko',
            'menu' => __('Ovoko', 'gp-clone'),
            'title' => __('Ovoko Settings', 'gp-clone'),
            'group' => __('Channels', 'gp-clone'),
            'summary' => __('Placeholder pod Ovoko bez API writes i bez publikacji.', 'gp-clone'),
            'later' => [
                __('Ovoko-specific category, stock and content boundaries.', 'gp-clone'),
                __('Future safe synchronization planning.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-translations-templates',
            'menu' => __('Tłumaczenia / szablony', 'gp-clone'),
            'title' => __('Translation / Content Templates', 'gp-clone'),
            'group' => __('Operations', 'gp-clone'),
            'summary' => __('Miejsce na przyszłe szablony treści i reguły tłumaczeń, bez generowania ani publikacji treści.', 'gp-clone'),
            'later' => [
                __('Title, description and translation template organization.', 'gp-clone'),
                __('Approval boundaries for generated or translated content.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-readiness-rules',
            'menu' => __('Reguły gotowości', 'gp-clone'),
            'title' => __('Readiness Rules', 'gp-clone'),
            'group' => __('Operations', 'gp-clone'),
            'summary' => __('Placeholder pod przyszłe warunki gotowości produktu/kanału, bez wykonywania reguł.', 'gp-clone'),
            'later' => [
                __('Required data checks before product, stock, price or channel actions.', 'gp-clone'),
                __('Blocking and review states for risky operations.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-automation-queues',
            'menu' => __('Automatyzacja / kolejki', 'gp-clone'),
            'title' => __('Automation / Queue Settings', 'gp-clone'),
            'group' => __('Operations', 'gp-clone'),
            'summary' => __('Miejsce na przyszłe ustawienia kolejek i automatyzacji. Wszystkie ryzykowne akcje pozostają wyłączone.', 'gp-clone'),
            'later' => [
                __('Queue limits, dry-run defaults and manual approval points.', 'gp-clone'),
                __('Operational safety before background jobs are introduced.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-feature-flags',
            'menu' => __('Feature flags / safety', 'gp-clone'),
            'title' => __('Feature Flags / Safety', 'gp-clone'),
            'group' => __('Safety', 'gp-clone'),
            'summary' => __('Centralne miejsce na przyszłe przełączniki bezpieczeństwa. Na tym etapie niczego nie włącza.', 'gp-clone'),
            'later' => [
                __('Feature flags for integrations, publishing, sync and risky workflows.', 'gp-clone'),
                __('Default-off safety model for future production controls.', 'gp-clone'),
            ],
        ],
        [
            'slug' => 'gps-settings-audit-log',
            'menu' => __('Audit log', 'gp-clone'),
            'title' => __('Audit Log', 'gp-clone'),
            'group' => __('Safety', 'gp-clone'),
            'summary' => __('Placeholder pod przyszły dziennik zmian konfiguracji i operacji administracyjnych.', 'gp-clone'),
            'later' => [
                __('Configuration change history and operator activity records.', 'gp-clone'),
                __('Future traceability for product, stock, price and channel actions.', 'gp-clone'),
            ],
        ],
    ];
}

/**
 * Renders one placeholder page for the requested settings section.
 */
function gps_admin_settings_render_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view these settings.', 'gp-clone'));
    }

    $sections = gps_admin_settings_sections();
    $current_slug = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : 'gps-settings-general';
    $current = gps_admin_settings_find_section($current_slug, $sections) ?? $sections[0];

    ?>
    <div class="wrap gps-admin-settings">
        <div class="gps-admin-settings__header">
            <div>
                <p class="gps-admin-settings__eyebrow"><?php esc_html_e('Administration / Settings', 'gp-clone'); ?></p>
                <h1><?php echo esc_html($current['title']); ?></h1>
                <p class="gps-admin-settings__lead"><?php echo esc_html($current['summary']); ?></p>
            </div>
            <span class="gps-admin-settings__badge gps-admin-settings__badge--draft"><?php esc_html_e('Structure only', 'gp-clone'); ?></span>
        </div>

        <div class="gps-admin-settings__layout">
            <aside class="gps-admin-settings__nav" aria-label="<?php esc_attr_e('Settings sections', 'gp-clone'); ?>">
                <?php foreach (gps_admin_settings_grouped_sections($sections) as $group => $group_sections) : ?>
                    <div class="gps-admin-settings__nav-group">
                        <h2><?php echo esc_html((string) $group); ?></h2>
                        <?php foreach ($group_sections as $section) : ?>
                            <a class="gps-admin-settings__nav-link <?php echo $section['slug'] === $current['slug'] ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $section['slug'])); ?>">
                                <?php echo esc_html($section['menu']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </aside>

            <main class="gps-admin-settings__content">
                <section class="gps-admin-settings__card">
                    <div class="gps-admin-settings__card-header">
                        <div>
                            <p class="gps-admin-settings__section-label"><?php echo esc_html($current['group']); ?></p>
                            <h2><?php echo esc_html($current['title']); ?></h2>
                        </div>
                        <span class="gps-admin-settings__badge gps-admin-settings__badge--safe"><?php esc_html_e('No live actions', 'gp-clone'); ?></span>
                    </div>

                    <p><?php echo esc_html($current['summary']); ?></p>

                    <?php if (!empty($current['notice'])) : ?>
                        <div class="gps-admin-settings__notice">
                            <?php echo esc_html($current['notice']); ?>
                        </div>
                    <?php endif; ?>

                    <h3><?php esc_html_e('Later this section will configure:', 'gp-clone'); ?></h3>
                    <ul class="gps-admin-settings__list">
                        <?php foreach ((array) $current['later'] as $item) : ?>
                            <li><?php echo esc_html((string) $item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="gps-admin-settings__card gps-admin-settings__card--muted">
                    <h2><?php esc_html_e('Safety status for this ticket', 'gp-clone'); ?></h2>
                    <div class="gps-admin-settings__status-grid">
                        <div><span class="gps-admin-settings__dot gps-admin-settings__dot--ready"></span><?php esc_html_e('Menu structure added', 'gp-clone'); ?></div>
                        <div><span class="gps-admin-settings__dot gps-admin-settings__dot--blocked"></span><?php esc_html_e('Business logic not implemented', 'gp-clone'); ?></div>
                        <div><span class="gps-admin-settings__dot gps-admin-settings__dot--blocked"></span><?php esc_html_e('External API writes disabled', 'gp-clone'); ?></div>
                        <div><span class="gps-admin-settings__dot gps-admin-settings__dot--blocked"></span><?php esc_html_e('Marketplace publishing disabled', 'gp-clone'); ?></div>
                    </div>
                </section>
            </main>
        </div>
    </div>
    <?php
}

/**
 * @param array<int, array<string, mixed>> $sections
 * @return array<string, array<int, array<string, mixed>>>
 */
function gps_admin_settings_grouped_sections(array $sections): array
{
    $grouped = [];
    foreach ($sections as $section) {
        $group = (string) $section['group'];
        $grouped[$group][] = $section;
    }

    return $grouped;
}

/**
 * @param array<int, array<string, mixed>> $sections
 * @return array<string, mixed>|null
 */
function gps_admin_settings_find_section(string $slug, array $sections): ?array
{
    foreach ($sections as $section) {
        if ($section['slug'] === $slug) {
            return $section;
        }
    }

    return null;
}

function gps_admin_settings_css(): string
{
    return <<<'CSS'
#adminmenu li.wp-has-current-submenu.toplevel_page_gps-admin-settings > a.wp-has-current-submenu,
#adminmenu li.current.toplevel_page_gps-admin-settings > a.menu-top,
#adminmenu .toplevel_page_gps-admin-settings .wp-submenu li.current a {
    background: #0f1f33;
    color: #ffffff;
}
.gps-admin-settings {
    color: #111827;
    max-width: 1360px;
}
.gps-admin-settings a {
    color: #0f1f33;
}
.gps-admin-settings__header {
    align-items: flex-start;
    background: #ffffff;
    border: 1px solid #d8dee8;
    border-radius: 12px;
    display: flex;
    gap: 24px;
    justify-content: space-between;
    margin: 20px 20px 20px 0;
    padding: 24px;
}
.gps-admin-settings__eyebrow,
.gps-admin-settings__section-label {
    color: #5b6676;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .08em;
    margin: 0 0 8px;
    text-transform: uppercase;
}
.gps-admin-settings h1,
.gps-admin-settings h2,
.gps-admin-settings h3 {
    color: #111827;
}
.gps-admin-settings h1 {
    font-size: 28px;
    line-height: 1.2;
    margin: 0 0 10px;
}
.gps-admin-settings h2 {
    font-size: 20px;
    margin: 0 0 12px;
}
.gps-admin-settings h3 {
    font-size: 15px;
    margin: 24px 0 10px;
}
.gps-admin-settings__lead {
    color: #3f4a5a;
    font-size: 15px;
    margin: 0;
    max-width: 780px;
}
.gps-admin-settings__layout {
    align-items: flex-start;
    display: grid;
    gap: 20px;
    grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
    margin-right: 20px;
}
.gps-admin-settings__nav,
.gps-admin-settings__card {
    background: #ffffff;
    border: 1px solid #d8dee8;
    border-radius: 12px;
}
.gps-admin-settings__nav {
    padding: 16px;
}
.gps-admin-settings__nav-group + .gps-admin-settings__nav-group {
    border-top: 1px solid #e7ebf0;
    margin-top: 14px;
    padding-top: 14px;
}
.gps-admin-settings__nav h2 {
    color: #5b6676;
    font-size: 11px;
    letter-spacing: .08em;
    margin: 0 0 8px;
    text-transform: uppercase;
}
.gps-admin-settings__nav-link {
    border-radius: 8px;
    color: #1f2937;
    display: block;
    font-weight: 600;
    padding: 9px 10px;
    text-decoration: none;
}
.gps-admin-settings__nav-link:focus,
.gps-admin-settings__nav-link:hover {
    background: #eef2f7;
    color: #0f1f33;
    box-shadow: none;
}
.gps-admin-settings__nav-link.is-active {
    background: #0f1f33;
    color: #ffffff;
}
.gps-admin-settings__content {
    display: grid;
    gap: 20px;
}
.gps-admin-settings__card {
    padding: 24px;
}
.gps-admin-settings__card--muted {
    background: #f8fafc;
}
.gps-admin-settings__card-header {
    align-items: flex-start;
    display: flex;
    gap: 16px;
    justify-content: space-between;
}
.gps-admin-settings__badge {
    border-radius: 999px;
    display: inline-flex;
    font-size: 12px;
    font-weight: 700;
    padding: 6px 10px;
    white-space: nowrap;
}
.gps-admin-settings__badge--draft {
    background: #eef2f7;
    color: #253244;
}
.gps-admin-settings__badge--safe {
    background: #e9f3ee;
    color: #24513f;
}
.gps-admin-settings__notice {
    background: #f6f8fb;
    border-left: 4px solid #0f1f33;
    color: #253244;
    margin: 18px 0 0;
    padding: 12px 14px;
}
.gps-admin-settings__list {
    margin: 0;
    padding-left: 20px;
}
.gps-admin-settings__list li {
    margin: 8px 0;
}
.gps-admin-settings__status-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.gps-admin-settings__status-grid > div {
    align-items: center;
    background: #ffffff;
    border: 1px solid #e1e6ee;
    border-radius: 10px;
    display: flex;
    gap: 8px;
    padding: 12px;
}
.gps-admin-settings__dot {
    border-radius: 50%;
    display: inline-block;
    height: 9px;
    width: 9px;
}
.gps-admin-settings__dot--ready {
    background: #2f6b52;
}
.gps-admin-settings__dot--blocked {
    background: #8a5a12;
}
@media (max-width: 960px) {
    .gps-admin-settings__layout,
    .gps-admin-settings__status-grid {
        grid-template-columns: 1fr;
    }
    .gps-admin-settings__header,
    .gps-admin-settings__card-header {
        display: block;
    }
    .gps-admin-settings__badge {
        margin-top: 12px;
    }
}
CSS;
}
