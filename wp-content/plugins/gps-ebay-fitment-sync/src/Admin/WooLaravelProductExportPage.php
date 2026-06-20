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
        add_action('wp_ajax_gps_marketplace_mapping_last_export', [$this, 'ajaxMarketplaceMappingLastExport']);
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
        echo '<h2>Marketplace mapping dla Laravel</h2><p>Read-only export rzeczywistych identyfikatorów eBay/Ovoko/Allegro z lokalnej bazy Woo/WordPress. Nie wykonuje API calls ani zapisów Woo/marketplace.</p>';
        echo $this->renderMarketplaceLastExportFallback();
        echo '<p><button class="button button-primary" id="gps-export-marketplace-mapping">Export marketplace mapping data for Laravel</button> <button class="button" id="gps-refresh-marketplace-links">Refresh export links</button></p><div id="gps-marketplace-summary"></div><div id="gps-marketplace-downloads"></div><div id="gps-marketplace-last-export"></div>';
        echo '<div id="gps-export-status" style="margin:1em 0;padding:1em;background:#fff;border:1px solid #ccd0d4;">Gotowe.</div><div id="gps-export-downloads"></div>';
        echo '<h2>Kolumny products.csv</h2><textarea readonly style="width:100%;height:160px;">' . esc_textarea(implode(', ', $this->exporter->productColumns())) . '</textarea>';
        echo '<script>jQuery(function($){let exportId="";function post(action,data){return $.post(ajaxurl,Object.assign({_ajax_nonce:"' . esc_js($nonce) . '",action:action},data||{}));}function esc(v){return $("<div>").text(v==null?"":String(v)).html();}function formatSize(b){if(b==null||b==="")return "";b=parseInt(b,10);if(!isFinite(b))return b+" B";return b+" B";}function fileListHtml(files){files=files||{};let keys=Object.keys(files);if(!keys.length)return "<p><em>Brak wygenerowanych plików marketplace mapping.</em></p>";let html="<table class=\"widefat striped\"><thead><tr><th>Filename</th><th>File size</th><th>Modified time</th><th>Download</th></tr></thead><tbody>";keys.forEach(function(k){let f=files[k], name=typeof f==="string"?f:(f.filename||k), url=typeof f==="object"?f.download_url:"", err=typeof f==="object"?f.download_error:"";if(!url&&typeof f==="string"&&exportId){url="' . esc_url(admin_url('admin-post.php?action=gps_laravel_product_export_download')) . '&_wpnonce=' . esc_js($nonce) . '&export_id="+encodeURIComponent(exportId)+"&file="+encodeURIComponent(k);}html+="<tr><td>"+esc(name)+(err?" <span class=\"notice notice-error inline\">"+esc(err)+"</span>":"")+"</td><td>"+esc(typeof f==="object"?formatSize(f.file_size):"")+"</td><td>"+esc(typeof f==="object"?(f.modified_time||""):"")+"</td><td>"+(url?"<a class=button href=\""+esc(url)+"\">Download</a>":"<span class=\"notice notice-warning inline\">Missing file or download URL</span>")+"</td></tr>";});return html+"</tbody></table>";}function renderSummary(s){s=s||{};let counts={total_products:s.total_products||0,products_with_any_marketplace_identifier:s.products_with_any_marketplace_identifier||0,products_with_ebay_identifier:s.products_with_ebay_identifier||0,products_with_ovoko_part_id:s.products_with_ovoko_part_id||0,products_with_allegro_identifier:s.products_with_allegro_identifier||0};$("#gps-marketplace-summary").html("<h3>Marketplace mapping summary</h3><pre>"+esc(JSON.stringify(counts,null,2))+"</pre>");}function renderMarketplace(data,target,title){data=data||{};exportId=data.export_id||exportId;let warnings=data.warnings||data.missing_files||[], debug=data.debug||{};let debugHtml="<p><code>last_export_id="+esc(debug.last_export_id||data.export_id||"")+"; export_dir="+esc(debug.export_dir||"")+"; files_found_count="+esc(debug.files_found_count==null?"":debug.files_found_count)+"</code></p>";let html="<h2>"+esc(title)+"</h2>"+debugHtml+(warnings.length?"<div class=\"notice notice-warning inline\"><p>"+esc(warnings.join(", "))+"</p></div>":"")+fileListHtml(data.files);$(target).html(html);renderSummary(data.summary||{});}function downloads(s){if(!s.files)return;let html="<h2>Download generated files</h2><ul>";Object.keys(s.files).forEach(function(k){let f=s.files[k], label=typeof f==="object"?(f.filename||k):f, url=typeof f==="object"?f.download_url:"";if(!url)url="' . esc_url(admin_url('admin-post.php?action=gps_laravel_product_export_download')) . '&_wpnonce=' . esc_js($nonce) . '&export_id="+encodeURIComponent(s.export_id)+"&file="+encodeURIComponent(k);html+="<li><a class=button href=\""+esc(url)+"\">"+esc(label)+"</a></li>";});html+="</ul>";$("#gps-export-downloads").html(html);}function tick(){post("gps_laravel_product_export_batch",{export_id:exportId,batch_size:200}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd eksportu");return;}let s=r.data;$("#gps-export-status").text("Status: "+s.status+", przetworzono: "+s.rows_processed+", last_product_id: "+s.last_product_id);downloads(s);if(s.status!=="completed") setTimeout(tick,250);});}$("#gps-export-start,#gps-export-products,#gps-export-images,#gps-export-categories").on("click",function(e){e.preventDefault();$("button[id^=gps-export]").prop("disabled",true);post("gps_laravel_product_export_start",{}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd startu");return;}exportId=r.data.export_id;$("#gps-export-status").text("Rozpoczęto: "+exportId);tick();});});$("#gps-export-category-tree").on("click",function(e){e.preventDefault();$("#gps-export-status").text("Eksport drzewa kategorii product_cat (read-only)...");post("gps_laravel_category_tree_export",{}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd eksportu drzewa kategorii");return;}exportId=r.data.export_id;$("#gps-export-status").text("Eksport drzewa kategorii gotowy. Kategorie: "+((r.data.summary&&r.data.summary.total_categories)||0));downloads(r.data);});});$("#gps-export-marketplace-mapping").on("click",function(e){e.preventDefault();$(this).prop("disabled",true);$("#gps-export-status").text("Eksport marketplace mapping (read-only)...");post("gps_marketplace_mapping_export",{}).done(function(r){$("#gps-export-marketplace-mapping").prop("disabled",false);if(!r.success||!r.data||r.data.status==="error"){let msg=(r.data&&r.data.error)||"Błąd eksportu marketplace mapping";$("#gps-export-status").text(msg);if(r.data)renderMarketplace(r.data,"#gps-marketplace-downloads","Marketplace mapping export");return;}let s=r.data.summary||{};$("#gps-export-status").text("Gotowe. Produkty: "+(s.total_products||0));renderMarketplace(r.data,"#gps-marketplace-downloads","Marketplace mapping export downloads");});});$("#gps-refresh-marketplace-links").on("click",function(e){e.preventDefault();post("gps_marketplace_mapping_last_export",{}).done(function(r){if(r.success&&r.data)renderMarketplace(r.data,"#gps-marketplace-last-export","Ostatni eksport marketplace mapping");});});post("gps_marketplace_mapping_last_export",{}).done(function(r){if(r.success&&r.data&&r.data.export_id)renderMarketplace(r.data,"#gps-marketplace-last-export","Ostatni eksport marketplace mapping");});});</script>';
        echo '</div>';
    }

    private function renderMarketplaceLastExportFallback(): string
    {
        $data = (new MarketplaceMappingExport())->lastExport();
        $debug = (array) ($data['debug'] ?? []);
        $html = '<div id="gps-marketplace-last-export-fallback" style="margin:1em 0;"><h3>Ostatni eksport marketplace mapping</h3><!-- Last marketplace mapping export -->';
        $html .= '<p><code>last_export_id=' . esc_html((string) ($debug['last_export_id'] ?? ($data['export_id'] ?? ''))) . '; export_dir=' . esc_html((string) ($debug['export_dir'] ?? '')) . '; files_found_count=' . esc_html((string) ($debug['files_found_count'] ?? '0')) . '</code></p>';
        $files = (array) ($data['files'] ?? []);
        if ((int) ($debug['files_found_count'] ?? 0) < 1) {
            $html .= '<p><em>Brak wygenerowanych plików marketplace mapping.</em></p>';
        } else {
            $html .= '<table class="widefat striped"><thead><tr><th>Filename</th><th>File size</th><th>Modified time</th><th>Download</th></tr></thead><tbody>';
            foreach ($files as $file) {
                $downloadUrl = (string) ($file['download_url'] ?? '');
                $exists = !empty($file['exists']);
                $html .= '<tr><td>' . esc_html((string) ($file['filename'] ?? '')) . ($exists ? '' : ' <span class="notice notice-warning inline">Missing file</span>') . '</td><td>' . ($exists ? esc_html(size_format((int) ($file['file_size'] ?? 0))) : '') . '</td><td>' . esc_html((string) ($file['modified_time'] ?? '')) . '</td><td>';
                $html .= $downloadUrl !== '' ? '<a class="button" href="' . esc_url($downloadUrl) . '">Download</a>' : ($exists ? '<span class="notice notice-error inline">File exists on disk but download URL could not be generated.</span>' : '<span class="notice notice-warning inline">Missing file</span>');
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '</div>';
    }

    public function ajaxStart(): void { $this->guardAjax(); wp_send_json_success($this->exporter->start()); }
    public function ajaxBatch(): void { $this->guardAjax(); $id = isset($_POST['export_id']) ? sanitize_key(wp_unslash($_POST['export_id'])) : ''; $batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 200; wp_send_json_success($this->exporter->runBatch($id, $batch)); }
    public function ajaxCategoryTree(): void { $this->guardAjax(); wp_send_json_success($this->exporter->exportCategoryTree()); }
    public function ajaxMarketplaceMapping(): void { $this->guardAjax(); $state = (new MarketplaceMappingExport())->export(); if (($state['status'] ?? '') === 'error') wp_send_json_error($state, 500); wp_send_json_success($state); }
    public function ajaxMarketplaceMappingLastExport(): void { $this->guardAjax(); wp_send_json_success((new MarketplaceMappingExport())->lastExport()); }

    public function download(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        check_admin_referer(WooLaravelProductExport::NONCE);
        $id = isset($_GET['export_id']) ? sanitize_key(wp_unslash($_GET['export_id'])) : '';
        $file = isset($_GET['file_key']) ? sanitize_key(wp_unslash($_GET['file_key'])) : (isset($_GET['file']) ? sanitize_key(wp_unslash($_GET['file'])) : '');
        $filename = isset($_GET['filename']) ? sanitize_file_name(wp_unslash($_GET['filename'])) : '';
        if (strpos($id, 'marketplace-mapping-') === 0) {
            $path = (new MarketplaceMappingExport())->filePathForDownload($id, $file !== '' ? $file : $filename);
            if ($path === '') wp_die('marketplace mapping file not found');
            header(substr($path, -5) === '.json' ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
            exit;
        }
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
