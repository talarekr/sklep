<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#';
$account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '#';
$shop_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : '#';
$cart_count = absint((function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0);
?>
<header class="gp-main-header">
    <div class="gp-container">
        <div class="gp-main-header__top-links">
            <a href="#">Gwarancja i zwroty</a>
            <a href="#">Kontakt</a>
            <a href="#">Najczęściej zadawane pytania</a>
            <a href="#">Oferta dla warsztatów</a>
        </div>

        <div class="gp-main-header__row">
            <div class="gp-logo-wrap">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="gp-logo-link" aria-label="<?php esc_attr_e('Strona główna', 'gp-clone'); ?>">
                    <?php
                    $custom_logo = get_custom_logo();
                    if (!empty($custom_logo)) {
                        echo $custom_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    } else {
                        ?>
                        <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/gp-logo-placeholder.svg'); ?>" alt="Gregor Swiss">
                    <?php } ?>
                </a>
            </div>

            <form class="gp-main-search" action="<?php echo esc_url(home_url('/')); ?>" method="get" role="search">
                <label class="screen-reader-text" for="gp-main-search-input"><?php esc_html_e('Wyszukiwarka sklepu', 'gp-clone'); ?></label>
                <span class="gp-main-search__icon" aria-hidden="true">&#128269;</span>
                <input
                    id="gp-main-search-input"
                    type="search"
                    name="s"
                    value="<?php echo esc_attr(get_search_query()); ?>"
                    placeholder="Wyszukiwanie według nazwy części, numeru części, kategorii, modelu samochodu..."
                >
                <button type="submit"><?php esc_html_e('Szukaj', 'gp-clone'); ?></button>
            </form>

            <div class="gp-main-actions">
                <a class="gp-main-actions__item" href="<?php echo esc_url($account_url); ?>">
                    <span class="gp-main-actions__icon" aria-hidden="true">&#128100;</span>
                    <span><?php esc_html_e('Mój profil', 'gp-clone'); ?></span>
                </a>

                <a class="gp-main-actions__item gp-main-actions__item--cart" href="<?php echo esc_url($cart_url); ?>">
                    <span class="gp-main-actions__icon" aria-hidden="true">&#128722;</span>
                    <span><?php esc_html_e('Koszyk', 'gp-clone'); ?></span>
                    <span class="gp-mini-cart-count"><?php echo $cart_count; ?></span>
                </a>
            </div>
        </div>

        <div class="gp-main-header__nav-row">
            <a href="<?php echo esc_url($shop_url); ?>" class="gp-all-cat">
                <span class="gp-hamburger" aria-hidden="true">&#9776;</span>
                <?php esc_html_e('Wszystkie kategorie', 'gp-clone'); ?>
            </a>
            <nav class="gp-shortcuts" aria-label="Skróty kategorii">
                <?php foreach (['Silniki', 'Skrzynie biegów', 'Dyferencjały', 'Felgi', 'Fotele', 'Kierownice', 'Promocje'] as $shortcut) : ?>
                    <a href="#"<?php echo $shortcut === 'Promocje' ? ' class="is-promo"' : ''; ?>><?php echo esc_html($shortcut); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="gp-phone">
                <span aria-hidden="true">&#128222;</span>
                <a href="tel:+48504266984">504 266 984</a>
            </div>
        </div>
    </div>
</header>

<?php if (is_front_page()) : ?>
<section class="gp-hero">
    <div class="gp-container gp-hero__content">
        <div class="gp-hero-promo">
            <h2>Kupuj u nas nawet <span>10%</span> taniej</h2>
        </div>
    </div>
</section>
<?php endif; ?>
