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
        add_action('wp_ajax_gps_marketplace_mapping_export', [$this, 'ajaxMarketplaceMappingStart']);
        add_action('wp_ajax_gps_marketplace_mapping_start', [$this, 'ajaxMarketplaceMappingStart']);
        add_action('wp_ajax_gps_marketplace_mapping_continue', [$this, 'ajaxMarketplaceMappingContinue']);
        add_action('wp_ajax_gps_marketplace_mapping_finalize', [$this, 'ajaxMarketplaceMappingFinalize']);
        add_action('wp_ajax_gps_marketplace_mapping_reset', [$this, 'ajaxMarketplaceMappingReset']);
        add_action('wp_ajax_gps_marketplace_mapping_last_export', [$this, 'ajaxMarketplaceMappingLastExport']);
        add_action('wp_ajax_gps_marketplace_mapping_filesystem_diagnostic', [$this, 'ajaxMarketplaceMappingFilesystemDiagnostic']);
        add_action('wp_ajax_gps_marketplace_mapping_product_discovery_diagnostic', [$this, 'ajaxMarketplaceMappingProductDiscoveryDiagnostic']);
        add_action('admin_post_gps_laravel_product_export_download', [$this, 'download']);
        add_action('admin_post_gps_marketplace_mapping_export_safe_download', [$this, 'download']);
        add_action('wp_ajax_gps_marketplace_mapping_test_download_resolution', [$this, 'ajaxMarketplaceMappingTestDownloadResolution']);
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
        echo '<div class="notice notice-info inline"><p><strong>Marketplace mapping admin code:</strong> ' . esc_html(MarketplaceMappingExport::DIAGNOSTICS_VERSION) . ' | handler file mtime: ' . esc_html(gmdate('Y-m-d H:i:s', (int) filemtime(__FILE__)) . ' UTC') . '</p></div>';
        echo '<div class="notice notice-info inline"><p><strong>Deploy marker:</strong> plugin version ' . esc_html(defined('GPS_EBAY_FITMENT_SYNC_VERSION') ? GPS_EBAY_FITMENT_SYNC_VERSION : '') . '; plugin file mtime ' . esc_html(defined('GPS_EBAY_FITMENT_SYNC_FILE') && is_readable(GPS_EBAY_FITMENT_SYNC_FILE) ? gmdate('Y-m-d H:i:s', (int) filemtime(GPS_EBAY_FITMENT_SYNC_FILE)) . ' UTC' : 'unavailable') . '</p></div>';
        echo $this->renderMarketplaceLastExportFallback();
        echo '<p><button class="button button-primary" id="gps-export-marketplace-mapping">Export marketplace mapping data for Laravel</button> <button class="button" id="gps-refresh-marketplace-links">Refresh export links</button> <button class="button" id="gps-reset-marketplace-mapping">Reset marketplace mapping export</button> <button class="button" id="gps-marketplace-filesystem-diagnostic">Check marketplace export filesystem</button> <button class="button" id="gps-marketplace-test-download-resolution">Test download resolution</button> <button class="button" id="gps-marketplace-product-discovery-diagnostic">Run product discovery diagnostic only</button></p><div id="gps-marketplace-summary"></div><div id="gps-marketplace-downloads"></div><div id="gps-marketplace-last-export"></div>';
        echo '<div id="gps-export-status" style="margin:1em 0;padding:1em;background:#fff;border:1px solid #ccd0d4;">Gotowe.</div><div id="gps-export-downloads"></div>';
        echo '<h2>Kolumny products.csv</h2><textarea readonly style="width:100%;height:160px;">' . esc_textarea(implode(', ', $this->exporter->productColumns())) . '</textarea>';
        echo '<script>jQuery(function($){let exportId="";function post(action,data){return $.post(ajaxurl,Object.assign({_ajax_nonce:"' . esc_js($nonce) . '",action:action},data||{}));}function esc(v){return $("<div>").text(v==null?"":String(v)).html();}function formatSize(b){if(b==null||b==="")return "";b=parseInt(b,10);if(!isFinite(b))return b+" B";return b+" B";}function fileListHtml(files){files=files||{};let keys=Object.keys(files);if(!keys.length)return "<p><em>Brak wygenerowanych plików marketplace mapping.</em></p>";let html="<table class=\"widefat striped\"><thead><tr><th>file_key</th><th>filename</th><th>absolute_path</th><th>exists</th><th>readable</th><th>file_size</th><th>download_handler_resolves_path</th><th>last_download_error</th><th>download_url</th></tr></thead><tbody>";keys.forEach(function(k){let f=files[k], name=typeof f==="string"?f:(f.filename||k), url=typeof f==="object"?f.download_url:"", err=typeof f==="object"?f.download_error:"";if(!url&&typeof f==="string"&&exportId){url="' . esc_url(admin_url('admin-post.php?action=gps_laravel_product_export_download')) . '&_wpnonce=' . esc_js($nonce) . '&export_id="+encodeURIComponent(exportId)+"&file="+encodeURIComponent(k);}html+="<tr><td>"+esc(name)+(err?" <span class=\"notice notice-error inline\">"+esc(err)+"</span>":"")+"</td><td>"+esc(typeof f==="object"?formatSize(f.file_size):"")+"</td><td>"+esc(typeof f==="object"?(f.modified_time||""):"")+"</td><td>"+(url?"<a class=button href=\""+esc(url)+"\">Download</a>":"<span class=\"notice notice-warning inline\">Missing file or download URL</span>")+"</td></tr>";});return html+"</tbody></table>";}function renderSummary(s){s=s||{};let counts={total_products:s.total_products||0,products_with_any_marketplace_identifier:s.products_with_any_marketplace_identifier||0,products_with_ebay_identifier:s.products_with_ebay_identifier||0,products_with_ovoko_part_id:s.products_with_ovoko_part_id||0,products_with_allegro_identifier:s.products_with_allegro_identifier||0};$("#gps-marketplace-summary").html("<h3>Marketplace mapping summary</h3><pre>"+esc(JSON.stringify(counts,null,2))+"</pre>");}function renderMarketplace(data,target,title){data=data||{};exportId=data.export_id||exportId;let warnings=data.warnings||data.missing_files||[], debug=data.debug||{};let stale=!debug.diagnostics_version?\'<div class="notice notice-error inline"><p>Marketplace mapping diagnostics version missing — stale code or wrong handler.</p></div>\':\'\';let rows=debug.direct_sql_product_count_by_post_type_status||debug.product_counts_by_status||{};let table=\'<table class="widefat striped"><thead><tr><th>post_type</th><th>post_status</th><th>count</th></tr></thead><tbody>\';Object.keys(rows).forEach(function(pt){Object.keys(rows[pt]||{}).forEach(function(st){table+=\'<tr><td>\'+esc(pt)+\'</td><td>\'+esc(st)+\'</td><td>\'+esc(rows[pt][st])+\'</td></tr>\';});});table+=\'</tbody></table>\';let debugHtml=stale+"<p><code>marketplace_mapping_export_code_version="+esc(debug.marketplace_mapping_export_code_version||"")+"; service_class="+esc(debug.service_class||"")+"; service_file_mtime="+esc(debug.service_file_mtime||"")+"; ajax_handler_file_mtime="+esc(debug.ajax_handler_file_mtime||"")+"</code></p><p><code>last_export_id="+esc(debug.last_export_id||data.export_id||"")+"; export_dir="+esc(debug.export_dir||"")+"; files_found_count="+esc(debug.files_found_count==null?"":debug.files_found_count)+"</code></p><h3>Direct SQL Woo product counts</h3>"+table+"<details open><summary>Marketplace export diagnostics</summary><pre>"+esc(JSON.stringify(debug,null,2))+"</pre></details>";let html="<h2>"+esc(title)+"</h2>"+debugHtml+(warnings.length?"<div class=\"notice notice-warning inline\"><p>"+esc(warnings.join(", "))+"</p></div>":"")+"<details open><summary>Download file diagnostics</summary><pre>"+esc(JSON.stringify(data.files||{},null,2))+"</pre></details>"+fileListHtml(data.files);$(target).html(html);renderSummary(data.summary||{});}function downloads(s){if(!s.files)return;let html="<h2>Download generated files</h2><ul>";Object.keys(s.files).forEach(function(k){let f=s.files[k], label=typeof f==="object"?(f.filename||k):f, url=typeof f==="object"?f.download_url:"";if(!url)url="' . esc_url(admin_url('admin-post.php?action=gps_laravel_product_export_download')) . '&_wpnonce=' . esc_js($nonce) . '&export_id="+encodeURIComponent(s.export_id)+"&file="+encodeURIComponent(k);html+="<li><a class=button href=\""+esc(url)+"\">"+esc(label)+"</a></li>";});html+="</ul>";$("#gps-export-downloads").html(html);}function tick(){post("gps_laravel_product_export_batch",{export_id:exportId,batch_size:200}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd eksportu");return;}let s=r.data;$("#gps-export-status").text("Status: "+s.status+", przetworzono: "+s.rows_processed+", last_product_id: "+s.last_product_id);downloads(s);if(s.status!=="completed") setTimeout(tick,250);});}$("#gps-export-start,#gps-export-products,#gps-export-images,#gps-export-categories").on("click",function(e){e.preventDefault();$("button[id^=gps-export]").prop("disabled",true);post("gps_laravel_product_export_start",{}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd startu");return;}exportId=r.data.export_id;$("#gps-export-status").text("Rozpoczęto: "+exportId);tick();});});$("#gps-export-category-tree").on("click",function(e){e.preventDefault();$("#gps-export-status").text("Eksport drzewa kategorii product_cat (read-only)...");post("gps_laravel_category_tree_export",{}).done(function(r){if(!r.success){$("#gps-export-status").text("Błąd eksportu drzewa kategorii");return;}exportId=r.data.export_id;$("#gps-export-status").text("Eksport drzewa kategorii gotowy. Kategorie: "+((r.data.summary&&r.data.summary.total_categories)||0));downloads(r.data);});});$("#gps-export-marketplace-mapping").on("click",function(e){e.preventDefault();$(this).prop("disabled",true);$("#gps-export-status").text("Eksport marketplace mapping (read-only)...");post("gps_marketplace_mapping_export",{}).done(function(r){$("#gps-export-marketplace-mapping").prop("disabled",false);if(!r.success||!r.data||r.data.status==="error"){let msg=(r.data&&r.data.error)||"Błąd eksportu marketplace mapping";$("#gps-export-status").text(msg);if(r.data)renderMarketplace(r.data,"#gps-marketplace-downloads","Marketplace mapping export");return;}let s=r.data.summary||{};$("#gps-export-status").text("Gotowe. Produkty: "+(s.total_products||0));renderMarketplace(r.data,"#gps-marketplace-downloads","Marketplace mapping export downloads");});});$("#gps-refresh-marketplace-links").on("click",function(e){e.preventDefault();post("gps_marketplace_mapping_last_export",{}).done(function(r){if(r.success&&r.data)renderMarketplace(r.data,"#gps-marketplace-last-export","Ostatni eksport marketplace mapping");});});$("#gps-marketplace-filesystem-diagnostic").on("click",function(e){e.preventDefault();$("#gps-export-status").text("Checking marketplace export filesystem...");post("gps_marketplace_mapping_filesystem_diagnostic",{}).done(function(r){if(r.success&&r.data){$("#gps-export-status").text("Marketplace filesystem diagnostic complete.");renderMarketplace(r.data,"#gps-marketplace-last-export","Marketplace export filesystem diagnostic");}else{$("#gps-export-status").text("Marketplace filesystem diagnostic failed.");}});});$("#gps-marketplace-test-download-resolution").on("click",function(e){e.preventDefault();post("gps_marketplace_mapping_test_download_resolution",{export_id:exportId}).done(function(r){if(r.success&&r.data){$("#gps-export-status").text("Download resolution test complete.");renderMarketplace(r.data,"#gps-marketplace-last-export","Marketplace test download resolution");}else{$("#gps-export-status").text("Download resolution test failed.");}});});$("#gps-marketplace-product-discovery-diagnostic").on("click",function(e){e.preventDefault();$("#gps-export-status").text("Running product discovery diagnostic only (read-only)...");post("gps_marketplace_mapping_product_discovery_diagnostic",{}).done(function(r){if(r.success&&r.data){$("#gps-export-status").text("Product discovery diagnostic complete. No export files created.");renderMarketplace(r.data,"#gps-marketplace-last-export","Product discovery diagnostic only");}else{$("#gps-export-status").text("Product discovery diagnostic failed.");}});});function marketplaceProgress(s){s=s||{};let pct=s.total_products?Math.round((s.processed_products||0)*1000/s.total_products)/10:0;let msg="Marketplace mapping: "+(s.status||"")+" "+(s.processed_products||0)+"/"+(s.total_products||0)+" ("+pct+"%), offset="+(s.current_offset||0)+", batch_size="+(s.batch_size||100)+", last_batch_duration="+(s.last_batch_duration||0)+"s"; if(s.last_batch_error) msg += ", error="+s.last_batch_error; $("#gps-export-status").text(msg); renderMarketplace(s,"#gps-marketplace-downloads",s.status==="completed"?"Marketplace mapping export downloads":"Marketplace mapping export progress");}
