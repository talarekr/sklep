<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#';
$login_url = home_url('/zaloguj');
$register_url = home_url('/zarejestruj');
$favourites_url = home_url('/ulubione');
$orders_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('orders') : home_url('/historia-zamowien');
$account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/moje-konto');
$logout_url = wp_logout_url(home_url('/'));
$checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/zamowienie');
$shop_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : '#';
$cart_count = absint((function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0);
$is_logged_in = is_user_logged_in();
$shortcuts = [
    [
        'label' => 'Silniki',
        'slugs' => ['silniki', 'silnik', 'silniki-i-osprzet', 'engines'],
        'url' => 'https://gpswiss.pl/kategoria-produktu/motoryzacja/czesci-samochodowe/silniki-i-osprzet/silniki-kompletne/',
    ],
    [
        'label' => 'Skrzynia biegów',
        'slugs' => ['skrzynia-biegow', 'skrzynie-biegow', 'transmission'],
        'url' => 'https://gpswiss.pl/kategoria-produktu/motoryzacja/czesci-samochodowe/uklad-napedowy/skrzynie-biegow/kompletne-skrzynie/',
    ],
    ['label' => 'Filtry DPF', 'slugs' => ['filtry-czastek-stalych-dpf-fap']],
    ['label' => 'Felgi', 'slugs' => ['felgi', 'felga', 'wheels']],
    ['label' => 'Fotele', 'slugs' => ['fotele', 'fotel', 'wyposazenie-wnetrza-samochodu', 'interior']],
    ['label' => 'Zwrotnice', 'slugs' => ['zwrotnice', 'zwrotnica', 'suspension']],
];

$resolve_category_url = static function (array $candidate_slugs, string $label) use ($shop_url): string {
    if (!taxonomy_exists('product_cat')) {
        return $shop_url;
    }

    foreach ($candidate_slugs as $slug) {
        $term = get_term_by('slug', sanitize_title($slug), 'product_cat');
        if ($term instanceof WP_Term) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                return $link;
            }
        }
    }

    $matching_terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'name__like' => $label,
        'number' => 1,
    ]);

    if (is_array($matching_terms) && isset($matching_terms[0]) && $matching_terms[0] instanceof WP_Term) {
        $link = get_term_link($matching_terms[0]);
        if (!is_wp_error($link)) {
            return $link;
        }
    }

    return $shop_url;
};

