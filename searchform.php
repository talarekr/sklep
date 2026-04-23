<?php if (!defined('ABSPATH')) { exit; } ?>
<form class="gp-search" action="<?php echo esc_url(home_url('/')); ?>" method="get">
    <div class="gp-search__switch" data-search-switch>
        <button type="button" class="is-active" data-mode="part">Numer części</button>
        <button type="button" data-mode="model">Model pojazdu</button>
    </div>
    <input type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="Wpisz numer części lub model pojazdu">
    <input type="hidden" name="post_type" value="product">
    <button type="submit">Szukaj</button>
</form>
