<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Admin;

use GPS_Ebay_Fitment_Sync\Service\WooLaravelProductExport;
use GPS_Ebay_Fitment_Sync\Service\MarketplaceMappingExport;

final class WooLaravelProductExportPage
{
    private WooLaravelProductExport $exporter;

    public function __construct(WooLaravelProductExport $exporter) { $this->exporter = $exporter; }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('wp_ajax_gps_laravel_product_export_start', [$this, 'ajaxStart']);
        add_action('wp_ajax_gps_laravel_product_export_batch', [$this, 'ajaxBatch']);
        add_action('wp_ajax_gps_laravel_category_tree_export', [$this, 'ajaxCategoryTree']);
        add_action('wp_ajax_gps_marketplace_mapping_export', [$this, 'ajaxMarketplaceMapping']);
        add_action('admin_post_gps_laravel_product_export_download', [$this, 'download']);
    }

    public function adminMenu(): void
    {
        add_submenu_page('woocommerce', 'Eksport produktów WooCommerce do Laravel', 'Eksport do Laravel', 'manage_woocommerce', 'gps-laravel-product-export', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Brak uprawnień.', 'gps-ebay-fitment-sync'));
        $nonce = wp_create_nonce(WooLaravelProductExport::NONCE);
        echo '<div class="wrap"><h1>Eksport produktów WooCommerce do Laravel</h1>';
        echo '<p>Bezpieczny eksport tylko do odczytu: produkty, obrazy, kategorie, atrybuty i wybrane metadane do CSV.</p>';
        echo '<p><button class="button button-primary" id="gps-export-start">Start export</button> <button class="button" id="gps-export-products" disabled>Export products CSV</button> <button class="button" id="gps-export-images" disabled>Export images CSV if separated</button> <button class="button" id="gps-export-categories" disabled>Export categories CSV if separated</button> <button class="button button-secondary" id="gps-export-category-tree">Export Woo category tree for Laravel</button></p>';
        echo '<h2>Marketplace mapping dla Laravel</h2><p>Read-only export rzeczywistych identyfikatorów eBay/Ovoko/Allegro z lokalnej bazy Woo/WordPress. Nie wykonuje API calls ani zapisów Woo/marketplace.</p><p><button class="button button-primary" id="gps-export-marketplace-mapping">Export marketplace mapping data for Laravel</button></p><div id="gps-marketplace-summary"></div>';
        echo '<div id="gps-export-status" style="margin:1em 0;padding:1em;background:#fff;border:1px solid #ccd0d4;">Gotowe.</div><div id="gps-export-downloads"></div>';
        echo '<h2>Kolumny products.csv</h2><textarea readonly style="width:100%;height:160px;">' . esc_textarea(implode(', ', $this->exporter->productColumns())) . '</textarea>';
        echo '<script>jQuery(function($){let exportId="";function post(action,data){return $.post(ajaxurl,Object.assign({_ajax_nonce:"' . esc_js($nonce) . '",action:action},data||{}));}function downloads(s){if(!s.files)return;let html="<h2>Download generated files</h2><ul>";Object.keys(s.files).forEach(function(k){html+="<li><a class=button href=\"' . esc_url(admin_url('admin-post.php?action=gps_laravel_product_export_download')) . '&_wpnonce=' . esc_js($nonce) . '&export_id="+encodeURIComponent(s.export_id)+"&file="+encodeURIComponent(k)+"\">"+s.files[k]+"</a></li>";});html+="</ul>";$("#gps-export-downloads").html(html);}function tick(){post("gps_laravel_product_export_batch",{export_id:exportId,batch_size:200}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd eksportu");return;}let s=r.data;$("#gps-export-status").text("Status: "+s.status+", przetworzono: "+s.rows_processed+", last_product_id: "+s.last_product_id);downloads(s);if(s.status!=="completed") setTimeout(tick,250);});}$("#gps-export-start,#gps-export-products,#gps-export-images,#gps-export-categories").on("click",function(e){e.preventDefault();$("button[id^=gps-export]").prop("disabled",true);post("gps_laravel_product_export_start",{}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd startu");return;}exportId=r.data.export_id;$("#gps-export-status").text("Rozpoczęto: "+exportId);tick();});});$("#gps-export-category-tree").on("click",function(e){e.preventDefault();$("#gps-export-status").text("Eksport drzewa kategorii product_cat (read-only)...");post("gps_laravel_category_tree_export",{}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd eksportu drzewa kategorii");return;}exportId=r.data.export_id;$("#gps-export-status").text("Eksport drzewa kategorii gotowy. Kategorie: "+((r.data.summary&&r.data.summary.total_categories)||0));downloads(r.data);});});$("#gps-export-marketplace-mapping").on("click",function(e){e.preventDefault();$(this).prop("disabled",true);$("#gps-export-status").text("Eksport marketplace mapping (read-only)...");post("gps_marketplace_mapping_export",{}).done(function(r){$("#gps-export-marketplace-mapping").prop("disabled",false);if(!r.success){$("#gps-export-status").text("Błąd eksportu marketplace mapping");return;}exportId=r.data.export_id;let s=r.data.summary||{};$("#gps-export-status").text("Marketplace mapping gotowy. Produkty: "+(s.total_products||0)+", eBay rows: "+(s.ebay_listing_rows||0)+", Ovoko rows: "+(s.ovoko_mapping_rows||0)+", Allegro rows: "+(s.allegro_mapping_rows||0));$("#gps-marketplace-summary").html("<h3>Detected counts</h3><pre>"+JSON.stringify({marketplace_products:s.products_with_any_marketplace_identifier,ebay:s.products_with_ebay_identifier,ovoko:s.products_with_ovoko_part_id,rrr:s.products_with_rrr_part_id,allegro:s.products_with_allegro_identifier,meta_keys:s.detected_meta_keys,tables:s.detected_custom_tables},null,2)+"</pre>");downloads(r.data);});});});</script>';
        echo '</div>';
    }

    public function ajaxStart(): void { $this->guardAjax(); wp_send_json_success($this->exporter->start()); }
    public function ajaxBatch(): void { $this->guardAjax(); $id = isset($_POST['export_id']) ? sanitize_key(wp_unslash($_POST['export_id'])) : ''; $batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 200; wp_send_json_success($this->exporter->runBatch($id, $batch)); }
    public function ajaxCategoryTree(): void { $this->guardAjax(); wp_send_json_success($this->exporter->exportCategoryTree()); }
    public function ajaxMarketplaceMapping(): void { $this->guardAjax(); wp_send_json_success((new MarketplaceMappingExport())->export()); }

    public function download(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        check_admin_referer(WooLaravelProductExport::NONCE);
        $id = isset($_GET['export_id']) ? sanitize_key(wp_unslash($_GET['export_id'])) : '';
        $file = isset($_GET['file']) ? sanitize_key(wp_unslash($_GET['file'])) : '';
        $state = $this->exporter->state($id);
        $path = (string)($state['files'][$file] ?? '');
        if ($path === '' || !is_readable($path)) wp_die('file not found');
        header('Content-Type: text/csv; charset=utf-8');
        if ($file === 'summary' || $file === 'manifest' || $file === 'products_json' || substr($file, -5) === '_json' || substr($file, -8) === '_summary') header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }

    private function guardAjax(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['error'=>'forbidden'], 403);
        check_ajax_referer(WooLaravelProductExport::NONCE);
    }
}
