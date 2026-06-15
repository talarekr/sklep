<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

final class WooLaravelProductExport
{
    public const OPTION_PREFIX = 'gps_laravel_product_export_';
    public const NONCE = 'gps_laravel_product_export';

    private const BATCH_DEFAULT = 200;
    private const MAX_AGE_SECONDS = 604800;

    private array $productColumns = [
        'woo_product_id','post_id','product_type','sku','name','slug','permalink','status','published','catalog_visibility','short_description','description','regular_price','sale_price','price','currency','stock_status','quantity','manage_stock','weight_kg','length_cm','width_cm','height_cm','categories','category_ids','tags','attributes_json','images','image_ids','primary_image','image_alt_texts','created_at','updated_at','part_number','part_number_normalized','oem_number','manufacturer_code','mpn','ean','condition','brand','manufacturer','storage_location_name','storage_location_id','ovoko_car_id','donor_car_id','car_id','vehicle_id','ovoko_vehicle_id','car_make','car_model','car_generation','car_platform','car_year','car_engine','car_engine_code','car_fuel','car_gearbox','car_body_type','car_mileage','car_vin','car_notes','ebay_fr_item_id','ebay_fr_offer_id','ebay_fr_inventory_sku','ebay_de_item_id','ebay_de_offer_id','ebay_de_inventory_sku','allegro_offer_id','ovoko_part_id','legacy_payload_json'
    ];

    private array $metaKeys = [
        'part_number' => ['part_number','_part_number','part_no','partnum','numer_czesci','nr_czesci','_sku'],
        'oem_number' => ['oem_number','_oem_number','oem','_oem','oe_number','oe','original_number'],
        'manufacturer_code' => ['manufacturer_code','_manufacturer_code','kod_producenta'],
        'mpn' => ['mpn','_mpn','manufacturer_part_number'],
        'ean' => ['ean','_ean','gtin','_gtin','barcode'],
        'condition' => ['condition','_condition','stan'],
        'brand' => ['brand','_brand','pa_brand','marka'],
        'manufacturer' => ['manufacturer','_manufacturer','producent'],
        'storage_location_name' => ['storage_location','_storage_location','storage_location_name','lokalizacja','magazyn'],
        'storage_location_id' => ['storage_location_id','_storage_location_id','warehouse_location_id'],
        'ovoko_car_id' => ['ovoko_car_id','_ovoko_car_id','rrr_car_id','_rrr_car_id','ovoko_donor_car_id','_ovoko_donor_car_id'],
        'donor_car_id' => ['donor_car_id','_donor_car_id','car_donor_id'],
        'car_id' => ['car_id','_car_id'], 'vehicle_id' => ['vehicle_id','_vehicle_id'], 'ovoko_vehicle_id' => ['ovoko_vehicle_id','_ovoko_vehicle_id'],
        'car_make' => ['car_make','vehicle_make','make','marka_pojazdu'], 'car_model' => ['car_model','vehicle_model','model_pojazdu'], 'car_generation' => ['car_generation','generation'], 'car_platform' => ['car_platform','platform'], 'car_year' => ['car_year','vehicle_year','year','rok'], 'car_engine' => ['car_engine','engine'], 'car_engine_code' => ['car_engine_code','engine_code'], 'car_fuel' => ['car_fuel','fuel'], 'car_gearbox' => ['car_gearbox','gearbox','transmission'], 'car_body_type' => ['car_body_type','body_type'], 'car_mileage' => ['car_mileage','mileage','przebieg'], 'car_vin' => ['car_vin','vin'], 'car_notes' => ['car_notes','vehicle_notes'],
        'ebay_fr_item_id' => ['ebay_fr_item_id','_ebay_fr_item_id'], 'ebay_fr_offer_id' => ['ebay_fr_offer_id','_ebay_fr_offer_id'], 'ebay_fr_inventory_sku' => ['ebay_fr_inventory_sku','_ebay_fr_inventory_sku'], 'ebay_de_item_id' => ['ebay_de_item_id','_ebay_de_item_id','ebay_item_id'], 'ebay_de_offer_id' => ['ebay_de_offer_id','_ebay_de_offer_id'], 'ebay_de_inventory_sku' => ['ebay_de_inventory_sku','_ebay_de_inventory_sku'], 'allegro_offer_id' => ['allegro_offer_id','_allegro_offer_id'], 'ovoko_part_id' => ['ovoko_part_id','_ovoko_part_id','rrr_part_id','_rrr_part_id'],
    ];

