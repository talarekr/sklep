<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

final class MarketplaceMappingExport
{
    public const EXPORT_VERSION = '1.0.0';
    private const MATCH_TERMS = ['allegro','ovoko','rrr','ebay','listing','offer','auction','item','inventory','external','marketplace','source','gmail','wei'];
    private const SENSITIVE = '/(token|secret|password|pass|auth|consumer|client_secret|access_key|refresh)/i';

    private array $summary = [];
    private array $files = [];
    private array $rows = [];
    private array $detectedMetaKeys = [];
    private array $detectedTables = [];
    private array $detectedOptions = [];
    private array $warnings = [];

    public function export(): array
    {
        $id = 'marketplace-mapping-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $dir = $this->exportDir($id);
        wp_mkdir_p($dir);
        $this->files = [
            'products_csv' => $dir . '/woo_marketplace_mapping_products.csv',
            'products_json' => $dir . '/woo_marketplace_mapping_products.json',
            'ebay_listings' => $dir . '/woo_ebay_listings.csv',
            'ovoko_mapping' => $dir . '/woo_ovoko_mapping.csv',
            'allegro_legacy_mapping' => $dir . '/woo_allegro_legacy_mapping.csv',
            'ebay_fitment' => $dir . '/woo_ebay_fitment.csv',
            'summary' => $dir . '/woo_marketplace_mapping_summary.json',
            'manifest' => $dir . '/export_manifest.json',
        ];
        $this->rows = ['products'=>[], 'ebay'=>[], 'ovoko'=>[], 'allegro'=>[], 'fitment'=>[]];
        $this->summary = $this->emptySummary();
        $this->detectSources();

        foreach ($this->productIds() as $pid) {
            $this->exportProduct((int) $pid);
        }

        $this->writeCsv($this->files['products_csv'], $this->productColumns(), $this->rows['products']);
        file_put_contents($this->files['products_json'], wp_json_encode($this->rows['products'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->writeCsv($this->files['ebay_listings'], $this->ebayColumns(), $this->rows['ebay']);
        $this->writeCsv($this->files['ovoko_mapping'], $this->ovokoColumns(), $this->rows['ovoko']);
        $this->writeCsv($this->files['allegro_legacy_mapping'], $this->allegroColumns(), $this->rows['allegro']);
        $this->writeCsv($this->files['ebay_fitment'], $this->fitmentColumns(), $this->rows['fitment']);

        $this->finalizeSummary();
        file_put_contents($this->files['summary'], wp_json_encode($this->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $manifest = $this->manifest();
        file_put_contents($this->files['manifest'], wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $state = ['export_id'=>$id, 'status'=>'completed', 'files'=>$this->files, 'summary'=>$this->summary, 'manifest'=>$manifest, 'completed_at'=>gmdate('c')];
        $missing = $this->missingFiles($state);
        if ($missing) {
            $state['status'] = 'error';
            $state['error'] = 'Marketplace mapping export did not generate all expected files.';
            $state['missing_files'] = $missing;
            $this->summary['warnings'][] = $state['error'] . ' Missing: ' . implode(', ', $missing);
            $state['summary'] = $this->summary;
        }
        update_option(WooLaravelProductExport::OPTION_PREFIX . $id, $state, false);
        update_option(WooLaravelProductExport::OPTION_PREFIX . 'last_marketplace_mapping', $id, false);
        return $this->publicState($state);
    }

    public function columnsPreview(): array { return ['products'=>$this->productColumns(), 'ebay'=>$this->ebayColumns(), 'ovoko'=>$this->ovokoColumns(), 'allegro'=>$this->allegroColumns(), 'fitment'=>$this->fitmentColumns()]; }

    public function lastExport(): array
    {
        $id = (string) get_option(WooLaravelProductExport::OPTION_PREFIX . 'last_marketplace_mapping', '');
        $state = $id !== '' ? get_option(WooLaravelProductExport::OPTION_PREFIX . sanitize_key($id), []) : [];
        if (is_array($state) && $state) {
            $publicState = $this->publicState($state);
            if ((int) ($publicState['debug']['files_found_count'] ?? 0) > 0) return $publicState;
        }

        $dirs = glob($this->exportDir() . '/marketplace-mapping-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach ($dirs as $dir) {
            $id = basename((string) $dir);
            $files = $this->expectedFiles((string) $dir);
            if (!$this->existingFileCount($files)) continue;
            $summaryPath = $files['summary'];
            $summary = is_readable($summaryPath) ? json_decode((string) file_get_contents($summaryPath), true) : [];
            return $this->publicState(['export_id'=>$id, 'status'=>'completed', 'files'=>$files, 'summary'=>is_array($summary) ? $summary : [], 'completed_at'=>gmdate('c', (int) filemtime((string) $dir))]);
        }
        return ['export_id'=>'', 'status'=>'missing', 'files'=>[], 'summary'=>[], 'warnings'=>['No previous marketplace mapping export files were found.']];
    }

    public function filePathForDownload(string $exportId, string $fileIdentifier): string
    {
        $exportId = sanitize_key($exportId);
        if ($exportId === '' || strpos($exportId, 'marketplace-mapping-') !== 0) return '';
        $files = $this->expectedFiles($this->exportDir($exportId));
        $state = get_option(WooLaravelProductExport::OPTION_PREFIX . $exportId, []);
        if (is_array($state) && !empty($state['files']) && is_array($state['files'])) {
            $files = array_merge($files, (array) $state['files']);
        }
        $identifier = sanitize_file_name($fileIdentifier);
        $path = isset($files[$fileIdentifier]) ? (string) $files[$fileIdentifier] : '';
        if ($path === '') {
            foreach ($files as $candidate) {
                if (basename((string) $candidate) === $identifier) {
                    $path = (string) $candidate;
                    break;
                }
            }
        }
        if ($path === '' || !in_array(basename($path), array_map('basename', $this->expectedFiles($this->exportDir($exportId))), true)) return '';
        $real = realpath($path);
        $base = realpath($this->exportDir($exportId));
        if (!$real || !$base || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_readable($real)) return '';
        return $real;
    }

    private function exportProduct(int $pid): void
    {
        $post = get_post($pid); if (!$post) return;
        $meta = $this->flattenMeta(get_post_meta($pid));
        $matched = $this->matchingMeta($meta);
        $relevant = $this->relevantMeta($meta);
        foreach (array_keys($matched) as $k) $this->detectedMetaKeys[$k] = true;
        $sku = (string)($meta['_sku'] ?? '');
        $terms = $this->terms($pid, 'product_cat');
        $images = $this->images($pid, $meta);
        $type = function_exists('wc_get_product') && ($p = wc_get_product($pid)) ? $p->get_type() : 'product';
        $ids = $this->ids($meta, $sku);
        $markets = $this->marketplaces($ids, $matched);
        $product = [
            'woo_product_id'=>$pid,'sku'=>$sku,'product_name'=>$post->post_title,'product_status'=>$post->post_status,'product_type'=>$type,
            'regular_price'=>(string)($meta['_regular_price'] ?? ''),'sale_price'=>(string)($meta['_sale_price'] ?? ''),'stock_quantity'=>(string)($meta['_stock'] ?? ''),'stock_status'=>(string)($meta['_stock_status'] ?? ''),'permalink'=>(string)get_permalink($pid),
            'category_ids'=>implode('|', wp_list_pluck($terms, 'term_id')),'category_names'=>implode('|', wp_list_pluck($terms, 'name')),
            'image_ids'=>implode('|', wp_list_pluck($images, 'id')),'image_urls'=>implode('|', wp_list_pluck($images, 'url')),
            'created_at'=>$post->post_date_gmt,'updated_at'=>$post->post_modified_gmt,
        ] + $ids;
        $product['has_any_marketplace_identifier'] = $markets ? '1' : '0';
        $product['detected_marketplaces'] = implode('|', $markets);
        $product['all_detected_marketplace_meta_keys'] = implode('|', array_keys($matched));
        $product['raw_marketplace_meta_json'] = $this->json($matched);
        $product['raw_relevant_meta_json'] = $this->json($relevant);
        $this->rows['products'][] = $product;
        $this->updateProductCounts($product);
        $this->addEbayRows($pid, $sku, $ids, $matched);
        $this->addOvokoRow($pid, $sku, $ids, $meta, $matched, $relevant, $terms, $post, $images);
        $this->addAllegroRow($pid, $sku, $ids, $matched, $relevant);
        $this->addFitmentRow($pid, $sku, $meta, $matched);
    }

    private function ids(array $m, string $sku): array
    {
        return [
            'source_system'=>$this->first($m,['source_system','_source_system','marketplace_source','_marketplace_source']),'external_id'=>$this->first($m,['external_id','_external_id','source_external_id','_source_external_id']),
            'ovoko_part_id'=>$this->first($m,['ovoko_part_id','_ovoko_part_id']),'rrr_part_id'=>$this->first($m,['rrr_part_id','_rrr_part_id','ovoko_rrr_part_id']),'gmail_sku'=>strpos($sku, 'GPS-GMAIL-') === 0 ? $sku : $this->first($m,['gmail_sku','_gmail_sku']),
            'allegro_offer_id'=>$this->firstLike($m,'/(^|_)allegro.*offer.*id|allegro_offer_id/i'),'secondary_allegro_offer_id'=>$this->firstLike($m,'/secondary.*allegro.*offer|allegro.*secondary.*offer/i'),'allegro_external_id'=>$this->firstLike($m,'/allegro.*external/i'),'allegro_signature'=>$this->firstLike($m,'/allegro.*signature/i'),'allegro_url'=>$this->firstLike($m,'/allegro.*url/i'),
            'ebay_listing_id'=>$this->firstLike($m,'/ebay.*listing.*id|listing.*id/i'),'ebay_item_id'=>$this->firstLike($m,'/ebay.*item.*id/i'),'ebay_offer_id'=>$this->firstLike($m,'/ebay.*offer.*id/i'),'ebay_inventory_sku'=>$this->firstLike($m,'/ebay.*inventory.*sku|inventory.*sku/i'),'ebay_marketplace'=>$this->firstLike($m,'/ebay.*marketplace|marketplace.*ebay/i'),
            'ebay_de_listing_id'=>$this->firstLike($m,'/(ebay_de|de_ebay|wei).*listing.*id/i'),'ebay_fr_listing_id'=>$this->firstLike($m,'/(ebay_fr|fr_ebay|wei_fr).*listing.*id/i'),'ebay_de_offer_id'=>$this->firstLike($m,'/(ebay_de|de_ebay|wei).*offer.*id/i'),'ebay_fr_offer_id'=>$this->firstLike($m,'/(ebay_fr|fr_ebay|wei_fr).*offer.*id/i'),'ebay_de_inventory_sku'=>$this->first($m,['ebay_de_inventory_sku','_ebay_de_inventory_sku','wei_inventory_sku']),'ebay_fr_inventory_sku'=>$this->first($m,['ebay_fr_inventory_sku','_ebay_fr_inventory_sku','wei_fr_inventory_sku']),'ebay_url_de'=>$this->firstLike($m,'/(ebay_de|de_ebay|wei).*url/i'),'ebay_url_fr'=>$this->firstLike($m,'/(ebay_fr|fr_ebay|wei_fr).*url/i'),
        ];
    }

    private function addEbayRows(int $pid, string $sku, array $ids, array $matched): void
    { foreach ([['EBAY_DE','de'], ['EBAY_FR','fr'], [$ids['ebay_marketplace'] ?: 'EBAY','']] as [$market,$s]) { $row = ['woo_product_id'=>$pid,'sku'=>$sku,'marketplace'=>$market,'ebay_inventory_sku'=>$ids[$s ? "ebay_{$s}_inventory_sku" : 'ebay_inventory_sku'] ?? '','ebay_offer_id'=>$ids[$s ? "ebay_{$s}_offer_id" : 'ebay_offer_id'] ?? '','ebay_listing_id'=>$ids[$s ? "ebay_{$s}_listing_id" : 'ebay_listing_id'] ?? '','ebay_item_id'=>$ids['ebay_item_id'],'ebay_category_id'=>$this->firstLike($matched,'/ebay.*category.*id/i'),'listing_status'=>$this->firstLike($matched,'/ebay.*status|listing.*status/i'),'price'=>$this->firstLike($matched,'/ebay.*price|listing.*price/i'),'quantity'=>$this->firstLike($matched,'/ebay.*quantity|listing.*quantity/i'),'url'=>$ids[$s ? "ebay_url_{$s}" : 'ebay_url_de'] ?: $ids['ebay_url_fr'],'mapping_source'=>'product_meta','raw_mapping_json'=>$this->json($matched)]; if (implode('', array_slice($row,3,9)) !== '') $this->rows['ebay'][] = $row; } }
    private function addOvokoRow(int $pid, string $sku, array $ids, array $m, array $matched, array $relevant, array $terms, $post, array $images): void
    { if ($ids['ovoko_part_id']==='' && $ids['rrr_part_id']==='' && $ids['gmail_sku']==='' && stripos($ids['source_system'], 'ovoko') === false) return; $this->rows['ovoko'][] = ['woo_product_id'=>$pid,'sku'=>$sku,'ovoko_part_id'=>$ids['ovoko_part_id'],'rrr_part_id'=>$ids['rrr_part_id'],'donor_car_id'=>$this->first($m,['donor_car_id','_donor_car_id','ovoko_car_id','rrr_car_id']),'source_system'=>$ids['source_system'],'current_status'=>(string)($m['_stock_status'] ?? ''),'ready_for_sale'=>$this->firstLike($m,'/ready.*sale|ready_for_sale/i'),'blocked_reasons'=>$this->firstLike($m,'/blocked.*reason|missing_price/i'),'price'=>(string)($m['_price'] ?? ''),'stock_quantity'=>(string)($m['_stock'] ?? ''),'category_ids'=>implode('|', wp_list_pluck($terms,'term_id')),'category_names'=>implode('|', wp_list_pluck($terms,'name')),'title'=>$post->post_title,'image_count'=>(string)count($images),'mapping_source'=>'product_meta','raw_ovoko_meta_json'=>$this->json(array_filter($matched, static fn($v,$k)=>preg_match('/ovoko|rrr|gmail|donor|ready|blocked|missing_price/i',(string)$k), ARRAY_FILTER_USE_BOTH)),'raw_relevant_meta_json'=>$this->json($relevant)]; }
    private function addAllegroRow(int $pid, string $sku, array $ids, array $matched, array $relevant): void
    { $has = $ids['allegro_offer_id'].$ids['secondary_allegro_offer_id'].$ids['allegro_external_id'].$ids['allegro_signature'].$ids['allegro_url']; if ($has==='') return; $this->rows['allegro'][] = ['woo_product_id'=>$pid,'sku'=>$sku,'allegro_offer_id'=>$ids['allegro_offer_id'],'secondary_allegro_offer_id'=>$ids['secondary_allegro_offer_id'],'allegro_external_id'=>$ids['allegro_external_id'],'allegro_signature'=>$ids['allegro_signature'],'allegro_auction_id'=>$this->firstLike($matched,'/allegro.*auction/i'),'allegro_url'=>$ids['allegro_url'],'source_system'=>$ids['source_system'],'external_id'=>$ids['external_id'],'mapping_source'=>'product_meta','confidence_hint'=>'matched_allegro_meta','raw_allegro_meta_json'=>$this->json(array_filter($matched, static fn($v,$k)=>stripos((string)$k,'allegro')!==false, ARRAY_FILTER_USE_BOTH)),'raw_relevant_meta_json'=>$this->json($relevant)]; }
    private function addFitmentRow(int $pid, string $sku, array $m, array $matched): void
    { $fit = array_filter($matched, static fn($v,$k)=>preg_match('/ktype|k_type|oem|part_number|manufacturer|mpn|compat|fitment/i',(string)$k), ARRAY_FILTER_USE_BOTH); if (!$fit && $this->first($m, ['part_number','_part_number','oem_number','_oem_number','manufacturer_code'])==='') return; $ktypes = $this->firstLike($m,'/ktype|k_type/i'); $this->rows['fitment'][] = ['woo_product_id'=>$pid,'sku'=>$sku,'part_number'=>$this->first($m,['part_number','_part_number','mpn','_sku']),'oem_numbers'=>$this->first($m,['oem_numbers','oem_number','_oem_number','oem']),'manufacturer_code'=>$this->first($m,['manufacturer_code','_manufacturer_code']),'kt_type_count'=>$ktypes === '' ? '0' : (string)count(array_filter(preg_split('/[|,;\s]+/', $ktypes) ?: [])),'ktypes'=>$ktypes,'ebay_marketplace'=>'global','fitment_mapping_source'=>'product_meta','fitment_status'=>$this->firstLike($m,'/fitment.*status/i'),'raw_fitment_json'=>$this->json($fit ?: $m)]; }

    private function detectSources(): void { global $wpdb; $tables=(array)$wpdb->get_col('SHOW TABLES'); foreach($tables as $t) if(preg_match('/(marketplace|ebay|wei|listing|offer|inventory|allegro|ovoko|rrr)/i',(string)$t)) $this->detectedTables[]=(string)$t; $opts=(array)$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP 'ebay|wei|allegro|ovoko|rrr|marketplace' LIMIT 200"); $this->detectedOptions=array_values(array_filter($opts, static fn($o)=>!preg_match(self::SENSITIVE,(string)$o))); }
    private function finalizeSummary(): void { $this->summary['ebay_listing_rows']=count($this->rows['ebay']); $this->summary['ovoko_mapping_rows']=count($this->rows['ovoko']); $this->summary['allegro_mapping_rows']=count($this->rows['allegro']); $this->summary['ebay_fitment_rows']=count($this->rows['fitment']); $this->summary['detected_meta_keys']=array_keys($this->detectedMetaKeys); sort($this->summary['detected_meta_keys']); $this->summary['detected_custom_tables']=$this->detectedTables; $this->summary['detected_plugin_options']=$this->detectedOptions; $this->summary['active_allegro_integration_detected']=(bool)preg_grep('/allegro/i', array_merge($this->detectedTables, $this->detectedOptions)); $this->summary['active_ebay_integration_detected']=(bool)preg_grep('/ebay|wei/i', array_merge($this->detectedTables, $this->detectedOptions)); $this->summary['active_ovoko_integration_detected']=(bool)preg_grep('/ovoko|rrr/i', array_merge($this->detectedTables, $this->detectedOptions)); $this->summary['warnings']=$this->warnings; $this->summary['files']=array_map('basename',$this->files); }
    private function updateProductCounts(array $r): void { $s=&$this->summary; $s['total_products']++; $r['sku']!==''?$s['products_with_sku']++:$s['products_missing_sku']++; if($r['has_any_marketplace_identifier']==='1')$s['products_with_any_marketplace_identifier']++; foreach(['ovoko_part_id','rrr_part_id','gmail_sku'] as $k) if($r[$k]!=='') $s['products_with_'.$k]++; if($r['allegro_offer_id'].$r['allegro_external_id'].$r['allegro_signature'].$r['allegro_url']!=='') $s['products_with_allegro_identifier']++; if($r['ebay_listing_id'].$r['ebay_item_id'].$r['ebay_offer_id'].$r['ebay_inventory_sku'].$r['ebay_de_listing_id'].$r['ebay_fr_listing_id']!=='') $s['products_with_ebay_identifier']++; if($r['ebay_de_listing_id'].$r['ebay_de_offer_id'].$r['ebay_de_inventory_sku']!=='') $s['products_with_ebay_de']++; if($r['ebay_fr_listing_id'].$r['ebay_fr_offer_id'].$r['ebay_fr_inventory_sku']!=='') $s['products_with_ebay_fr']++; foreach(['sku'=>'sku_count','ovoko_part_id'=>'ovoko_part_id_count','allegro_offer_id'=>'allegro_offer_id_count','ebay_listing_id'=>'ebay_listing_id_count','ebay_offer_id'=>'ebay_offer_id_count','ebay_inventory_sku'=>'ebay_inventory_sku_count','external_id'=>'external_id_count','source_system'=>'source_system_count'] as $c=>$k) if($r[$c]!=='') $s['mapping_confidence_inputs'][$k]++; if($r['raw_relevant_meta_json']!=='[]') $s['mapping_confidence_inputs']['legacy_payload_count']++; }
    private function emptySummary(): array { return ['generated_at'=>gmdate('c'),'total_products'=>0,'products_with_sku'=>0,'products_missing_sku'=>0,'products_with_any_marketplace_identifier'=>0,'products_with_ovoko_part_id'=>0,'products_with_rrr_part_id'=>0,'products_with_gmail_sku'=>0,'products_with_allegro_identifier'=>0,'products_with_ebay_identifier'=>0,'products_with_ebay_de'=>0,'products_with_ebay_fr'=>0,'ebay_listing_rows'=>0,'ovoko_mapping_rows'=>0,'allegro_mapping_rows'=>0,'ebay_fitment_rows'=>0,'detected_meta_keys'=>[],'detected_custom_tables'=>[],'detected_plugin_options'=>[],'active_allegro_integration_detected'=>false,'active_ebay_integration_detected'=>false,'active_ovoko_integration_detected'=>false,'mapping_confidence_inputs'=>['sku_count'=>0,'ovoko_part_id_count'=>0,'allegro_offer_id_count'=>0,'ebay_listing_id_count'=>0,'ebay_offer_id_count'=>0,'ebay_inventory_sku_count'=>0,'external_id_count'=>0,'source_system_count'=>0,'legacy_payload_count'=>0],'warnings'=>[],'files'=>[]]; }

    private function productIds(): array { global $wpdb; return (array)$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID ASC"); }
    private function flattenMeta(array $meta): array { $out=[]; foreach($meta as $k=>$vals){ if(preg_match(self::SENSITIVE,(string)$k)) continue; $v=maybe_unserialize((string)($vals[0]??'')); $out[$k]=is_scalar($v)?(string)$v:$v; } return $out; }
    private function matchingMeta(array $m): array { return array_filter($m, function($v,$k){ foreach(self::MATCH_TERMS as $t) if(stripos((string)$k,$t)!==false) return true; return false; }, ARRAY_FILTER_USE_BOTH); }
    private function relevantMeta(array $m): array { return array_filter($m, fn($v,$k)=>isset($this->matchingMeta($m)[$k]) || preg_match('/part|oem|ktype|vehicle|donor|car|ready|blocked|price|stock/i',(string)$k), ARRAY_FILTER_USE_BOTH); }
    private function first(array $m, array $keys): string { foreach($keys as $k) if(isset($m[$k]) && is_scalar($m[$k]) && trim((string)$m[$k])!=='') return trim((string)$m[$k]); return ''; }
    private function firstLike(array $m, string $regex): string { foreach($m as $k=>$v) if(preg_match($regex,(string)$k) && is_scalar($v) && trim((string)$v)!=='') return trim((string)$v); return ''; }
    private function marketplaces(array $ids, array $matched): array { $out=[]; if($ids['ovoko_part_id']||$ids['rrr_part_id']||$ids['gmail_sku'])$out[]='OVOKO'; if($ids['allegro_offer_id']||$ids['allegro_external_id']||$ids['allegro_url'])$out[]='ALLEGRO'; if($ids['ebay_de_listing_id']||$ids['ebay_de_offer_id']||$ids['ebay_de_inventory_sku'])$out[]='EBAY_DE'; if($ids['ebay_fr_listing_id']||$ids['ebay_fr_offer_id']||$ids['ebay_fr_inventory_sku'])$out[]='EBAY_FR'; if(!$out && preg_grep('/ebay/i', array_keys($matched)))$out[]='EBAY'; return array_values(array_unique($out)); }
    private function terms(int $pid, string $tax): array { $terms=get_the_terms($pid,$tax); return is_array($terms)?array_map(static fn($t)=>['term_id'=>(int)$t->term_id,'name'=>(string)$t->name],$terms):[]; }
    private function images(int $pid, array $m): array { $ids=[]; if(!empty($m['_thumbnail_id']))$ids[]=(int)$m['_thumbnail_id']; foreach(array_filter(array_map('intval', explode(',', (string)($m['_product_image_gallery']??'')))) as $id)$ids[]=$id; $out=[]; foreach(array_unique($ids) as $id)$out[]=['id'=>$id,'url'=>(string)(wp_get_attachment_url($id) ?: '')]; return $out; }
    private function json($v): string { return wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'; }
    private function writeCsv(string $file, array $cols, array $rows): void { $fp=fopen($file,'wb'); fputcsv($fp,$cols); foreach($rows as $r) fputcsv($fp, array_map(static fn($c)=>(string)($r[$c] ?? ''), $cols)); fclose($fp); }
    private function manifest(): array { $files=[]; foreach($this->files as $path) $files[]=['filename'=>basename($path),'row_count'=>$this->rowCountForFile(basename($path)),'sha256'=>hash_file('sha256',$path),'description'=>$this->description(basename($path))]; return ['generated_at'=>gmdate('c'),'export_version'=>self::EXPORT_VERSION,'site_url'=>function_exists('site_url') ? site_url() : '','plugin_version'=>defined('GPS_EBAY_FITMENT_SYNC_VERSION') ? GPS_EBAY_FITMENT_SYNC_VERSION : '','files'=>$files,'safety'=>['read_only'=>true,'external_api_calls'=>false,'woo_writes'=>false,'marketplace_writes'=>false]]; }
    private function rowCountForFile(string $f): int { return match($f){'woo_marketplace_mapping_products.csv','woo_marketplace_mapping_products.json'=>count($this->rows['products']),'woo_ebay_listings.csv'=>count($this->rows['ebay']),'woo_ovoko_mapping.csv'=>count($this->rows['ovoko']),'woo_allegro_legacy_mapping.csv'=>count($this->rows['allegro']),'woo_ebay_fitment.csv'=>count($this->rows['fitment']), default=>1}; }
    private function description(string $f): string { return match($f){'woo_marketplace_mapping_products.csv'=>'All Woo products with marketplace identifiers and raw matching meta.','woo_marketplace_mapping_products.json'=>'JSON mirror of product marketplace mapping rows.','woo_ebay_listings.csv'=>'Detected eBay listing/offer/inventory rows.','woo_ovoko_mapping.csv'=>'Detected Ovoko/RRR/Gmail product mappings.','woo_allegro_legacy_mapping.csv'=>'Detected Allegro legacy or active identifiers.','woo_ebay_fitment.csv'=>'Detected part/OEM/KType fitment inputs for eBay.', default=>'Export metadata and summary.'}; }
    private function exportDir(string $id=''): string { $u=wp_upload_dir(); return trailingslashit($u['basedir']).'gps-laravel-product-export'.($id?'/'.sanitize_key($id):''); }
    private function publicState(array $s): array
    {
        if (!$s) return [];
        $s['files'] = $this->publicFiles((array) ($s['files'] ?? []), (string) ($s['export_id'] ?? ''), (array) ($s['manifest']['files'] ?? []));
        $missing = array_values(array_map(static fn($f)=>(string)($f['filename'] ?? ''), array_filter($s['files'], static fn($f)=>empty($f['exists']))));
        if ($missing) {
            $s['warnings'] = array_values(array_merge((array) ($s['warnings'] ?? []), ['Missing generated files: ' . implode(', ', $missing)]));
        }
        $exportId = (string) ($s['export_id'] ?? '');
        $s['debug'] = [
            'last_export_id' => $exportId,
            'export_dir' => $exportId !== '' ? $this->exportDir($exportId) : '',
            'files_found_count' => count(array_filter($s['files'], static fn($f) => !empty($f['exists']))),
        ];
        return $s;
    }
    private function publicFiles(array $files, string $exportId, array $manifestFileList = []): array
    {
        $out = [];
        $manifestFiles = [];
        foreach ($manifestFileList as $meta) {
            if (isset($meta['filename'])) $manifestFiles[(string) $meta['filename']] = $meta;
        }
        foreach ($files as $key => $path) {
            $path = (string) $path;
            $filename = basename($path);
            $meta = $manifestFiles[$filename] ?? [];
            $exists = $path !== '' && is_readable($path);
            $out[(string) $key] = [
                'filename' => $filename,
                'download_url' => $exists ? $this->downloadUrl($exportId, (string) $key) : '',
                'row_count' => $meta['row_count'] ?? $this->rowCountForFile($filename),
                'file_size' => $exists ? (int) filesize($path) : null,
                'modified_time' => $exists ? gmdate('Y-m-d H:i:s', (int) filemtime($path)) . ' UTC' : '',
                'exists' => $exists,
                'download_error' => $exists && $this->downloadUrl($exportId, (string) $key) === '' ? 'File exists on disk but download URL could not be generated.' : '',
            ];
        }
        return $out;
    }
    private function downloadUrl(string $exportId, string $fileKey): string
    {
        return add_query_arg([
            'action' => 'gps_laravel_product_export_download',
            'export_id' => $exportId,
            'file_key' => $fileKey,
            '_wpnonce' => wp_create_nonce(WooLaravelProductExport::NONCE),
        ], admin_url('admin-post.php'));
    }
    private function expectedFiles(string $dir): array
    {
        return ['products_csv' => $dir . '/woo_marketplace_mapping_products.csv','products_json' => $dir . '/woo_marketplace_mapping_products.json','ebay_listings' => $dir . '/woo_ebay_listings.csv','ovoko_mapping' => $dir . '/woo_ovoko_mapping.csv','allegro_legacy_mapping' => $dir . '/woo_allegro_legacy_mapping.csv','ebay_fitment' => $dir . '/woo_ebay_fitment.csv','summary' => $dir . '/woo_marketplace_mapping_summary.json','manifest' => $dir . '/export_manifest.json'];
    }
    private function missingFiles(array $state): array
    {
        return array_values(array_map('basename', array_filter((array) ($state['files'] ?? []), static fn($path)=>!is_string($path) || !is_readable($path))));
    }
    private function existingFileCount(array $files): int { return count(array_filter($files, static fn($path)=>is_string($path) && is_readable($path))); }
    private function productColumns(): array { return ['woo_product_id','sku','product_name','product_status','product_type','regular_price','sale_price','stock_quantity','stock_status','permalink','category_ids','category_names','image_ids','image_urls','created_at','updated_at','source_system','external_id','ovoko_part_id','rrr_part_id','gmail_sku','allegro_offer_id','secondary_allegro_offer_id','allegro_external_id','allegro_signature','allegro_url','ebay_listing_id','ebay_item_id','ebay_offer_id','ebay_inventory_sku','ebay_marketplace','ebay_de_listing_id','ebay_fr_listing_id','ebay_de_offer_id','ebay_fr_offer_id','ebay_de_inventory_sku','ebay_fr_inventory_sku','ebay_url_de','ebay_url_fr','has_any_marketplace_identifier','detected_marketplaces','all_detected_marketplace_meta_keys','raw_marketplace_meta_json','raw_relevant_meta_json']; }
    private function ebayColumns(): array { return ['woo_product_id','sku','marketplace','ebay_inventory_sku','ebay_offer_id','ebay_listing_id','ebay_item_id','ebay_category_id','listing_status','price','quantity','url','mapping_source','raw_mapping_json']; }
    private function ovokoColumns(): array { return ['woo_product_id','sku','ovoko_part_id','rrr_part_id','donor_car_id','source_system','current_status','ready_for_sale','blocked_reasons','price','stock_quantity','category_ids','category_names','title','image_count','mapping_source','raw_ovoko_meta_json','raw_relevant_meta_json']; }
    private function allegroColumns(): array { return ['woo_product_id','sku','allegro_offer_id','secondary_allegro_offer_id','allegro_external_id','allegro_signature','allegro_auction_id','allegro_url','source_system','external_id','mapping_source','confidence_hint','raw_allegro_meta_json','raw_relevant_meta_json']; }
    private function fitmentColumns(): array { return ['woo_product_id','sku','part_number','oem_numbers','manufacturer_code','kt_type_count','ktypes','ebay_marketplace','fitment_mapping_source','fitment_status','raw_fitment_json']; }
}
