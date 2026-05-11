<?php

namespace GAG;

if (!defined('ABSPATH')) {
    exit;
}

class ImportGuard
{
    private static int $depth = 0;
    /** @var array<string, int> */
    private static array $removed_wei_callbacks = [];

    public static function begin(string $mode): void
    {
        self::$depth++;
        $GLOBALS['gag_import_guard_active'] = true;
        $GLOBALS['gag_import_guard_mode'] = sanitize_key($mode);

        self::suppress_wei_autosync_hooks();
    }

    public static function end(): void
    {
        self::$depth = max(0, self::$depth - 1);
        if (self::$depth === 0) {
            $GLOBALS['gag_import_guard_active'] = false;
            unset($GLOBALS['gag_import_guard_mode']);
        }
    }

    public static function is_active(): bool
    {
        return !empty($GLOBALS['gag_import_guard_active']) || self::$depth > 0;
    }

    public static function mode(): string
    {
        return sanitize_key((string) ($GLOBALS['gag_import_guard_mode'] ?? ''));
    }

    public static function suppress_wei_autosync_hooks(): void
    {
        global $wp_filter;

        $targets = [
            'woocommerce_product_set_stock' => ['queue_product_stock_sync'],
            'woocommerce_variation_set_stock' => ['queue_product_stock_sync'],
            'woocommerce_new_product' => ['queue_new_product_sync'],
            'woocommerce_update_product' => ['queue_updated_product_sync'],
            'woocommerce_product_set_regular_price' => ['queue_product_price_sync'],
            'woocommerce_product_set_sale_price' => ['queue_product_price_sync'],
            'save_post_product' => ['queue_saved_product_stock_sync'],
        ];

        foreach ($targets as $hook => $methods) {
            if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
                continue;
            }

            foreach ((array) $wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ((array) $callbacks as $callback) {
                    $function = $callback['function'] ?? null;
                    if (!is_array($function) || !isset($function[0], $function[1]) || !is_object($function[0])) {
                        continue;
                    }

                    $class = get_class($function[0]);
                    $method = (string) $function[1];
                    if ($class !== 'WEI\\Services\\AutoSyncScheduler' || !in_array($method, $methods, true)) {
                        continue;
                    }

                    $key = $hook . '|' . $priority . '|' . $method;
                    if (isset(self::$removed_wei_callbacks[$key])) {
                        continue;
                    }

                    remove_action($hook, $function, (int) $priority);
                    self::$removed_wei_callbacks[$key] = time();
                }
            }
        }
    }
}
