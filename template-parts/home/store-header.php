<?php if (!defined('ABSPATH')) { exit; } ?>
<header class="gp-main-header">
    <div class="gp-container">
        <div class="gp-main-header__row">
            <div class="gp-logo-wrap">
                <?php
                $custom_logo = get_custom_logo();
                if (!empty($custom_logo)) {
                    echo $custom_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    ?>
                    <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/gp-logo-placeholder.svg'); ?>" alt="Global Parts">
                <?php } ?>
            </div>
            <div class="gp-header-tools">
                <div><a href="#">EN</a> / <strong>PL</strong></div>
                <div><a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '#'); ?>">Mój profil</a></div>
                <div class="gp-mini-cart-wrap">
                    <a href="<?php echo esc_url(function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#'); ?>">Zamówienie</a> | <a href="<?php echo esc_url(function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#'); ?>">Przejdź do koszyka</a>
                    <span class="gp-mini-cart-count"><?php echo absint((function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0); ?></span>
                    <?php if (function_exists('woocommerce_mini_cart')) : ?>
                        <div class="gp-mini-cart-dropdown"><?php woocommerce_mini_cart(); ?></div>
                    <?php endif; ?>
                </div>
                <div>Powiadomienia <span class="gp-notice-dot">3</span></div>
            </div>
        </div>

        <div class="gp-main-header__row2">
            <a href="<?php echo esc_url(function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : '#'); ?>" class="gp-all-cat">Wszystkie kategorie</a>
            <nav class="gp-shortcuts" aria-label="Skróty kategorii">
                <?php foreach (['Silniki', 'Skrzynie biegów', 'Dyferencjały', 'Felgi', 'Fotele', 'Kierownice', 'Promocje'] as $shortcut) : ?>
                    <a href="#"><?php echo esc_html($shortcut); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="gp-phone">+48 510 215 506</div>
            <?php get_search_form(); ?>
        </div>
    </div>
</header>
