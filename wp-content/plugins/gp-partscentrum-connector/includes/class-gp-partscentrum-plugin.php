<?php

if (!defined('ABSPATH')) {
    exit;
}

class GP_Partscentrum_Plugin
{
    private const PAGE_SLUG = 'nowe-czesci-skoda';
    private const ACTION_SEARCH = 'gp_partscentrum_search';
    private const ACTION_ADD_TO_CART = 'gp_partscentrum_add_to_cart';
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
                    <?php elseif (!empty($result['data']) && is_array($result['data'])): ?>
                        <?php $data = $result['data']; ?>
                        <h2><?php echo esc_html((string) ($data['supplier_title'] ?? 'Nowa część Skoda')); ?></h2>
                        <ul>
                            <li><strong>Numer części:</strong> <?php echo esc_html((string) ($data['supplier_part_number'] ?? '')); ?></li>
                            <li><strong>Dostępność:</strong> <?php echo esc_html((string) ($data['availability'] ?? 'unknown')); ?></li>
                            <li><strong>Cena:</strong> <?php echo wp_kses_post(wc_price((float) ($data['final_price'] ?? 0))); ?></li>
                        </ul>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD_TO_CART); ?>">
                            <?php wp_nonce_field('gp_partscentrum_add_to_cart_nonce', '_gp_nonce'); ?>
                            <input type="hidden" name="payload" value="<?php echo esc_attr(wp_json_encode($data)); ?>">
                            <button type="submit">Dodaj do koszyka</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
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
            $this->store_flash_result(['data' => $cached]);
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
        $supplierPrice = (float) ($data['supplier_price'] ?? 0);
        $finalPrice = $this->calculate_final_price($supplierPrice);

        $payload = [
            'source' => 'partscentrum',
            'type' => 'new_skoda_part',
            'supplier_part_number' => (string) ($data['supplier_part_number'] ?? $partNumber),
            'supplier_title' => (string) ($data['supplier_title'] ?? ('Nowa część Skoda ' . $partNumber)),
            'supplier_price' => $supplierPrice,
            'availability' => (string) ($data['availability'] ?? 'unknown'),
            'supplier_product_id' => (string) ($data['supplier_product_id'] ?? ''),
            'margin_percent' => self::MARGIN_PERCENT,
            'final_price' => $finalPrice,
            'checked_at' => (string) ($data['checked_at'] ?? gmdate('c')),
        ];

        set_transient($cacheKey, $payload, self::CACHE_TTL);

        $this->store_flash_result(['data' => $payload]);
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
            'supplier_price' => wc_format_decimal((float) ($payload['supplier_price'] ?? 0), 2),
            'margin_percent' => self::MARGIN_PERCENT,
            'final_price' => wc_format_decimal((float) ($payload['final_price'] ?? 0), 2),
            'checked_at' => sanitize_text_field((string) ($payload['checked_at'] ?? gmdate('c'))),
            'availability' => sanitize_text_field((string) ($payload['availability'] ?? 'unknown')),
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
        $itemData[] = ['name' => 'Cena dostawcy', 'value' => wc_price((float) ($meta['supplier_price'] ?? 0))];
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
        $item->add_meta_data('supplier_price', (string) ($meta['supplier_price'] ?? ''), true);
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

    private function redirect_to_landing(): void
    {
        wp_safe_redirect(home_url('/' . self::PAGE_SLUG . '/'));
        exit;
    }
}
