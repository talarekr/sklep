<?php if (!defined('ABSPATH')) { exit; }
$banners = [
    ['title' => 'Wyprzedaż do -30%', 'text' => 'Sprawdź przecenione silniki, skrzynie i elementy karoserii w ograniczonej ofercie.', 'cta' => 'KUP TERAZ'],
    ['title' => 'Kup bez pośredników i oszczędź 5%', 'text' => 'Kupując bezpośrednio w sklepie zapłacisz nawet 5% taniej niż na platformach zakupowych.', 'cta' => 'KUP TERAZ'],
    ['title' => 'Oferta dla warsztatów', 'text' => 'Dołącz do programu B2B i uzyskaj dedykowane rabaty oraz opiekuna handlowego.', 'cta' => 'ZAREJESTRUJ SWÓJ WARSZTAT'],
    ['title' => '90 dni gwarancji', 'text' => 'Na używany silnik, skrzynię biegów lub dyferencjał otrzymujesz aż 90 dni gwarancji.', 'cta' => 'KUP TERAZ'],
];
?>
<section class="gp-banners">
    <div class="gp-container">
        <div class="gp-banner-grid">
            <?php foreach ($banners as $banner) : ?>
                <article class="gp-banner">
                    <div>
                        <h3><?php echo esc_html($banner['title']); ?></h3>
                        <p><?php echo esc_html($banner['text']); ?></p>
                    </div>
                    <a href="#" class="gp-btn"><?php echo esc_html($banner['cta']); ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