function marketplaceContinue(){post("gps_marketplace_mapping_continue",{batch_size:100}).done(function(r){let s=r.data||{}; marketplaceProgress(s); if(r.success&&s.status==="running") setTimeout(marketplaceContinue,250); else $("#gps-export-marketplace-mapping").prop("disabled",false);}).fail(function(xhr){let s=(xhr.responseJSON&&xhr.responseJSON.data)||{}; marketplaceProgress(s);});}
$("#gps-export-marketplace-mapping").off("click").on("click",function(e){e.preventDefault();$(this).prop("disabled",true);$("#gps-export-status").text("Starting staged marketplace mapping export...");post("gps_marketplace_mapping_start",{batch_size:100}).done(function(r){let s=r.data||{}; marketplaceProgress(s); if(s.status==="running") setTimeout(marketplaceContinue,100); else $("#gps-export-marketplace-mapping").prop("disabled",false);}).fail(function(xhr){let s=(xhr.responseJSON&&xhr.responseJSON.data)||{}; marketplaceProgress(s);$("#gps-export-marketplace-mapping").prop("disabled",false);});});
$("#gps-reset-marketplace-mapping").on("click",function(e){e.preventDefault();post("gps_marketplace_mapping_reset",{}).done(function(r){$("#gps-export-status").text((r.data&&r.data.message)||"Marketplace mapping export reset.");});});
post("gps_marketplace_mapping_last_export",{}).done(function(r){if(r.success&&r.data&&r.data.export_id)renderMarketplace(r.data,"#gps-marketplace-last-export","Ostatni eksport marketplace mapping");});});</script>';
        echo '</div>';
    }

    private function renderMarketplaceLastExportFallback(): string
    {
        $data = (new MarketplaceMappingExport())->lastExport();
        $debug = (array) ($data['debug'] ?? []);
        $html = '<div id="gps-marketplace-last-export-fallback" style="margin:1em 0;"><h3>Ostatni eksport marketplace mapping</h3><!-- Last marketplace mapping export -->';
        if (empty($debug['diagnostics_version'])) {
            $html .= '<div class="notice notice-error inline"><p>Marketplace mapping diagnostics version missing — stale code or wrong handler.</p></div>';
        }
        $html .= '<p><code>marketplace_mapping_export_code_version=' . esc_html((string) ($debug['marketplace_mapping_export_code_version'] ?? '')) . '; service_class=' . esc_html((string) ($debug['service_class'] ?? '')) . '; service_file_mtime=' . esc_html((string) ($debug['service_file_mtime'] ?? '')) . '</code></p>';
        $html .= '<p><code>last_export_id=' . esc_html((string) ($debug['last_export_id'] ?? ($data['export_id'] ?? ''))) . '; export_dir=' . esc_html((string) ($debug['export_dir'] ?? '')) . '; files_found_count=' . esc_html((string) ($debug['files_found_count'] ?? '0')) . '</code></p>';
        $html .= '<h4>Direct SQL Woo product counts</h4><table class="widefat striped"><thead><tr><th>post_type</th><th>post_status</th><th>count</th></tr></thead><tbody>';
        foreach ((array) ($debug['direct_sql_product_count_by_post_type_status'] ?? ($debug['product_counts_by_status'] ?? [])) as $postType => $statuses) {
            foreach ((array) $statuses as $status => $count) {
                $html .= '<tr><td>' . esc_html((string) $postType) . '</td><td>' . esc_html((string) $status) . '</td><td>' . esc_html((string) $count) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        $html .= '<details open><summary>Fallback filesystem debug</summary><pre>' . esc_html(wp_json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        $files = (array) ($data['files'] ?? []);
        if ((int) ($debug['files_found_count'] ?? 0) < 1) {
            $html .= '<p><em>Brak wygenerowanych plików marketplace mapping.</em></p>';
        } else {
            $html .= '<table class="widefat striped"><thead><tr><th>file_key</th><th>filename</th><th>absolute_path</th><th>exists</th><th>readable</th><th>file_size</th><th>download_handler_resolves_path</th><th>last_download_error</th><th>download_url</th></tr></thead><tbody>';
            foreach ($files as $key => $file) {
                $downloadUrl = (string) ($file['download_url'] ?? '');
                $safeDownloadUrl = (string) ($file['safe_download_url'] ?? '');
                $exists = !empty($file['exists']);
                $html .= '<tr><td>' . esc_html((string) $key) . '</td><td>' . esc_html((string) ($file['filename'] ?? '')) . '</td><td><code>' . esc_html((string) ($file['absolute_path'] ?? '')) . '</code></td><td>' . esc_html($exists ? 'yes' : 'no') . '</td><td>' . esc_html(!empty($file['readable']) ? 'yes' : 'no') . '</td><td>' . ($exists ? esc_html((string) ($file['file_size'] ?? 0)) : '') . '</td><td>' . esc_html(!empty($file['download_handler_resolves_path']) ? 'yes' : 'no') . '</td><td>' . esc_html((string) ($file['last_download_error'] ?? '')) . '</td><td>';
                $html .= $downloadUrl !== '' ? '<a class="button" href="' . esc_url($downloadUrl) . '">Download</a> ' : '<span class="notice notice-warning inline">Missing URL</span> ';
                $html .= $safeDownloadUrl !== '' ? '<a class="button" href="' . esc_url($safeDownloadUrl) . '">Safe download</a>' : '';
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '</div>';
    }

    public function ajaxStart(): void { $this->guardAjax(); wp_send_json_success($this->exporter->start()); }
    public function ajaxBatch(): void { $this->guardAjax(); $id = isset($_POST['export_id']) ? sanitize_key(wp_unslash($_POST['export_id'])) : ''; $batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 200; wp_send_json_success($this->exporter->runBatch($id, $batch)); }
    public function ajaxCategoryTree(): void { $this->guardAjax(); wp_send_json_success($this->exporter->exportCategoryTree()); }
    public function ajaxMarketplaceMappingStart(): void { $this->guardAjax(); $batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 100; $state = $this->withAjaxHandlerMarker((new MarketplaceMappingExport())->start($batch)); if (($state['status'] ?? '') === 'error') wp_send_json_error($state, 500); wp_send_json_success($state); }
    public function ajaxMarketplaceMappingContinue(): void { $this->guardAjax(); $batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 100; $state = $this->withAjaxHandlerMarker((new MarketplaceMappingExport())->continue($batch)); if (($state['status'] ?? '') === 'error') wp_send_json_error($state, 500); wp_send_json_success($state); }
    public function ajaxMarketplaceMappingFinalize(): void { $this->guardAjax(); $state = $this->withAjaxHandlerMarker((new MarketplaceMappingExport())->continue(100)); if (($state['status'] ?? '') === 'error') wp_send_json_error($state, 500); wp_send_json_success($state); }
    public function ajaxMarketplaceMappingReset(): void { $this->guardAjax(); wp_send_json_success((new MarketplaceMappingExport())->reset()); }
    public function ajaxMarketplaceMappingLastExport(): void { $this->guardAjax(); wp_send_json_success($this->withAjaxHandlerMarker((new MarketplaceMappingExport())->lastExport())); }
    public function ajaxMarketplaceMappingFilesystemDiagnostic(): void { $this->guardAjax(); wp_send_json_success($this->withAjaxHandlerMarker((new MarketplaceMappingExport())->filesystemDiagnostic())); }
    public function ajaxMarketplaceMappingProductDiscoveryDiagnostic(): void { $this->guardAjax(); wp_send_json_success($this->withAjaxHandlerMarker((new MarketplaceMappingExport())->productDiscoveryDiagnostic())); }
    public function ajaxMarketplaceMappingTestDownloadResolution(): void
    {
        $this->guardAjax();
        $id = isset($_POST['export_id']) ? sanitize_key(wp_unslash($_POST['export_id'])) : '';
        if ($id === '') {
            $last = (new MarketplaceMappingExport())->lastExport();
            $id = (string) ($last['export_id'] ?? '');
        }
        wp_send_json_success($this->withAjaxHandlerMarker(['export_id'=>$id, 'status'=>'download_resolution_test', 'files'=>(new MarketplaceMappingExport())->downloadResolutionForAll($id), 'summary'=>[]]));
    }

    private function withAjaxHandlerMarker(array $state): array
    {
        $state['debug'] = array_merge((array) ($state['debug'] ?? []), [
            'ajax_handler_file' => __FILE__,
            'ajax_handler_file_mtime' => is_readable(__FILE__) ? gmdate('Y-m-d H:i:s', (int) filemtime(__FILE__)) . ' UTC' : '',
        ]);
        return $state;
    }

    public function download(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('forbidden');
        check_admin_referer(WooLaravelProductExport::NONCE);
        $id = isset($_GET['export_id']) ? sanitize_key(wp_unslash($_GET['export_id'])) : '';
        $file = isset($_GET['file_key']) ? sanitize_key(wp_unslash($_GET['file_key'])) : (isset($_GET['file']) ? sanitize_key(wp_unslash($_GET['file'])) : '');
        $filename = isset($_GET['filename']) ? sanitize_file_name(wp_unslash($_GET['filename'])) : '';
        if (strpos($id, 'marketplace-mapping-') === 0) {
            $resolution = (new MarketplaceMappingExport())->downloadResolution($id, $file !== '' ? $file : $filename);
            $path = (string) ($resolution['resolved_path'] ?? '');
            if ($path === '' || empty($resolution['would_pass_security'])) wp_die(esc_html('Marketplace mapping download failed: ' . (string) ($resolution['last_download_error'] ?? 'file not found')));
            $this->streamDownload($path, (string) $resolution['mime_type']);
        }
        $state = $this->exporter->state($id);
        $path = (string)($state['files'][$file] ?? '');
        if ($path === '' || !is_readable($path)) wp_die('file not found');
        header('Content-Type: text/csv; charset=utf-8');
        if ($file === 'summary' || $file === 'manifest' || $file === 'products_json' || substr($file, -5) === '_json' || substr($file, -8) === '_summary') header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        $this->streamDownload($path, (substr($path, -5) === '.json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8'));
    }

    private function streamDownload(string $path, string $mimeType): void
    {
        if (!is_readable($path)) wp_die(esc_html('Download failed: file is not readable.'));
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) break;
        }
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        @ini_set('zlib.output_compression', 'Off');
        @set_time_limit(0);
        nocache_headers();
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
        header('Content-Transfer-Encoding: binary');
        $size = @filesize($path);
        if (is_int($size) && $size >= 0) header('Content-Length: ' . (string) $size);
        $handle = @fopen($path, 'rb');
        if (!$handle) wp_die(esc_html('Download failed: unable to open file stream.'));
        while (!feof($handle)) {
            echo fread($handle, 1048576);
            flush();
        }
        fclose($handle);
        exit;
    }

    private function guardAjax(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['error'=>'forbidden'], 403);
        check_ajax_referer(WooLaravelProductExport::NONCE);
    }
}