$all_product_categories = [];
if (taxonomy_exists('product_cat')) {
    $motoryzacja_term = get_term_by('slug', sanitize_title('motoryzacja'), 'product_cat');

    if (!$motoryzacja_term instanceof WP_Term) {
        $motoryzacja_term = get_term_by('name', 'Motoryzacja', 'product_cat');
    }

    if ($motoryzacja_term instanceof WP_Term) {
        $is_technical_category = static function (WP_Term $term): bool {
            $technical_slugs = [
                'motoryzacja',
                'czesci-samochodowe',
            ];
            $technical_names = [
                'motoryzacja',
                'części samochodowe',
            ];

            if (in_array(sanitize_title($term->slug), $technical_slugs, true)) {
                return true;
            }

            return in_array(mb_strtolower(wp_strip_all_tags((string) $term->name)), $technical_names, true);
        };

        $terms_by_parent = [];
        $all_terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'fields' => 'all',
        ]);

        if (is_array($all_terms)) {
            foreach ($all_terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }

                $parent_id = (int) $term->parent;
                if (!isset($terms_by_parent[$parent_id])) {
                    $terms_by_parent[$parent_id] = [];
                }

                $terms_by_parent[$parent_id][] = $term;
            }
        }

        $queue = [(int) $motoryzacja_term->term_id];
        $visited = [];
        $resolved_ids = [];

        while ($queue !== []) {
            $parent_id = array_shift($queue);
            if (isset($visited[$parent_id])) {
                continue;
            }

            $visited[$parent_id] = true;
            $children = $terms_by_parent[$parent_id] ?? [];

            foreach ($children as $child) {
                if (!$child instanceof WP_Term) {
                    continue;
                }

                if ($is_technical_category($child)) {
                    $queue[] = (int) $child->term_id;
                    continue;
                }

                if ((int) $child->count > 0) {
                    $resolved_ids[] = (int) $child->term_id;
                }
            }
        }

        $resolved_ids = array_values(array_unique($resolved_ids));

        if ($resolved_ids !== []) {
            $all_product_categories = get_terms([
                'taxonomy' => 'product_cat',
                'include' => $resolved_ids,
                'hide_empty' => true,
                'orderby' => 'name',
                'order' => 'ASC',
            ]);
        }
    }

    if (is_wp_error($all_product_categories) || !is_array($all_product_categories)) {
        $all_product_categories = [];
    }
}
?>
<header class="gp-main-header">
    <div class="gp-container">
        <div class="gp-main-header__top-links">
            <a href="<?php echo esc_url(home_url('/kontakt')); ?>"><?php esc_html_e('Kontakt', 'gp-clone'); ?></a>
            <label class="gp-language-switcher" for="gp-language-select">
                <span class="gp-language-switcher__label" data-label-desktop><?php esc_html_e('Wybierz język', 'gp-clone'); ?></span>
                <span class="gp-language-switcher__label gp-language-switcher__label--mobile" data-label-mobile><?php esc_html_e('Język', 'gp-clone'); ?></span>
                <select id="gp-language-select" class="gp-language-switcher__select" aria-label="<?php esc_attr_e('Wybierz język', 'gp-clone'); ?>">
                    <option value="pl">🇵🇱 Polski</option>
                    <option value="en">🇬🇧 Angielski</option>
                    <option value="fr">🇫🇷 Francuski</option>
                    <option value="uk">🇺🇦 Ukraiński</option>
                    <option value="de">🇩🇪 Niemiecki</option>
                </select>
            </label>
            <a href="#" class="gp-rzetelna-link gp-rzetelna-link--top" aria-label="<?php esc_attr_e('Rzetelna Firma', 'gp-clone'); ?>">
                <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/rzetelna-firma.jpg'); ?>" alt="<?php esc_attr_e('Rzetelna Firma', 'gp-clone'); ?>" loading="lazy">
            </a>
        </div>

        <div class="gp-main-header__row">
            <div class="gp-logo-wrap">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="gp-logo-link" aria-label="<?php esc_attr_e('Strona główna', 'gp-clone'); ?>">
                    <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/gp-logo-main.jpg'); ?>" alt="Gregor Swiss">
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
                <input type="hidden" name="post_type" value="product">
                <button type="submit"><?php esc_html_e('Szukaj', 'gp-clone'); ?></button>
            </form>

            <div class="gp-main-actions">
                <div class="gp-profile-menu" data-gp-profile-menu>
                    <button
                        type="button"
                        class="gp-main-actions__item gp-profile-menu__trigger"
                        aria-expanded="false"
                        aria-controls="gp-profile-dropdown"
                        data-gp-profile-trigger
                    >
                        <span class="gp-main-actions__icon" aria-hidden="true">&#128100;</span>
                        <span><?php esc_html_e('Mój profil', 'gp-clone'); ?></span>
                    </button>
                    <div class="gp-profile-dropdown" id="gp-profile-dropdown" data-gp-profile-dropdown hidden>
                        <?php if (!$is_logged_in) : ?>
                            <div class="gp-profile-dropdown__actions">
                                <a class="gp-btn gp-btn--primary" href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></a>
                                <a class="gp-btn gp-btn--outline" href="<?php echo esc_url($register_url); ?>"><?php esc_html_e('Zarejestruj się', 'gp-clone'); ?></a>
                            </div>
                        <?php endif; ?>
                        <div class="gp-profile-dropdown__links">
                            <?php if ($is_logged_in) : ?>
                                <a href="<?php echo esc_url($account_url); ?>">👤 <?php esc_html_e('Moje konto', 'gp-clone'); ?></a>
                                <a href="<?php echo esc_url($orders_url); ?>">🧾 <?php esc_html_e('Moje zamówienia', 'gp-clone'); ?></a>
                                <a href="<?php echo esc_url($logout_url); ?>">↪ <?php esc_html_e('Wyloguj się', 'gp-clone'); ?></a>
                            <?php else : ?>
                                <a href="<?php echo esc_url($favourites_url); ?>">❤️ <?php esc_html_e('Ulubione', 'gp-clone'); ?></a>
                                <a href="<?php echo esc_url($orders_url); ?>">🧾 <?php esc_html_e('Historia zamówień', 'gp-clone'); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="gp-mini-cart-wrap" data-gp-mini-cart-wrap>
                    <button
                        type="button"
                        class="gp-main-actions__item gp-main-actions__item--cart"
                        data-gp-mini-cart-open
                        aria-haspopup="dialog"
                        aria-controls="gp-mini-cart-panel"
                    >
                        <span class="gp-main-actions__icon" aria-hidden="true">&#128722;</span>
                        <span><?php esc_html_e('Koszyk', 'gp-clone'); ?></span>
                        <span class="gp-mini-cart-count"><?php echo $cart_count; ?></span>
                    </button>

                    <aside class="gp-mini-cart-panel" id="gp-mini-cart-panel" data-gp-mini-cart-panel aria-hidden="true" hidden>
                        <button type="button" class="gp-mini-cart-panel__close" data-gp-mini-cart-close aria-label="<?php esc_attr_e('Zamknij podgląd koszyka', 'gp-clone'); ?>">×</button>
                        <h3><?php esc_html_e('Koszyk', 'gp-clone'); ?></h3>
                        <div class="gp-mini-cart-panel__content" data-gp-mini-cart-content>
                            <?php if (function_exists('gp_render_mini_cart_content')) { gp_render_mini_cart_content(); } ?>
                        </div>
                        <div class="gp-mini-cart-panel__footer">
                            <a href="<?php echo esc_url($checkout_url); ?>" class="gp-btn gp-btn--primary gp-mini-cart-checkout" data-gp-order-cta><?php esc_html_e('Zamówienie', 'gp-clone'); ?></a>
                            <a href="<?php echo esc_url($cart_url); ?>" class="gp-btn gp-btn--outline"><?php esc_html_e('Przejdź do koszyka', 'gp-clone'); ?></a>
                        </div>
                    </aside>
                </div>
            </div>
        </div>

        <div class="gp-main-header__nav-row">
            <div class="gp-all-cat-menu" data-gp-all-cat-menu>
                <button
                    type="button"
                    class="gp-all-cat"
                    aria-expanded="false"
                    aria-controls="gp-all-categories-dropdown"
                    data-gp-all-cat-trigger
                >
                    <span class="gp-hamburger" aria-hidden="true">&#9776;</span>
                    <?php esc_html_e('Menu', 'gp-clone'); ?>
                </button>
                <div class="gp-all-cat-dropdown" id="gp-all-categories-dropdown" data-gp-all-cat-dropdown hidden>
                    <ul class="gp-all-cat-dropdown__list">
                        <?php if (!empty($all_product_categories)) : ?>
                            <?php foreach ($all_product_categories as $category) : ?>
                                <?php
                                $category_link = get_term_link($category);
                                if (is_wp_error($category_link)) {
                                    continue;
                                }
                                ?>
                                <li>
                                    <a href="<?php echo esc_url($category_link); ?>">
                                        <?php echo esc_html($category->name); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <li class="gp-all-cat-dropdown__empty"><?php esc_html_e('Brak dostępnych kategorii.', 'gp-clone'); ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <nav class="gp-shortcuts" aria-label="Skróty kategorii">
                <?php foreach ($shortcuts as $shortcut) : ?>
                    <?php $shortcut_url = $shortcut['url'] ?? $resolve_category_url($shortcut['slugs'], $shortcut['label']); ?>
                    <a href="<?php echo esc_url($shortcut_url); ?>"><?php echo esc_html($shortcut['label']); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="gp-phone">
                <span aria-hidden="true">&#128222;</span>
                <a href="tel:+48504266984">+48 504 266 984</a>
                <span class="gp-phone__separator" aria-hidden="true">|</span>
                <a href="tel:+48579152665">+48 579 152 665</a>
            </div>
        </div>
    </div>
