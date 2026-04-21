<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();

get_template_part('template-parts/home/top-bar');
get_template_part('template-parts/home/store-header');
get_template_part('template-parts/home/banners');
get_template_part('template-parts/home/home-title');
get_template_part('template-parts/home/popular-products');

get_footer();
