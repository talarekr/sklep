<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="gp-brand-selector">
    <div class="gp-container">
        <h2 class="gp-section-title">Wybierz markę</h2>
        <div class="gp-brand-row">
            <?php foreach (['BMW', 'MINI', 'Mercedes', 'Volkswagen', 'Audi'] as $brand) : ?>
                <a class="gp-brand-item" href="#"><?php echo esc_html($brand); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
