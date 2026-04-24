<?php

if (!defined('ABSPATH')) {
    exit;
}

class GP_Partscentrum_Plugin
{
    private const PAGE_SLUG = 'nowe-czesci-skoda';
    private const ACTION_SEARCH = 'gp_partscentrum_search';
    private const ACTION_ADD_TO_CART = 'gp_partscentrum_add_to_cart';
    private const ACTION_ADMIN_LOGIN_TEST = 'gp_partscentrum_admin_login_test';
    private const ACTION_ADMIN_SEARCH_TEST = 'gp_partscentrum_admin_search_test';
    private const ADMIN_MENU_SLUG = 'gp-partscentrum-admin';
    private const CACHE_TTL = 600;
    private const RATE_LIMIT = 12;
    private const RATE_WINDOW = 300;
    private const MARGIN_PERCENT = 10.0;
    private const DYNAMIC_SKU = 'gp-partscentrum-dynamic';

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        self::ensure_target_page();
        self::ensure_dynamic_product();
    }

    private function __construct()
    {
        add_shortcode('gp_partscentrum_search', [$this, 'render_search_shortcode']);

        add_action('admin_post_nopriv_' . self::ACTION_SEARCH, [$this, 'handle_search']);
        add_action('admin_post_' . self::ACTION_SEARCH, [$this, 'handle_search']);

        add_action('admin_post_nopriv_' . self::ACTION_ADD_TO_CART, [$this, 'handle_add_to_cart']);
        add_action('admin_post_' . self::ACTION_ADD_TO_CART, [$this, 'handle_add_to_cart']);
        add_action('admin_post_' . self::ACTION_ADMIN_LOGIN_TEST, [$this, 'handle_admin_login_test']);
        add_action('admin_post_' . self::ACTION_ADMIN_SEARCH_TEST, [$this, 'handle_admin_search_test']);
        add_action('admin_menu', [$this, 'register_admin_menu']);

        add_action('init', [__CLASS__, 'ensure_target_page']);
        add_action('init', [__CLASS__, 'ensure_dynamic_product']);

        add_filter('wp_nav_menu_items', [$this, 'inject_menu_link'], 20, 2);

        add_action('woocommerce_before_calculate_totals', [$this, 'apply_dynamic_prices'], 20);
        add_filter('woocommerce_get_item_data', [$this, 'render_cart_item_meta'], 20, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copy_meta_to_order_item'], 20, 4);
        add_filter('woocommerce_cart_item_name', [$this, 'replace_cart_item_name'], 20, 3);
    }

    public static function ensure_target_page(): void
    {
        $page = get_page_by_path(self::PAGE_SLUG);
        if ($page instanceof WP_Post) {
            return;
        }

        wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Nowe części Skoda',
            'post_name' => self::PAGE_SLUG,
            'post_content' => '[gp_partscentrum_search]',
        ]);
    }

    public static function ensure_dynamic_product(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $existingId = wc_get_product_id_by_sku(self::DYNAMIC_SKU);
        if ($existingId > 0) {
            return;
        }

        $product = new WC_Product_Simple();
        $product->set_name('Nowa część Skoda (Partscentrum)');
        $product->set_status('private');
        $product->set_catalog_visibility('hidden');
        $product->set_sku(self::DYNAMIC_SKU);
        $product->set_regular_price('1');
        $product->set_price('1');
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->save();
    }

    public function inject_menu_link(string $items, stdClass $args): string
    {
        $menuHtml = strtolower($items);
        if (str_contains($menuHtml, self::PAGE_SLUG)) {
            return $items;
        }

        $url = home_url('/' . self::PAGE_SLUG . '/');
        $items .= '<li class="menu-item menu-item-gp-partscentrum"><a href="' . esc_url($url) . '">Nowe części Skoda</a></li>';

        return $items;
    }

    public function render_search_shortcode(): string
    {
        $result = $this->load_flash_result();
        ob_start();
        ?>
        <section class="gp-partscentrum">
            <h1>Nowe części Skoda</h1>
            <p>Wyszukiwarka nowych części Skoda z panelu zewnętrznego dostawcy Partscentrum.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gp-partscentrum__search-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SEARCH); ?>">
                <?php wp_nonce_field('gp_partscentrum_search_nonce', '_gp_nonce'); ?>
                <label for="gp-part-number">Wpisz numer części Skoda</label>
                <input type="text" id="gp-part-number" name="part_number" required maxlength="64" placeholder="np. 5Q0820803E">
                <button type="submit">Szukaj</button>
            </form>

            <?php if ($result !== null): ?>
                <div class="gp-partscentrum__result">
                    <?php if (!empty($result['error'])): ?>
                        <p class="gp-partscentrum__error"><?php echo esc_html((string) $result['error']); ?></p>
                    <?php elseif (!empty($result['items']) && is_array($result['items'])): ?>
                        <h2>Wyniki wyszukiwania</h2>
                        <?php foreach ($result['items'] as $item): ?>
                            <?php if (!is_array($item)) {
                                continue;
                            } ?>
                            <article class="gp-partscentrum__item">
                                <h3><?php echo esc_html((string) ($item['supplier_title'] ?? 'Nowa część Skoda')); ?></h3>
                                <ul>
                                    <li><strong>Numer części:</strong> <?php echo esc_html((string) ($item['supplier_part_number'] ?? '')); ?></li>
                                    <li><strong>Dostępność:</strong> <?php echo esc_html((string) ($item['availability'] ?? '- / -')); ?></li>
                                    <li><strong>Cena:</strong> <?php echo wp_kses_post(wc_price((float) ($item['final_price'] ?? 0))); ?></li>
                                </ul>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD_TO_CART); ?>">
                                    <?php wp_nonce_field('gp_partscentrum_add_to_cart_nonce', '_gp_nonce'); ?>
                                    <input type="hidden" name="payload" value="<?php echo esc_attr(wp_json_encode($item)); ?>">
                                    <button type="submit">Dodaj do koszyka</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function register_admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Partscentrum',
            'Partscentrum',
            'manage_options',
            self::ADMIN_MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        $flash = $this->load_admin_flash();
        $configStatus = [
            'login' => defined('GP_PARTSCENTRUM_LOGIN') && (string) GP_PARTSCENTRUM_LOGIN !== '',
            'password' => defined('GP_PARTSCENTRUM_PASSWORD') && (string) GP_PARTSCENTRUM_PASSWORD !== '',
        ];
        $logs = GP_Partscentrum_Client::get_recent_logs(30);
        ?>
        <div class="wrap">
            <h1>GP Partscentrum Connector — diagnostyka</h1>

            <h2>Status konfiguracji</h2>
            <table class="widefat striped" style="max-width: 700px;">
                <tbody>
                <tr>
                    <th scope="row">GP_PARTSCENTRUM_LOGIN</th>
                    <td><?php echo $configStatus['login'] ? 'ustawiony' : 'brak'; ?></td>
                </tr>
                <tr>
                    <th scope="row">GP_PARTSCENTRUM_PASSWORD</th>
                    <td><?php echo $configStatus['password'] ? 'ustawione' : 'brak'; ?></td>
                </tr>
                </tbody>
            </table>

            <h2>Test logowania</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADMIN_LOGIN_TEST); ?>">
                <?php wp_nonce_field('gp_partscentrum_admin_login_test', '_gp_admin_nonce'); ?>
                <?php submit_button('Testuj logowanie', 'secondary', 'submit', false); ?>
            </form>

            <h2>Test wyszukiwania</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADMIN_SEARCH_TEST); ?>">
                <?php wp_nonce_field('gp_partscentrum_admin_search_test', '_gp_admin_nonce'); ?>
                <label for="gp-admin-part-number"><strong>Numer części do testu</strong></label><br>
                <input type="text" name="part_number" id="gp-admin-part-number" class="regular-text" required maxlength="64" placeholder="np. 5Q0820803E">
                <?php submit_button('Testuj wyszukiwanie', 'secondary', 'submit', false, ['style' => 'margin-left:8px']); ?>
            </form>

            <?php if (is_array($flash)): ?>
                <h2>Wynik ostatniego testu</h2>
                <table class="widefat striped">
                    <tbody>
                    <?php foreach ($flash as $key => $value): ?>
                        <?php if ($key === 'sample_results' && is_array($value)): ?>
                            <tr>
                                <th scope="row">sample_results</th>
                                <td>
                                    <?php if ($value === []): ?>
                                        brak
                                    <?php else: ?>
                                        <table class="widefat striped">
                                            <thead>
                                            <tr>
                                                <th>Nazwa</th>
                                                <th>PN</th>
                                                <th>Cena brutto po rabacie</th>
                                                <th>Cena po marży +10%</th>
                                                <th>Dostępność</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($value as $sample): ?>
                                                <?php if (!is_array($sample)) {
                                                    continue;
                                                } ?>
                                                <tr>
                                                    <td><?php echo esc_html((string) ($sample['name'] ?? '')); ?></td>
                                                    <td><?php echo esc_html((string) ($sample['pn'] ?? '')); ?></td>
                                                    <td><?php echo esc_html((string) wc_price((float) ($sample['gross_discounted'] ?? 0))); ?></td>
                                                    <td><?php echo esc_html((string) wc_price((float) ($sample['price_with_margin'] ?? 0))); ?></td>
                                                    <td><?php echo esc_html((string) ($sample['availability'] ?? '')); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th scope="row"><?php echo esc_html((string) $key); ?></th>
                                <td>
                                    <?php
                                    if (is_bool($value)) {
                                        echo esc_html($value ? 'true' : 'false');
                                    } elseif (is_array($value)) {
                                        $flat = array_filter(array_map(static fn($v): string => is_scalar($v) ? (string) $v : '', $value));
                                        echo esc_html($flat === [] ? '[]' : implode(', ', $flat));
                                    } else {
                                        echo esc_html((string) $value);
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Ostatnie logi</h2>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Akcja</th>
                    <th>Status</th>
                    <th>Komunikat błędu</th>
                    <th>HTTP code</th>
                    <th>Liczba wyników</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="6">Brak logów.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php if (!is_array($log)) {
                            continue;
                        } ?>
                        <tr>
                            <td><?php echo esc_html((string) ($log['timestamp'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($log['action'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($log['status'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($log['error'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($log['http_code'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) ($log['results_count'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_search(): void
    {
        if (!$this->verify_nonce('_gp_nonce', 'gp_partscentrum_search_nonce')) {
            $this->store_flash_result(['error' => 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.']);
            $this->redirect_to_landing();
        }

        if (!$this->check_rate_limit()) {
            $this->store_flash_result(['error' => 'Zbyt wiele zapytań. Spróbuj ponownie za kilka minut.']);
            $this->redirect_to_landing();
        }

        $partNumber = $this->sanitize_part_number((string) ($_POST['part_number'] ?? ''));
        if ($partNumber === '') {
            $this->store_flash_result(['error' => 'Podaj poprawny numer części.']);
            $this->redirect_to_landing();
        }

        $cacheKey = 'gp_pc_' . md5($partNumber);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            $this->store_flash_result($cached);
            $this->redirect_to_landing();
        }

        $client = new GP_Partscentrum_Client();
        if (!$client->login()) {
            $this->store_flash_result(['error' => 'Nie udało się sprawdzić dostępności części. Spróbuj ponownie później.']);
            $this->redirect_to_landing();
        }

        $result = $client->search_part($partNumber);
        if (!$result['success']) {
            $this->store_flash_result(['error' => 'Nie udało się sprawdzić dostępności części. Spróbuj ponownie później.']);
            $this->redirect_to_landing();
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $items = [];

        foreach ((array) ($data['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $supplierPriceGrossDiscounted = (float) ($row['supplier_price_gross_discounted'] ?? 0);
            if ($supplierPriceGrossDiscounted <= 0) {
                continue;
            }

            $items[] = [
                'source' => 'partscentrum',
                'type' => 'new_skoda_part',
                'supplier_part_number' => (string) ($row['supplier_part_number'] ?? $partNumber),
                'supplier_title' => (string) ($row['supplier_title'] ?? ('Nowa część Skoda ' . $partNumber)),
                'supplier_price_gross_discounted' => $supplierPriceGrossDiscounted,
                'availability' => (string) ($row['availability'] ?? '- / -'),
                'supplier_product_id' => (string) ($row['supplier_product_id'] ?? ''),
                'margin_percent' => self::MARGIN_PERCENT,
                'final_price' => $this->calculate_final_price($supplierPriceGrossDiscounted),
                'checked_at' => (string) ($row['checked_at'] ?? gmdate('c')),
            ];
        }

        if ($items === []) {
            $this->store_flash_result(['error' => 'Nie znaleziono wyników dla podanego numeru części.']);
            $this->redirect_to_landing();
        }

        $payload = [
            'submitted_part_number' => $partNumber,
            'items' => $items,
        ];

        set_transient($cacheKey, $payload, self::CACHE_TTL);

        $this->store_flash_result($payload);
        $this->redirect_to_landing();
    }

    public function handle_add_to_cart(): void
    {
        if (!$this->verify_nonce('_gp_nonce', 'gp_partscentrum_add_to_cart_nonce')) {
            wc_add_notice('Nie udało się dodać części do koszyka. Spróbuj ponownie.', 'error');
            wp_safe_redirect(wc_get_page_permalink('cart'));
            exit;
        }

        $payloadRaw = (string) ($_POST['payload'] ?? '');
        $payload = json_decode(wp_unslash($payloadRaw), true);

        if (!is_array($payload)) {
            wc_add_notice('Niepoprawne dane części. Wyszukaj część ponownie.', 'error');
            $this->redirect_to_landing();
        }

        $productId = wc_get_product_id_by_sku(self::DYNAMIC_SKU);
        if ($productId <= 0) {
            self::ensure_dynamic_product();
            $productId = wc_get_product_id_by_sku(self::DYNAMIC_SKU);
        }

        if ($productId <= 0) {
            wc_add_notice('Nie udało się przygotować produktu dynamicznego.', 'error');
            $this->redirect_to_landing();
        }

        $meta = [
            'source' => 'partscentrum',
            'type' => 'new_skoda_part',
            'supplier_part_number' => sanitize_text_field((string) ($payload['supplier_part_number'] ?? '')),
            'supplier_title' => sanitize_text_field((string) ($payload['supplier_title'] ?? 'Nowa część Skoda')),
            'supplier_price_gross_discounted' => wc_format_decimal((float) ($payload['supplier_price_gross_discounted'] ?? 0), 2),
            'margin_percent' => self::MARGIN_PERCENT,
            'final_price' => wc_format_decimal((float) ($payload['final_price'] ?? 0), 2),
            'checked_at' => sanitize_text_field((string) ($payload['checked_at'] ?? gmdate('c'))),
            'availability' => sanitize_text_field((string) ($payload['availability'] ?? '- / -')),
            'supplier_product_id' => sanitize_text_field((string) ($payload['supplier_product_id'] ?? '')),
            'unique_key' => md5(wp_json_encode($payload) . '|' . microtime(true)),
        ];

        $added = WC()->cart ? WC()->cart->add_to_cart($productId, 1, 0, [], ['gp_partscentrum' => $meta]) : false;
        if (!$added) {
            wc_add_notice('Nie udało się dodać pozycji do koszyka.', 'error');
            $this->redirect_to_landing();
        }

        wc_add_notice('Dodano nową część Skoda do koszyka.', 'success');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public function handle_admin_login_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        if (!$this->verify_nonce('_gp_admin_nonce', 'gp_partscentrum_admin_login_test')) {
            wp_die('Błędny nonce.');
        }

        $client = new GP_Partscentrum_Client();
        $result = $client->run_login_diagnostic();
        $this->store_admin_flash($result);
        $this->redirect_to_admin_page();
    }

    public function handle_admin_search_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        if (!$this->verify_nonce('_gp_admin_nonce', 'gp_partscentrum_admin_search_test')) {
            wp_die('Błędny nonce.');
        }

        $partNumber = $this->sanitize_part_number((string) ($_POST['part_number'] ?? ''));
        $client = new GP_Partscentrum_Client();
        $result = $client->run_search_diagnostic($partNumber, self::MARGIN_PERCENT);
        $this->store_admin_flash($result);
        $this->redirect_to_admin_page();
    }

    public function apply_dynamic_prices(WC_Cart $cart): void
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        foreach ($cart->get_cart() as $cartItemKey => $item) {
            if (empty($item['gp_partscentrum']) || !is_array($item['gp_partscentrum'])) {
                continue;
            }

            $finalPrice = (float) ($item['gp_partscentrum']['final_price'] ?? 0);
            if ($finalPrice <= 0 || empty($item['data']) || !$item['data'] instanceof WC_Product) {
                continue;
            }

            $item['data']->set_price($finalPrice);
            $cart->cart_contents[$cartItemKey] = $item;
        }
    }

    /**
     * @param array<int,array{name:string,value:string}> $itemData
     * @param array<string,mixed> $cartItem
     * @return array<int,array{name:string,value:string}>
     */
    public function render_cart_item_meta(array $itemData, array $cartItem): array
    {
        $meta = $cartItem['gp_partscentrum'] ?? null;
        if (!is_array($meta)) {
            return $itemData;
        }

        $itemData[] = ['name' => 'Źródło', 'value' => 'partscentrum'];
        $itemData[] = ['name' => 'Typ', 'value' => 'Nowa część Skoda'];
        $itemData[] = ['name' => 'Numer części', 'value' => (string) ($meta['supplier_part_number'] ?? '')];
        $itemData[] = ['name' => 'Cena dostawcy (brutto po rabacie)', 'value' => wc_price((float) ($meta['supplier_price_gross_discounted'] ?? 0))];
        $itemData[] = ['name' => 'Marża', 'value' => (string) self::MARGIN_PERCENT . '%'];
        $itemData[] = ['name' => 'Sprawdzono', 'value' => (string) ($meta['checked_at'] ?? '')];

        return $itemData;
    }

    /**
     * @param array<string,mixed> $cartItemValues
     */
    public function copy_meta_to_order_item(WC_Order_Item_Product $item, string $cartItemKey, array $cartItemValues, WC_Order $order): void
    {
        $meta = $cartItemValues['gp_partscentrum'] ?? null;
        if (!is_array($meta)) {
            return;
        }

        $item->add_meta_data('source', 'partscentrum', true);
        $item->add_meta_data('type', 'Nowa część Skoda', true);
        $item->add_meta_data('supplier_part_number', (string) ($meta['supplier_part_number'] ?? ''), true);
        $item->add_meta_data('supplier_title', (string) ($meta['supplier_title'] ?? ''), true);
        $item->add_meta_data('supplier_price_gross_discounted', (string) ($meta['supplier_price_gross_discounted'] ?? ''), true);
        $item->add_meta_data('margin_percent', (string) self::MARGIN_PERCENT, true);
        $item->add_meta_data('final_price', (string) ($meta['final_price'] ?? ''), true);
        $item->add_meta_data('checked_at', (string) ($meta['checked_at'] ?? ''), true);
    }

    /**
     * @param array<string,mixed> $cartItem
     */
    public function replace_cart_item_name(string $name, array $cartItem, string $cartItemKey): string
    {
        $meta = $cartItem['gp_partscentrum'] ?? null;
        if (!is_array($meta)) {
            return $name;
        }

        return esc_html((string) ($meta['supplier_title'] ?? 'Nowa część Skoda'));
    }

    private function calculate_final_price(float $supplierPrice): float
    {
        $finalPrice = $supplierPrice * (1 + (self::MARGIN_PERCENT / 100));
        return (float) wc_format_decimal($finalPrice, 2);
    }

    private function sanitize_part_number(string $raw): string
    {
        $value = strtoupper(trim(sanitize_text_field(wp_unslash($raw))));
        $value = preg_replace('/[^A-Z0-9\-_.\/]/', '', $value) ?? '';

        return substr($value, 0, 64);
    }

    private function verify_nonce(string $nonceField, string $action): bool
    {
        $nonce = isset($_POST[$nonceField]) ? (string) wp_unslash($_POST[$nonceField]) : '';
        return $nonce !== '' && wp_verify_nonce($nonce, $action);
    }

    private function check_rate_limit(): bool
    {
        $key = 'gp_pc_rate_' . md5($this->user_identifier());
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        set_transient($key, $count + 1, self::RATE_WINDOW);

        return true;
    }

    private function user_identifier(): string
    {
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }

        return 'ip_' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function store_flash_result(array $payload): void
    {
        $key = $this->flash_key();
        set_transient($key, $payload, 120);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_flash_result(): ?array
    {
        $key = $this->flash_key();
        $value = get_transient($key);
        if (is_array($value)) {
            delete_transient($key);
            return $value;
        }

        return null;
    }

    private function flash_key(): string
    {
        return 'gp_pc_flash_' . md5($this->user_identifier());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function store_admin_flash(array $payload): void
    {
        set_transient('gp_pc_admin_flash_' . get_current_user_id(), $payload, 300);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_admin_flash(): ?array
    {
        $key = 'gp_pc_admin_flash_' . get_current_user_id();
        $value = get_transient($key);
        if (is_array($value)) {
            delete_transient($key);
            return $value;
        }

        return null;
    }

    private function redirect_to_landing(): void
    {
        wp_safe_redirect(home_url('/' . self::PAGE_SLUG . '/'));
        exit;
    }

    private function redirect_to_admin_page(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_MENU_SLUG));
        exit;
    }
}