    public function start(): array
    {
        $this->cleanup();
        $id = 'woo-laravel-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $dir = $this->exportDir($id);
        wp_mkdir_p($dir);
        $files = ['products' => $dir . '/products.csv', 'images' => $dir . '/product_images.csv', 'categories' => $dir . '/product_categories.csv', 'attributes' => $dir . '/product_attributes.csv', 'meta' => $dir . '/product_meta.csv'];
        $this->writeHeader($files['products'], $this->productColumns);
        $this->writeHeader($files['images'], ['woo_product_id','sku','image_id','image_url','alt_text','position','is_primary']);
        $this->writeHeader($files['categories'], ['woo_product_id','category_id','category_path','category_name','slug']);
        $this->writeHeader($files['attributes'], ['woo_product_id','name','values','visible','is_taxonomy']);
        $this->writeHeader($files['meta'], ['woo_product_id','meta_key','meta_value']);
        $state = ['export_id'=>$id,'last_product_id'=>0,'rows_processed'=>0,'files'=>$files,'started_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'completed_at'=>'','status'=>'running','summary'=>$this->emptySummary(),'donor_sources'=>$this->detectLocalSources()];
        update_option(self::OPTION_PREFIX . $id, $state, false);
        return $this->publicState($state);
    }

    public function runBatch(string $id, int $limit = self::BATCH_DEFAULT): array
    {
        global $wpdb;
        $state = $this->state($id);
        if (!$state || $state['status'] === 'completed') return $this->publicState($state ?: []);
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND ID > %d ORDER BY ID ASC LIMIT %d", (int)$state['last_product_id'], $limit));
        foreach ($rows as $pid) {
            $this->exportProduct((int)$pid, $state);
            $state['last_product_id'] = (int)$pid;
            $state['rows_processed']++;
        }
        if (count($rows) < $limit) {
            $state['status'] = 'completed';
            $state['completed_at'] = gmdate('c');
            $state = $this->writeSummary($state);
        }
        $state['updated_at'] = gmdate('c');
        update_option(self::OPTION_PREFIX . $id, $state, false);
        return $this->publicState($state);
    }

    public function state(string $id): array { $s = get_option(self::OPTION_PREFIX . sanitize_key($id), []); return is_array($s) ? $s : []; }
    public function productColumns(): array { return $this->productColumns; }

    private function exportProduct(int $pid, array &$state): void
    {
        $post = get_post($pid); if (!$post) return;
        $meta = get_post_meta($pid); $flat = $this->flattenMeta($meta); $mapped = $this->mappedMeta($flat);
        $terms = $this->terms($pid, 'product_cat'); $tags = $this->terms($pid, 'product_tag'); $images = $this->images($pid, $flat);
        $attrs = $this->attributes($pid, $flat);
        $type = function_exists('wc_get_product') && ($p = wc_get_product($pid)) ? $p->get_type() : (string)($flat['_product_type'] ?? '');
        $row = array_fill_keys($this->productColumns, '');
        foreach ($mapped as $k=>$v) if (array_key_exists($k,$row)) $row[$k]=$v;
        $row = array_merge($row, ['woo_product_id'=>$pid,'post_id'=>$pid,'product_type'=>$type,'sku'=>(string)($flat['_sku'] ?? ''),'name'=>$post->post_title,'slug'=>$post->post_name,'permalink'=>get_permalink($pid),'status'=>$post->post_status,'published'=>$post->post_date_gmt,'catalog_visibility'=>(string)($flat['_visibility'] ?? ''),'short_description'=>$post->post_excerpt,'description'=>$post->post_content,'regular_price'=>(string)($flat['_regular_price'] ?? ''),'sale_price'=>(string)($flat['_sale_price'] ?? ''),'price'=>(string)($flat['_price'] ?? ''),'currency'=>function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '','stock_status'=>(string)($flat['_stock_status'] ?? ''),'quantity'=>(string)($flat['_stock'] ?? ''),'manage_stock'=>(string)($flat['_manage_stock'] ?? ''),'weight_kg'=>(string)($flat['_weight'] ?? ''),'length_cm'=>(string)($flat['_length'] ?? ''),'width_cm'=>(string)($flat['_width'] ?? ''),'height_cm'=>(string)($flat['_height'] ?? ''),'categories'=>implode('|', wp_list_pluck($terms,'path')),'category_ids'=>implode('|', wp_list_pluck($terms,'term_id')),'tags'=>implode('|', wp_list_pluck($tags,'name')),'attributes_json'=>wp_json_encode($attrs, JSON_UNESCAPED_UNICODE),'images'=>implode('|', wp_list_pluck($images,'url')),'image_ids'=>implode('|', wp_list_pluck($images,'id')),'primary_image'=>(string)($images[0]['url'] ?? ''),'image_alt_texts'=>implode('|', wp_list_pluck($images,'alt')),'created_at'=>$post->post_date_gmt,'updated_at'=>$post->post_modified_gmt,'part_number_normalized'=>$this->normalize((string)($mapped['part_number'] ?? '')),'legacy_payload_json'=>wp_json_encode($this->legacyPayload($flat), JSON_UNESCAPED_UNICODE)]);
        $this->put($state['files']['products'], $this->ordered($row, $this->productColumns));
        foreach ($images as $i=>$img) $this->put($state['files']['images'], [$pid,$row['sku'],$img['id'],$img['url'],$img['alt'],$i,$i===0?'1':'0']);
        foreach ($terms as $t) $this->put($state['files']['categories'], [$pid,$t['term_id'],$t['path'],$t['name'],$t['slug']]);
        foreach ($attrs as $a) $this->put($state['files']['attributes'], [$pid,$a['name'],implode('|',$a['values']),$a['visible']?'1':'0',$a['is_taxonomy']?'1':'0']);
        foreach ($this->legacyPayload($flat) as $k=>$v) $this->put($state['files']['meta'], [$pid,$k,is_scalar($v)?(string)$v:wp_json_encode($v)]);
        $this->updateSummary($state['summary'], $row, $images);
    }

    private function mappedMeta(array $flat): array { $out=[]; foreach($this->metaKeys as $field=>$keys){ foreach($keys as $k){ if(isset($flat[$k]) && $flat[$k] !== '') { $out[$field]=(string)$flat[$k]; break; } } } return $out; }
    private function flattenMeta(array $meta): array { $out=[]; foreach($meta as $k=>$vals){ $v=maybe_unserialize((string)($vals[0]??'')); if (is_scalar($v)) $out[$k]=(string)$v; } return $out; }
    private function images(int $pid, array $m): array { $ids=[]; if(!empty($m['_thumbnail_id'])) $ids[]=(int)$m['_thumbnail_id']; foreach(array_filter(array_map('intval', explode(',', (string)($m['_product_image_gallery']??'')))) as $id) $ids[]=$id; $out=[]; foreach(array_unique($ids) as $id){ $url=wp_get_attachment_url($id); $out[]=['id'=>$id,'url'=>$url ? (string)$url : '','alt'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),'url_resolved'=>$url !== false && $url !== '']; } return $out; }
    private function terms(int $pid, string $tax): array { $terms=get_the_terms($pid,$tax); if(!is_array($terms)) return []; return array_map(function($t) use($tax){ return ['term_id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'path'=>$this->termPath($t,$tax)]; }, $terms); }
    private function termPath($term, string $tax): string { $names=[$term->name]; $p=(int)$term->parent; while($p){ $pt=get_term($p,$tax); if(!$pt || is_wp_error($pt)) break; array_unshift($names,$pt->name); $p=(int)$pt->parent; } return implode(' > ',$names); }
    private function attributes(int $pid, array $m): array { $raw=isset($m['_product_attributes']) ? maybe_unserialize($m['_product_attributes']) : []; $out=[]; if(is_array($raw)) foreach($raw as $name=>$a){ $isTax=!empty($a['is_taxonomy']); $values=$isTax ? wp_list_pluck($this->terms($pid,$name),'name') : array_map('trim', explode('|',(string)($a['value']??''))); $out[]=['name'=>(string)($a['name']??$name),'values'=>array_values(array_filter($values)),'visible'=>!empty($a['is_visible']),'is_taxonomy'=>$isTax]; } return $out; }
    private function legacyPayload(array $flat): array { $allow=[]; foreach($this->metaKeys as $keys) foreach($keys as $k) $allow[$k]=true; $out=[]; foreach($flat as $k=>$v){ $lk=strtolower($k); if((isset($allow[$k]) || str_contains($lk,'ovoko') || str_contains($lk,'rrr') || str_contains($lk,'ebay') || str_contains($lk,'allegro') || str_contains($lk,'source')) && !preg_match('/(token|secret|password|pass|key|auth|client_secret)/i',$k)) $out[$k]=$v; } return $out; }
    private function updateSummary(array &$s, array $r, array $images): void { $s['total_woo_products_exported']++; foreach(['sku'=>'products_with_sku','categories'=>'products_with_categories','ovoko_car_id'=>'products_with_ovoko_car_id'] as $col=>$key) if($r[$col] !== '') $s[$key]++; if(count($images)>0){ $s['products_with_images']++; } else { $s['products_missing_images']++; } $s['total_image_rows'] += count($images); foreach($images as $img){ if(empty($img['url_resolved'])) $s['image_url_resolution_warnings'][]=['woo_product_id'=>$r['woo_product_id'],'image_id'=>$img['id'],'warning'=>'Attachment URL could not be resolved; image row was exported with an empty image_url.']; } if($r['part_number']!==''||$r['oem_number']!=='') $s['products_with_oem_or_part_number']++; if($r['ovoko_car_id']==='') $s['products_missing_ovoko_car_id']++; $hasVehicle=false; foreach(['car_make','car_model','car_year','car_engine','car_vin','donor_car_id','vehicle_id'] as $c) if($r[$c] !== '') $hasVehicle=true; $hasVehicle ? $s['products_with_donor_vehicle_details']++ : $s['products_missing_donor_vehicle_details']++; }
    private function emptySummary(): array { return ['total_woo_products_exported'=>0,'products_with_sku'=>0,'products_with_oem_or_part_number'=>0,'products_with_images'=>0,'products_missing_images'=>0,'total_image_rows'=>0,'image_url_resolution_warnings'=>[],'products_with_categories'=>0,'products_with_ovoko_car_id'=>0,'products_missing_ovoko_car_id'=>0,'products_with_donor_vehicle_details'=>0,'products_missing_donor_vehicle_details'=>0]; }
    private function detectLocalSources(): array { global $wpdb; $tables=$wpdb->get_col('SHOW TABLES'); return ['note'=>'Eksport czyta wyłącznie lokalną bazę: postmeta oraz wykryte lokalne tabele zawierające ovoko/rrr/car/vehicle w nazwie.','tables'=>array_values(array_filter((array)$tables, static fn($t)=>preg_match('/(ovoko|rrr|car|vehicle|donor)/i',(string)$t)))]; }
    private function writeSummary(array $state): array { $path=dirname($state['files']['products']).'/export_summary.json'; file_put_contents($path, wp_json_encode(['export_id'=>$state['export_id'],'summary'=>$state['summary'],'donor_sources'=>$state['donor_sources'],'columns'=>$this->productColumns], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); $state['files']['summary']=$path; return $state; }
    private function writeHeader(string $f, array $h): void { $fp=fopen($f,'wb'); fputcsv($fp,$h); fclose($fp); }
    private function put(string $f, array $r): void { $fp=fopen($f,'ab'); fputcsv($fp,$r); fclose($fp); }
    private function ordered(array $row, array $cols): array { return array_map(static fn($c)=>(string)($row[$c] ?? ''), $cols); }
    private function normalize(string $v): string { return strtoupper(preg_replace('/[^A-Z0-9]/','',strtoupper($v)) ?? ''); }
    private function exportDir(string $id=''): string { $u=wp_upload_dir(); return trailingslashit($u['basedir']).'gps-laravel-product-export'.($id?'/'.sanitize_key($id):''); }
    private function publicState(array $s): array { if(!$s) return []; $s['files']=array_map('basename', (array)($s['files']??[])); return $s; }
    private function cleanup(): void
    {
        $base = $this->exportDir();
        if (!is_dir($base) || !is_readable($base)) return;
        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            if (!is_string($dir) || filemtime($dir) > time() - self::MAX_AGE_SECONDS) continue;
            foreach ((array) glob($dir . '/*') as $file) if (is_file($file)) @unlink($file);
            @rmdir($dir);
        }
    }
}
