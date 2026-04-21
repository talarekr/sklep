<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="gp-repeat-search">
    <div class="gp-container">
        <form class="gp-search" action="<?php echo esc_url(home_url('/')); ?>" method="get">
            <div class="gp-search__switch" data-search-switch>
                <button type="button" class="is-active" data-mode="part">Numer części</button>
                <button type="button" data-mode="model">Model pojazdu</button>
            </div>
            <input type="text" name="s" placeholder="Wpisz numer części lub model pojazdu">
            <button type="submit">Szukaj</button>
        </form>
    </div>
</section>
