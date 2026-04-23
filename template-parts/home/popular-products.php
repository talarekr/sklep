<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$shop_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : '#';

$section_definitions = [
    [
        'title' => __('Silniki kompletne', 'gp-clone'),
        'terms' => ['silniki-kompletne'],
    ],
    [
        'title' => __('Skrzynie kompletne', 'gp-clone'),
        'terms' => ['kompletne-skrzynie'],
    ],
    [
        'title' => __('Zwrotnice', 'gp-clone'),
        'terms' => ['zwrotnice'],
    ],
    [
        'title' => __('Filtry DPF', 'gp-clone'),
        'terms' => ['filtry-czastek-stalych-dpf-fap'],
    ],
];

$category_tiles = [
    ['label' => 'Silniki i osprzęt', 'terms' => ['silniki', 'silnik', 'engines'], 'icon' => 'engine'],
    ['label' => 'Skrzynie biegów i napędy', 'terms' => ['skrzynie-biegow', 'napedy', 'transmission'], 'icon' => 'gearbox'],
    ['label' => 'Felgi i opony', 'terms' => ['felgi', 'opony', 'wheels'], 'icon' => 'wheel'],
    ['label' => 'Układ kierowniczy', 'terms' => ['kierownice', 'uklad-kierowniczy', 'steering'], 'icon' => 'steering'],
    ['label' => 'Układ hamulcowy', 'terms' => ['hamulce', 'uklad-hamulcowy', 'brakes'], 'icon' => 'brake'],
    ['label' => 'Oświetlenie', 'terms' => ['oswietlenie', 'lampy', 'lighting'], 'icon' => 'light'],
    ['label' => 'Zawieszenie', 'terms' => ['zawieszenie', 'suspension'], 'icon' => 'suspension'],
    ['label' => 'Elektronika', 'terms' => ['elektronika', 'electronic'], 'icon' => 'chip'],
    ['label' => 'Wnętrze / kokpit', 'terms' => ['wnetrze', 'kokpit', 'interior'], 'icon' => 'interior'],
    ['label' => 'Karoseria', 'terms' => ['karoseria', 'body'], 'icon' => 'body'],
    ['label' => 'Chłodzenie', 'terms' => ['chlodzenie', 'cooling'], 'icon' => 'cooling'],
    ['label' => 'Akcesoria', 'terms' => ['akcesoria', 'accessories'], 'icon' => 'accessory'],
];

$brand_logos = [
    ['name' => 'BMW', 'file' => 'assets/images/brands/bmw.jpg'],
    ['name' => 'AUDI', 'file' => 'assets/images/brands/audi.jpg'],
    ['name' => 'Volkswagen', 'file' => 'assets/images/brands/volkswagen.jpg'],
    ['name' => 'Skoda', 'file' => 'assets/images/brands/skoda.jpg'],
];

$normalize_term = static function (string $value): string {
    $normalized = sanitize_title($value);
    return str_replace('-', '', $normalized);
};

$resolve_terms_for_section = static function (array $candidate_terms) use ($normalize_term): array {
    if (!taxonomy_exists('product_cat')) {
        return [];
    }

    $all_terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ]);

    if (is_wp_error($all_terms) || empty($all_terms)) {
        return [];
    }

    $exact = [];
    $partial = [];
    $needles = array_map(
        static fn(string $term): string => $normalize_term($term),
        $candidate_terms
    );

    foreach ($all_terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $haystack = $normalize_term($term->slug . ' ' . $term->name);

        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            if ($haystack === $needle) {
                $exact[$term->term_id] = $term;
                break;
            }

            if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                $partial[$term->term_id] = $term;
                break;
            }
        }
    }

    return array_values($exact + $partial);
};

$get_product_categories = static function (array $candidate_slugs) use ($resolve_terms_for_section): array {
    return $resolve_terms_for_section($candidate_slugs);
};

$get_products_for_section = static function (array $candidate_terms) use ($resolve_terms_for_section): array {
    if (!class_exists('WooCommerce')) {
        return [];
    }

    $terms = $resolve_terms_for_section($candidate_terms);
    $term_ids = array_map(static fn(WP_Term $term): int => (int) $term->term_id, $terms);

    $args = [
        'status' => 'publish',
        'limit' => 12,
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    if ($term_ids !== []) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => array_unique($term_ids),
                'operator' => 'IN',
            ],
        ];
        return wc_get_products($args);
    }

    return wc_get_products($args);
};

$get_tile_url = static function (array $candidate_terms) use ($get_product_categories, $shop_url): string {
    $categories = $get_product_categories($candidate_terms);
    if (!empty($categories)) {
        $link = get_term_link($categories[0]);
        if (!is_wp_error($link)) {
            return $link;
        }
    }

    return $shop_url;
};

