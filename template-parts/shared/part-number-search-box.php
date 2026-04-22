<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
$part_number_query = isset($_GET['part_number']) ? sanitize_text_field((string) wp_unslash($_GET['part_number'])) : '';
?>
<aside class="gp-part-search-box" data-gp-part-search-box>
    <button type="button" class="gp-part-search-box__toggle" data-gp-part-search-toggle aria-expanded="true" aria-controls="gp-part-search-panel">
        <span class="gp-part-search-box__toggle-icon" aria-hidden="true">&#128269;</span>
        <span class="gp-part-search-box__toggle-label"><?php esc_html_e('Numer części', 'gp-clone'); ?></span>
    </button>

    <div class="gp-part-search-box__panel" id="gp-part-search-panel" data-gp-part-search-panel>
        <div class="gp-part-search-box__header">
            <h3><?php esc_html_e('Wyszukiwanie po numerze części', 'gp-clone'); ?></h3>
            <button type="button" class="gp-part-search-box__collapse" data-gp-part-search-close aria-label="<?php esc_attr_e('Zwiń wyszukiwarkę numeru części', 'gp-clone'); ?>">
                &times;
            </button>
        </div>

        <form method="get" action="<?php echo esc_url($shop_url); ?>" class="gp-part-search-box__form">
            <label for="gp-part-number-input"><?php esc_html_e('Numer części', 'gp-clone'); ?></label>
            <input
                id="gp-part-number-input"
                type="search"
                name="part_number"
                value="<?php echo esc_attr($part_number_query); ?>"
                placeholder="<?php esc_attr_e('np. 8E0 953 521D', 'gp-clone'); ?>"
                required
            >
            <button type="submit"><?php esc_html_e('Szukaj', 'gp-clone'); ?></button>
        </form>
    </div>
</aside>