</header>
<div class="gp-auth-modal" data-gp-auth-modal aria-hidden="true" hidden>
    <div class="gp-auth-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gp-auth-modal-title">
        <button type="button" class="gp-auth-modal__close" data-gp-auth-modal-close aria-label="<?php esc_attr_e('Zamknij', 'gp-clone'); ?>">×</button>
        <div class="gp-auth-modal__grid">
            <section>
                <h3 id="gp-auth-modal-title"><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></h3>
                <?php if (gp_is_google_oauth_available()) : ?>
                    <div class="gp-auth-social" data-gp-google-button data-gp-context="login"></div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-gp-google-form>
                        <input type="hidden" name="action" value="gp_google_identity">
                        <input type="hidden" name="gp_context" value="login">
                        <input type="hidden" name="gp_google_nonce" value="<?php echo esc_attr(wp_create_nonce('gp_google_identity_nonce')); ?>">
                        <input type="hidden" name="credential" value="" data-gp-google-credential>
                        <input type="hidden" name="gp_google_request_nonce" value="<?php echo esc_attr(wp_create_nonce('gp_google_request_nonce')); ?>" data-gp-google-request-nonce>
                    </form>
                    <div class="gp-auth-separator"><span><?php esc_html_e('lub', 'gp-clone'); ?></span></div>
                <?php endif; ?>
                <form class="gp-auth-form gp-auth-form--compact" method="get" action="<?php echo esc_url(home_url('/zaloguj')); ?>">
                    <div>
                        <label for="gp-modal-email"><?php esc_html_e('Adres e-mail', 'gp-clone'); ?></label>
                        <input id="gp-modal-email" type="email" name="email" required>
                    </div>
                    <div>
                        <label for="gp-modal-password"><?php esc_html_e('Hasło', 'gp-clone'); ?></label>
                        <div class="gp-password-wrap">
                            <input id="gp-modal-password" type="password" name="password" required>
                            <button type="button" class="gp-password-toggle" data-gp-password-toggle="gp-modal-password">👁</button>
                        </div>
                    </div>
                    <div class="gp-auth-row">
                        <label class="gp-auth-checkbox"><input type="checkbox" name="remember_me"><?php esc_html_e('Zapamiętaj mnie', 'gp-clone'); ?></label>
                        <a href="#"><?php esc_html_e('Zapomniałeś hasła?', 'gp-clone'); ?></a>
                    </div>
                    <button type="submit" class="gp-auth-submit"><?php esc_html_e('Zaloguj się', 'gp-clone'); ?></button>
                </form>
            </section>
            <aside class="gp-auth-modal__side">
                <h4><?php esc_html_e('Nie masz konta?', 'gp-clone'); ?></h4>
                <a href="<?php echo esc_url(home_url('/zarejestruj')); ?>" class="gp-btn gp-btn--primary"><?php esc_html_e('Zarejestruj się', 'gp-clone'); ?></a>
                <a href="<?php echo esc_url($checkout_url); ?>" class="gp-btn gp-btn--outline"><?php esc_html_e('Kontynuuj jako gość', 'gp-clone'); ?></a>
                <ul class="gp-auth-modal__benefits">
                    <li><?php esc_html_e('Historia zamówień', 'gp-clone'); ?></li>
                    <li><?php esc_html_e('Uproszczone zwroty', 'gp-clone'); ?></li>
                </ul>
            </aside>
        </div>
    </div>