$icon = static function (string $type): string {
    $paths = [
        'engine' => '<rect x="8" y="12" width="16" height="10" rx="2"/><path d="M6 15h2m16 0h2M12 10V7m8 3V8"/>',
        'gearbox' => '<circle cx="12" cy="17" r="3"/><path d="M12 6v4m0 14v-4m8-3h-4M8 17H4m13.7-5.7-2.8 2.8m0 5.8 2.8 2.8M8.3 11.3l2.8 2.8m0 5.8-2.8 2.8"/>',
        'wheel' => '<circle cx="16" cy="16" r="10"/><circle cx="16" cy="16" r="3"/><path d="M16 6v7l6-3m-12 6h6l-4 6"/>',
        'steering' => '<circle cx="16" cy="16" r="10"/><path d="M6 16h20M16 16l-3-5m3 5 3-5m-3 5v10"/>',
        'brake' => '<circle cx="16" cy="16" r="10"/><circle cx="16" cy="16" r="4"/><path d="M23 10l3-2v16l-3-2"/>',
        'light' => '<path d="M8 12h10a6 6 0 010 12H8z"/><path d="M20 14l5-1m-5 4h5m-5 4 5 1"/>',
        'suspension' => '<path d="M9 8l6 6-6 6m8-12 6 6-6 6"/>',
        'chip' => '<rect x="10" y="10" width="12" height="12" rx="1"/><path d="M14 6v4m4-4v4m4-4v4M14 22v4m4-4v4m4-4v4M6 14h4m-4 4h4m-4 4h4M22 14h4m-4 4h4m-4 4h4"/>',
        'interior' => '<path d="M6 18h20l-2-8H8z"/><path d="M11 18v-3h10v3"/>',
        'body' => '<path d="M5 18h22l-2-6-4-2H11L7 12z"/><circle cx="10" cy="18" r="2"/><circle cx="22" cy="18" r="2"/>',
        'cooling' => '<circle cx="16" cy="16" r="3"/><path d="M16 6c1 3 2 5 4 7-2 0-4 1-4 3-1-2-3-3-5-3 2-2 4-4 5-7zm0 20c-1-3-2-5-4-7 2 0 4-1 4-3 1 2 3 3 5 3-2 2-4 4-5 7z"/>',
        'accessory' => '<rect x="8" y="8" width="16" height="16" rx="3"/><path d="M12 16h8m-4-4v8"/>',
    ];

    $body = $paths[$type] ?? $paths['accessory'];

    return '<svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $body . '</g></svg>';
};
?>

<div class="gp-home-middle">
    <?php foreach ($section_definitions as $index => $section) : ?>
        <?php $products = $get_products_for_section($section['terms']); ?>
        <?php if (empty($products)) { continue; } ?>
        <section class="gp-home-products" data-gp-carousel>
            <div class="gp-container">
                <div class="gp-popular__head">
                    <h2 class="gp-section-title"><?php echo esc_html($section['title']); ?></h2>
                    <a class="gp-home-section-link" href="<?php echo esc_url($get_tile_url($section['terms'])); ?>"><?php esc_html_e('Pokaż wszystkie', 'gp-clone'); ?></a>
                </div>
                <div class="gp-carousel__viewport" data-gp-carousel-viewport>
                    <div class="gp-products gp-carousel__track" data-gp-carousel-track>
                        <?php foreach ($products as $product) : ?>
                            <?php get_template_part('template-parts/product/product-card', null, ['product' => $product, 'wrapper_classes' => 'gp-carousel__slide']); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="gp-carousel__nav" data-gp-carousel-nav>
                    <button type="button" class="gp-carousel__arrow" data-gp-prev aria-label="<?php esc_attr_e('Poprzednie produkty', 'gp-clone'); ?>">&#8249;</button>
                    <div class="gp-carousel__dots" data-gp-carousel-dots></div>
                    <button type="button" class="gp-carousel__arrow" data-gp-next aria-label="<?php esc_attr_e('Następne produkty', 'gp-clone'); ?>">&#8250;</button>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if (!is_front_page()) : ?>
    <section class="gp-home-categories">
        <div class="gp-container">
            <h2 class="gp-section-title"><?php esc_html_e('Kategorie części i akcesoriów samochodowych', 'gp-clone'); ?></h2>
            <div class="gp-categories-grid">
                <?php foreach ($category_tiles as $tile) : ?>
                    <a class="gp-category-tile" href="<?php echo esc_url($get_tile_url($tile['terms'])); ?>">
                        <span class="gp-category-tile__icon"><?php echo $icon($tile['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <span class="gp-category-tile__name"><?php echo esc_html($tile['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="gp-home-brands">
        <div class="gp-container">
            <h2 class="gp-section-title"><?php esc_html_e('Nasze marki', 'gp-clone'); ?></h2>
            <div class="gp-brand-logos" role="list" aria-label="<?php esc_attr_e('Marki sklepu', 'gp-clone'); ?>">
                <?php foreach ($brand_logos as $brand) : ?>
                    <span class="gp-brand-logo" role="listitem">
                        <img src="<?php echo esc_url(get_template_directory_uri() . '/' . $brand['file']); ?>" alt="<?php echo esc_attr($brand['name']); ?>" loading="lazy" />
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>