</div>

<?php if (is_front_page()) : ?>
<?php
$hero_slides = [
    [
        'title' => '',
        'image' => 'https://gpswiss.pl/wp-content/uploads/baner.png',
        'alt' => 'GP SWISS - największy wybór części używanych w Polsce',
    ],
];
?>
<section class="gp-hero" data-gp-hero-slider data-autoplay-ms="5500">
    <div class="gp-hero__slides" data-gp-hero-track>
        <?php foreach ($hero_slides as $index => $slide) : ?>
            <article
                class="gp-hero__slide<?php echo $index === 0 ? ' is-active' : ''; ?>"
                data-gp-hero-slide
                aria-hidden="<?php echo $index === 0 ? 'false' : 'true'; ?>"
            >
                <img
                    src="<?php echo esc_url($slide['image']); ?>"
                    alt="<?php echo esc_attr($slide['alt']); ?>"
                    width="1920"
                    height="700"
                    <?php if ($index === 0) : ?>
                        loading="eager"
                        fetchpriority="high"
                    <?php else : ?>
                        loading="lazy"
                        fetchpriority="low"
                    <?php endif; ?>
                    decoding="async"
                >
                <div class="gp-hero__overlay"></div>
                <div class="gp-hero__title-template" data-gp-hero-slide-title hidden><?php echo wp_kses_post($slide['title']); ?></div>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="gp-container gp-hero__content">
        <div class="gp-hero-promo" data-gp-hero-content>
            <h2 data-gp-hero-title><?php echo wp_kses_post($hero_slides[0]['title']); ?></h2>
        </div>
    </div>
    <button type="button" class="gp-hero__arrow gp-hero__arrow--prev" data-gp-hero-prev aria-label="<?php esc_attr_e('Poprzedni baner', 'gp-clone'); ?>">&#8249;</button>
    <button type="button" class="gp-hero__arrow gp-hero__arrow--next" data-gp-hero-next aria-label="<?php esc_attr_e('Następny baner', 'gp-clone'); ?>">&#8250;</button>
    <div class="gp-hero__dots" data-gp-hero-dots aria-label="<?php esc_attr_e('Wybór slajdu banera', 'gp-clone'); ?>"></div>
</section>
<?php endif; ?>
