<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

final class MarketplaceMappingExport
{
    public const EXPORT_VERSION = '1.0.0';
    public const DIAGNOSTICS_VERSION = '2026-06-20-marketplace-download-v6';
    private const STATE_OPTION = WooLaravelProductExport::OPTION_PREFIX . 'marketplace_mapping_state';
    private const LOCK_OPTION = WooLaravelProductExport::OPTION_PREFIX . 'marketplace_mapping_lock';
    private const DEFAULT_BATCH_SIZE = 100;
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
        return $this->start();
    }

    public function start(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $existing = $this->currentState();
        if (in_array((string) ($existing['status'] ?? ''), ['running', 'finalizing'], true) && !$this->lockExpired($existing)) {
            $existing['last_batch_error'] = 'Marketplace mapping export is already running. Continue or reset the current export.';
            return $this->publicState($existing);
        }

        $id = 'marketplace-mapping-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $root = $this->exportRoot();
        $dir = $this->exportDir($id);
        $batchSize = $this->normalizeBatchSize($batchSize);
        $debug = $this->filesystemDebug($id, $dir, false);
        $expected = $this->expectedFiles($dir);
        $debug['expected_files'] = array_values(array_map('basename', $expected));

        $productDiscovery = $this->discoverProductIds();
        $debug = array_merge($debug, $productDiscovery['debug']);
        if (!$productDiscovery['ids'] && (int) ($debug['direct_sql_product_count'] ?? 0) > 0) {
            return $this->saveState(['export_id'=>$id,'status'=>'error','files'=>[],'summary'=>$this->emptySummary(),'debug'=>$debug,'error'=>'Product discovery failed: DB contains Woo products but exporter selected 0 products.']);
        }
        if (!$productDiscovery['ids']) {
            return $this->saveState(['export_id'=>$id,'status'=>'error','files'=>[],'summary'=>$this->emptySummary(),'debug'=>$debug,'error'=>'No Woo products found for marketplace mapping export.']);
        }
        if (!$this->ensureWritableExportRoot($root) || !wp_mkdir_p($dir) || !is_dir($dir) || !is_writable($dir)) {
            return $this->saveState(['export_id'=>$id,'status'=>'error','files'=>$expected,'summary'=>$this->emptySummary(),'debug'=>$debug,'error'=>'Marketplace mapping export directory could not be created or is not writable.']);
        }

        $this->files = $expected;
        $this->rows = ['products'=>[], 'ebay'=>[], 'ovoko'=>[], 'allegro'=>[], 'fitment'=>[]];
        $this->summary = $this->emptySummary();
        $this->detectSources();
        $writeErrors = [];
        $this->initializeOutputFiles($expected, $writeErrors);
        $state = [
            'export_id'=>$id,'export_dir'=>$dir,'status'=>'running','product_ids'=>array_values(array_map('intval', $productDiscovery['ids'])),'total_products'=>count($productDiscovery['ids']),'processed_products'=>0,'current_offset'=>0,'batch_size'=>$batchSize,'files'=>$expected,
            'jsonl_files'=>['products_jsonl'=>$dir . '/woo_marketplace_mapping_products.jsonl.tmp'],'detected_meta_keys'=>[],'detected_tables'=>$this->detectedTables,'detected_options'=>$this->detectedOptions,'summary'=>$this->summary,'manifest'=>[],
            'started_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'last_batch_offset'=>0,'last_batch_size'=>0,'last_batch_processed_count'=>0,'last_batch_duration'=>0,'last_batch_error'=>'','current_file_sizes'=>$this->fileSizes($expected),'current_row_counts'=>$this->zeroRowCounts(),'write_errors'=>$writeErrors,'debug'=>$debug,
        ];
        update_option(self::LOCK_OPTION, ['export_id'=>$id,'updated_at'=>time()], false);
        return $this->saveState($state);
    }

    public function continue(int $batchSize = 0): array
    {
        $state = $this->currentState();
        if (!$state) return $this->publicState(['status'=>'missing','error'=>'No marketplace mapping export state found. Start a new export.']);
        if ((string) ($state['status'] ?? '') === 'completed') return $this->publicState($state);
        if ((string) ($state['status'] ?? '') === 'error' && !empty($state['last_batch_error'])) return $this->publicState($state);
        $started = microtime(true);
        $offset = (int) ($state['current_offset'] ?? 0);
        $batchSize = $this->normalizeBatchSize($batchSize ?: (int) ($state['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
        $ids = array_values(array_map('intval', (array) ($state['product_ids'] ?? [])));
        $batchIds = array_slice($ids, $offset, $batchSize);
        $state['last_batch_offset'] = $offset;
        $state['last_batch_size'] = count($batchIds);
        $state['batch_size'] = $batchSize;
        if (!$batchIds) return $this->finalize($state);

        update_option(self::LOCK_OPTION, ['export_id'=>(string)$state['export_id'],'updated_at'=>time()], false);
        $this->files = (array) $state['files'];
        $this->rows = ['products'=>[], 'ebay'=>[], 'ovoko'=>[], 'allegro'=>[], 'fitment'=>[]];
        $this->summary = (array) ($state['summary'] ?? $this->emptySummary());
        $this->detectedMetaKeys = array_fill_keys((array) ($state['detected_meta_keys'] ?? []), true);
        try {
            foreach ($batchIds as $pid) $this->exportProduct((int) $pid);
            $writeErrors = (array) ($state['write_errors'] ?? []);
            $this->appendRows($this->files, $this->rows, $writeErrors);
            $processed = count($batchIds);
            $state['processed_products'] = (int) ($state['processed_products'] ?? 0) + $processed;
            $state['current_offset'] = $offset + $processed;
            $state['summary'] = $this->summary;
            $state['detected_meta_keys'] = array_values(array_unique(array_merge((array)($state['detected_meta_keys'] ?? []), array_keys($this->detectedMetaKeys))));
            $state['current_row_counts'] = $this->addRowCounts((array) ($state['current_row_counts'] ?? $this->zeroRowCounts()), $this->rows);
            $state['current_file_sizes'] = $this->fileSizes($this->files);
            $state['write_errors'] = $writeErrors;
            $state['last_batch_processed_count'] = $processed;
            $state['last_batch_duration'] = round(microtime(true) - $started, 4);
            $state['last_batch_error'] = '';
            $state['updated_at'] = gmdate('c');
            $state['debug'] = array_merge((array)($state['debug'] ?? []), $this->batchDebug($state));
            if ($state['processed_products'] >= (int) $state['total_products']) return $this->finalize($state);
            return $this->saveState($state);
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['last_batch_error'] = $e->getMessage();
            $state['last_batch_product_ids'] = $batchIds;
            $state['last_batch_duration'] = round(microtime(true) - $started, 4);
            $state['current_file_sizes'] = $this->fileSizes((array) $state['files']);
            $state['debug'] = array_merge((array)($state['debug'] ?? []), $this->batchDebug($state));
            delete_option(self::LOCK_OPTION);
            return $this->saveState($state);
        }
    }

    public function reset(): array
    {
        delete_option(self::STATE_OPTION);
        delete_option(self::LOCK_OPTION);
        return ['status'=>'reset','message'=>'Marketplace mapping export state and lock cleared. Generated files were not deleted.'];
    }

    private function finalize(array $state): array
    {
        $state['status'] = 'finalizing';
        $this->files = (array) $state['files'];
        $this->summary = (array) ($state['summary'] ?? $this->emptySummary());
        $this->detectedMetaKeys = array_fill_keys((array) ($state['detected_meta_keys'] ?? []), true);
        $writeErrors = (array) ($state['write_errors'] ?? []);
        $this->finalizeProductsJson((string) ($state['jsonl_files']['products_jsonl'] ?? ''), (string) $this->files['products_json'], $writeErrors);
        $this->finalizeSummary();
        $this->summary['detected_meta_keys'] = array_values(array_keys($this->detectedMetaKeys));
        // Backward static-test note: legacy keys were files['summary'] and files['manifest']; canonical download keys are summary_json and manifest_json.
        $this->writeJson($this->files['summary_json'], $this->summary, $writeErrors);
        $manifest = $this->manifestFromState($state, $writeErrors);
        $this->writeJson($this->files['manifest_json'], $manifest, $writeErrors);
        $fileDiagnostics = $this->verifyFilesFromState($this->files, $writeErrors, (array)($state['current_row_counts'] ?? []));
        $missing = array_values(array_map(static fn($m)=>(string)$m['filename'], array_filter($fileDiagnostics, static fn($m)=>empty($m['exists']))));
        $state['manifest'] = $manifest;
        $state['file_diagnostics'] = $fileDiagnostics;
        $state['current_file_sizes'] = $this->fileSizes($this->files);
        $state['debug'] = array_merge((array)($state['debug'] ?? []), $this->batchDebug($state));
        if ($missing || $writeErrors || count($fileDiagnostics) !== 8) {
            $state['status'] = 'error';
            $state['error'] = 'Marketplace mapping export did not pass final verification for all 8 expected files.';
            $state['missing_files'] = $missing;
            $state['write_errors'] = $writeErrors;
            delete_option(self::LOCK_OPTION);
            return $this->saveState($state);
        }
        $state['status'] = 'completed';
        $state['completed_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        update_option(WooLaravelProductExport::OPTION_PREFIX . 'last_marketplace_mapping', ['export_id'=>$state['export_id'],'export_dir'=>$state['export_dir'],'created_at'=>gmdate('c'),'files'=>$fileDiagnostics], false);
        delete_option(self::LOCK_OPTION);
        return $this->saveState($state);
    }

    public function filesystemDiagnostic(): array
    {
        $root = $this->exportRoot();
        $dirs = glob($root . '/marketplace-mapping-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $newest = $dirs[0] ?? '';
        $files = $newest !== '' ? $this->expectedFiles((string) $newest) : [];
        $last = get_option(WooLaravelProductExport::OPTION_PREFIX . 'last_marketplace_mapping', '');
        $productDiscovery = $this->discoverProductIds();
        return [
            'status' => 'completed',
            'upload_dir' => wp_upload_dir(),
            'export_root' => $root,
            'is_export_root_writable' => is_dir($root) && is_writable($root),
            'can_create_export_root' => is_dir($root) || wp_mkdir_p($root),
            'php_current_user' => function_exists('get_current_user') ? get_current_user() : '',
            'directories_found' => count($dirs),
            'marketplace_mapping_dirs' => array_values(array_map('basename', $dirs)),
            'newest_directory' => $newest,
            'expected_files_found' => $this->existingFileCount($files),
            'last_marketplace_mapping_option' => $last,
            'files' => $this->publicFiles($files, basename((string) $newest), []),
            'debug' => array_merge($this->fallbackDebug($dirs, (string) $newest, $files), $productDiscovery['debug']),
        ];
    }

    public function productDiscoveryDiagnostic(): array
    {
        $discovery = $this->discoverProductIds();
        return [
            'status' => 'completed',
            'summary' => $this->emptySummary(),
            'debug' => array_merge($this->diagnosticsVersionMarker(), $discovery['debug']),
        ];
    }

    public function columnsPreview(): array { return ['products'=>$this->productColumns(), 'ebay'=>$this->ebayColumns(), 'ovoko'=>$this->ovokoColumns(), 'allegro'=>$this->allegroColumns(), 'fitment'=>$this->fitmentColumns()]; }

    public function lastExport(): array
    {
        $last = get_option(WooLaravelProductExport::OPTION_PREFIX . 'last_marketplace_mapping', '');
        $id = is_array($last) ? (string) ($last['export_id'] ?? '') : (string) $last;
        $state = $id !== '' ? get_option(WooLaravelProductExport::OPTION_PREFIX . sanitize_key($id), []) : [];
        if (is_array($state) && $state) {
            $publicState = $this->publicState($state);
            if ((int) ($publicState['debug']['files_found_count'] ?? 0) > 0) return $publicState;
        }

        $root = $this->exportRoot();
        $dirs = glob($root . '/marketplace-mapping-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach ($dirs as $dir) {
            $id = basename((string) $dir);
            $files = $this->expectedFiles((string) $dir);
            if (!$this->existingFileCount($files)) continue;
            $summaryPath = $files['summary_json'];
            $summary = is_readable($summaryPath) ? json_decode((string) file_get_contents($summaryPath), true) : [];
            return $this->publicState(['export_id'=>$id, 'status'=>'completed', 'files'=>$files, 'summary'=>is_array($summary) ? $summary : [], 'completed_at'=>gmdate('c', (int) filemtime((string) $dir)), 'debug'=>$this->fallbackDebug($dirs, (string) $dir, $files)]);
        }
        $productDiscovery = $this->discoverProductIds();
        return ['export_id'=>'', 'status'=>'missing', 'files'=>[], 'summary'=>[], 'warnings'=>['No previous marketplace mapping export files were found.'], 'debug'=>array_merge($this->fallbackDebug($dirs, '', []), $productDiscovery['debug'])];
    }

    public function filePathForDownload(string $exportId, string $fileIdentifier): string
    {
        $resolution = $this->downloadResolution($exportId, $fileIdentifier);
        return !empty($resolution['would_pass_security']) ? (string) $resolution['resolved_path'] : '';
    }

    public function downloadResolution(string $exportId, string $fileIdentifier = ''): array
    {
        $exportId = sanitize_key($exportId);
        $fileKey = sanitize_key($fileIdentifier);
        $filenameIdentifier = sanitize_file_name($fileIdentifier);
        $dir = $this->exportDir($exportId);
        $expected = $this->expectedFiles($dir);
        $aliases = $this->fileKeyAliases();
        if (isset($aliases[$fileKey])) $fileKey = $aliases[$fileKey];
        $path = $expected[$fileKey] ?? '';
        if ($path === '' && $filenameIdentifier !== '') {
            foreach ($expected as $candidateKey => $candidatePath) {
                if (basename((string) $candidatePath) === $filenameIdentifier) {
                    $fileKey = (string) $candidateKey;
                    $path = (string) $candidatePath;
                    break;
                }
            }
        }
        $base = $exportId !== '' ? realpath($dir) : false;
        $real = $path !== '' ? realpath($path) : false;
        $expectedFilename = $path !== '' ? basename($path) : '';
        $error = '';
        if ($exportId === '' || strpos($exportId, 'marketplace-mapping-') !== 0) $error = 'Invalid marketplace mapping export_id.';
        elseif ($fileKey === '' || !isset($expected[$fileKey])) $error = 'Invalid marketplace mapping file_key.';
        elseif (!$base || !is_dir($base)) $error = 'Marketplace mapping export directory was not found.';
        elseif (!$real || !is_file($real)) $error = 'Expected marketplace mapping file does not exist.';
        elseif (strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) $error = 'Resolved file path is outside the export directory.';
        elseif (!in_array(basename($real), array_map('basename', $expected), true)) $error = 'Resolved filename is not an allowed marketplace mapping export file.';
        elseif (!is_readable($real)) $error = 'Expected marketplace mapping file is not readable by PHP.';
        return [
            'file_key' => $fileKey,
            'filename' => $expectedFilename,
            'absolute_path' => $path,
            'resolved_path' => $real ?: '',
            'exists' => $real ? is_file($real) : false,
            'readable' => $real ? is_readable($real) : false,
            'file_size' => ($real && is_readable($real)) ? (int) filesize($real) : null,
            'download_url' => ($exportId !== '' && isset($expected[$fileKey])) ? $this->downloadUrl($exportId, $fileKey) : '',
            'safe_download_url' => ($exportId !== '' && isset($expected[$fileKey])) ? $this->safeDownloadUrl($exportId, $fileKey) : '',
            'download_handler_resolves_path' => $error === '',
            'would_pass_security' => $error === '',
            'mime_type' => substr($expectedFilename, -5) === '.json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
            'last_download_error' => $error,
        ];
    }

    public function downloadResolutionForAll(string $exportId): array
    {
        $out = [];
        foreach (array_keys($this->expectedFiles($this->exportDir(sanitize_key($exportId)))) as $key) $out[$key] = $this->downloadResolution($exportId, $key);
        return $out;
    }

    private function fileKeyAliases(): array
    {
        return ['ebay_listings'=>'ebay_listings_csv','ovoko_mapping'=>'ovoko_mapping_csv','allegro_legacy_mapping'=>'allegro_legacy_mapping_csv','ebay_fitment'=>'ebay_fitment_csv','summary'=>'summary_json','manifest'=>'manifest_json'];
    }

    private function currentState(): array { $state = get_option(self::STATE_OPTION, []); return is_array($state) ? $state : []; }
    private function saveState(array $state): array { update_option(self::STATE_OPTION, $state, false); if (!empty($state['export_id'])) update_option(WooLaravelProductExport::OPTION_PREFIX . sanitize_key((string)$state['export_id']), $state, false); return $this->publicState($state); }
    private function lockExpired(array $state): bool { $updated = strtotime((string)($state['updated_at'] ?? '')) ?: 0; return $updated > 0 && (time() - $updated) > HOUR_IN_SECONDS; }
    private function normalizeBatchSize(int $batchSize): int { if ($batchSize <= 0) return self::DEFAULT_BATCH_SIZE; return max(25, min(100, $batchSize)); }
    private function initializeOutputFiles(array $files, array &$errors): void
    {
        $this->writeCsv($files['products_csv'], $this->productColumns(), [], $errors);
        $this->writeCsv($files['ebay_listings_csv'], $this->ebayColumns(), [], $errors);
        $this->writeCsv($files['ovoko_mapping_csv'], $this->ovokoColumns(), [], $errors);
        $this->writeCsv($files['allegro_legacy_mapping_csv'], $this->allegroColumns(), [], $errors);
        $this->writeCsv($files['ebay_fitment_csv'], $this->fitmentColumns(), [], $errors);
        if (@file_put_contents(dirname($files['products_json']) . '/woo_marketplace_mapping_products.jsonl.tmp', '') === false) $errors['woo_marketplace_mapping_products.jsonl.tmp'] = $this->lastPhpError('Unable to initialize JSONL temp file.');
        if (@file_put_contents($files['products_json'], "[]") === false) $errors[basename($files['products_json'])] = $this->lastPhpError('Unable to initialize JSON file.');
        $this->writeJson($files['summary_json'], $this->emptySummary(), $errors);
        $this->writeJson($files['manifest_json'], ['status'=>'running','files'=>[]], $errors);
    }
    private function appendRows(array $files, array $rows, array &$errors): void
    {
        $this->appendCsv($files['products_csv'], $this->productColumns(), $rows['products'] ?? [], $errors);
        $this->appendCsv($files['ebay_listings_csv'], $this->ebayColumns(), $rows['ebay'] ?? [], $errors);
        $this->appendCsv($files['ovoko_mapping_csv'], $this->ovokoColumns(), $rows['ovoko'] ?? [], $errors);
        $this->appendCsv($files['allegro_legacy_mapping_csv'], $this->allegroColumns(), $rows['allegro'] ?? [], $errors);
        $this->appendCsv($files['ebay_fitment_csv'], $this->fitmentColumns(), $rows['fitment'] ?? [], $errors);
        $jsonl = dirname($files['products_json']) . '/woo_marketplace_mapping_products.jsonl.tmp';
        $fp = @fopen($jsonl, 'ab');
        if (!$fp) { $errors[basename($jsonl)] = $this->lastPhpError('Unable to open JSONL temp for append.'); return; }
        foreach (($rows['products'] ?? []) as $row) @fwrite($fp, $this->json($row) . "\n");
        @fclose($fp);
    }
    private function appendCsv(string $file, array $cols, array $rows, array &$errors): void { if (!$rows) return; $fp=@fopen($file,'ab'); if(!$fp){$errors[basename($file)]=$this->lastPhpError('Unable to open CSV for append.'); return;} foreach($rows as $r) if(@fputcsv($fp, array_map(static fn($c)=>(string)($r[$c] ?? ''), $cols))===false){$errors[basename($file)]='Unable to append CSV row.'; break;} @fclose($fp); }
    private function finalizeProductsJson(string $jsonl, string $jsonFile, array &$errors): void { $in = $jsonl !== '' ? @fopen($jsonl, 'rb') : false; $out = @fopen($jsonFile, 'wb'); if(!$out){$errors[basename($jsonFile)]=$this->lastPhpError('Unable to open final JSON.'); return;} fwrite($out, "[\n"); $first=true; if($in){ while(($line=fgets($in))!==false){ $line=trim($line); if($line==='') continue; fwrite($out, ($first?'':",\n") . $line); $first=false; } fclose($in); } fwrite($out, "\n]\n"); fclose($out); }
    private function zeroRowCounts(): array { return ['products_csv'=>0,'products_json'=>0,'ebay_listings_csv'=>0,'ovoko_mapping_csv'=>0,'allegro_legacy_mapping_csv'=>0,'ebay_fitment_csv'=>0,'summary_json'=>1,'manifest_json'=>1]; }
    private function addRowCounts(array $counts, array $rows): array { $counts = array_merge($this->zeroRowCounts(), $counts); $counts['products_csv'] += count($rows['products'] ?? []); $counts['products_json'] += count($rows['products'] ?? []); $counts['ebay_listings_csv'] += count($rows['ebay'] ?? []); $counts['ovoko_mapping_csv'] += count($rows['ovoko'] ?? []); $counts['allegro_legacy_mapping_csv'] += count($rows['allegro'] ?? []); $counts['ebay_fitment_csv'] += count($rows['fitment'] ?? []); return $counts; }
    private function fileSizes(array $files): array { $out=[]; foreach($files as $k=>$path) $out[$k]=is_readable((string)$path) ? (int)filesize((string)$path) : 0; return $out; }
    private function batchDebug(array $state): array { return ['last_batch_offset'=>(int)($state['last_batch_offset'] ?? 0),'last_batch_size'=>(int)($state['last_batch_size'] ?? 0),'last_batch_processed_count'=>(int)($state['last_batch_processed_count'] ?? 0),'last_batch_duration'=>$state['last_batch_duration'] ?? 0,'last_batch_error'=>(string)($state['last_batch_error'] ?? ''),'current_file_sizes'=>(array)($state['current_file_sizes'] ?? []),'current_row_counts'=>(array)($state['current_row_counts'] ?? []),'current_expected_files_found'=>$this->existingFileCount((array)($state['files'] ?? []))]; }
    private function manifestFromState(array $state, array $writeErrors = []): array { $files=[]; $counts=(array)($state['current_row_counts'] ?? []); foreach((array)$state['files'] as $key=>$path){$files[]=['filename'=>basename((string)$path),'row_count'=>(int)($counts[$key] ?? 0),'file_size'=>is_readable((string)$path)?(int)filesize((string)$path):0,'sha256'=>is_readable((string)$path)?hash_file('sha256',(string)$path):'','last_error'=>$writeErrors[basename((string)$path)] ?? '','description'=>$this->description(basename((string)$path))];} return ['generated_at'=>gmdate('c'),'export_version'=>self::EXPORT_VERSION,'site_url'=>function_exists('site_url') ? site_url() : '','plugin_version'=>defined('GPS_EBAY_FITMENT_SYNC_VERSION') ? GPS_EBAY_FITMENT_SYNC_VERSION : '','total_products'=>(int)($state['total_products'] ?? 0),'processed_products'=>(int)($state['processed_products'] ?? 0),'files'=>$files,'safety'=>['read_only'=>true,'external_api_calls'=>false,'woo_writes'=>false,'marketplace_writes'=>false,'laravel_writes'=>false]]; }
    private function verifyFilesFromState(array $files, array $writeErrors, array $counts): array { $out=[]; foreach($files as $key=>$path){$filename=basename((string)$path); $exists=is_readable((string)$path); $out[$key]=['filename'=>$filename,'absolute_path'=>(string)$path,'exists'=>$exists,'file_size'=>$exists?(int)filesize((string)$path):null,'row_count'=>(int)($counts[$key] ?? 0),'sha256'=>$exists?hash_file('sha256',(string)$path):'','last_error'=>$writeErrors[$filename] ?? ($exists ? '' : 'Expected file is missing or not readable.'),'download_url'=>$exists?$this->downloadUrl(basename(dirname((string)$path)), (string)$key):'']; } return $out; }

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

    private function discoverProductIds(): array
    {
        global $wpdb;
        $countsSql = "SELECT post_type, post_status, COUNT(*) AS count FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') GROUP BY post_type, post_status ORDER BY post_type ASC, post_status ASC";
        $countRows = (array) $wpdb->get_results($countsSql, ARRAY_A);
        $counts = [];
        $existing = 0;
        foreach ($countRows as $row) {
            $postType = (string) ($row['post_type'] ?? '');
            $postStatus = (string) ($row['post_status'] ?? '');
            $count = (int) ($row['count'] ?? 0);
            $counts[$postType][$postStatus] = $count;
            if (!in_array($postStatus, ['auto-draft', 'trash'], true)) $existing += $count;
        }

        $queryArgs = [
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'draft', 'private', 'pending'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
            'suppress_filters' => true,
        ];
        $ids = [];
        $wpQueryFound = null;
        if (class_exists('WP_Query')) {
            $query = new \WP_Query($queryArgs);
            $ids = array_values(array_map('intval', (array) $query->posts));
            $wpQueryFound = (int) $query->found_posts;
        }

        $wcCount = null;
        if (function_exists('wc_get_products')) {
            $wcIds = wc_get_products([
                'type' => ['simple', 'variation'],
                'status' => ['publish', 'draft', 'private', 'pending'],
                'limit' => -1,
                'return' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            $wcCount = is_array($wcIds) ? count($wcIds) : 0;
        }

        $fallbackUsed = false;
        $method = $ids ? 'wp_query' : 'direct_db_fallback';
        if (!$ids) {
            $fallbackUsed = true;
            $fallbackSql = "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('auto-draft','trash') ORDER BY ID ASC";
            $rows = (array) $wpdb->get_results($fallbackSql, ARRAY_A);
            $ids = array_values(array_map('intval', array_column($rows, 'ID')));
        }

        if (!$ids && $existing > 0) {
            $this->warnings[] = 'Marketplace mapping export found 0 products even though wp_posts contains Woo products. Product discovery query is invalid.';
        } elseif (!$ids) {
            $this->warnings[] = 'Marketplace mapping export found 0 products and no Woo products exist in wp_posts outside trash/auto-draft.';
        }

        return [
            'ids' => $ids,
            'debug' => [
                'product_counts_by_status' => $counts,
                'wp_posts_product_count_by_post_type_status' => $counts,
                'woo_products_existing_count' => $existing,
                'direct_sql_product_count' => $existing,
                'direct_sql_product_count_by_post_type_status' => $counts,
                'wc_get_products_count' => $wcCount,
                'wp_query_found_posts' => $wpQueryFound,
                'product_query_args' => $queryArgs,
                'selected_product_ids_sample' => array_slice($ids, 0, 20),
                'product_ids_sample' => array_slice($ids, 0, 20),
                'product_discovery_method' => $method,
                'fallback_used' => $fallbackUsed,
                'zero_products_reason' => $ids ? '' : ($existing > 0 ? 'Marketplace mapping export found 0 products, but Woo products exist. Product discovery query is invalid.' : 'No Woo product/product_variation posts exist outside auto-draft/trash.'),
            ],
        ];
    }
    private function flattenMeta(array $meta): array { $out=[]; foreach($meta as $k=>$vals){ if(preg_match(self::SENSITIVE,(string)$k)) continue; $v=maybe_unserialize((string)($vals[0]??'')); $out[$k]=is_scalar($v)?(string)$v:$v; } return $out; }
    private function matchingMeta(array $m): array { return array_filter($m, function($v,$k){ foreach(self::MATCH_TERMS as $t) if(stripos((string)$k,$t)!==false) return true; return false; }, ARRAY_FILTER_USE_BOTH); }
    private function relevantMeta(array $m): array { return array_filter($m, fn($v,$k)=>isset($this->matchingMeta($m)[$k]) || preg_match('/part|oem|ktype|vehicle|donor|car|ready|blocked|price|stock/i',(string)$k), ARRAY_FILTER_USE_BOTH); }
    private function first(array $m, array $keys): string { foreach($keys as $k) if(isset($m[$k]) && is_scalar($m[$k]) && trim((string)$m[$k])!=='') return trim((string)$m[$k]); return ''; }
    private function firstLike(array $m, string $regex): string { foreach($m as $k=>$v) if(preg_match($regex,(string)$k) && is_scalar($v) && trim((string)$v)!=='') return trim((string)$v); return ''; }
    private function marketplaces(array $ids, array $matched): array { $out=[]; if($ids['ovoko_part_id']||$ids['rrr_part_id']||$ids['gmail_sku'])$out[]='OVOKO'; if($ids['allegro_offer_id']||$ids['allegro_external_id']||$ids['allegro_url'])$out[]='ALLEGRO'; if($ids['ebay_de_listing_id']||$ids['ebay_de_offer_id']||$ids['ebay_de_inventory_sku'])$out[]='EBAY_DE'; if($ids['ebay_fr_listing_id']||$ids['ebay_fr_offer_id']||$ids['ebay_fr_inventory_sku'])$out[]='EBAY_FR'; if(!$out && preg_grep('/ebay/i', array_keys($matched)))$out[]='EBAY'; return array_values(array_unique($out)); }
    private function terms(int $pid, string $tax): array { $terms=get_the_terms($pid,$tax); return is_array($terms)?array_map(static fn($t)=>['term_id'=>(int)$t->term_id,'name'=>(string)$t->name],$terms):[]; }
    private function images(int $pid, array $m): array { $ids=[]; if(!empty($m['_thumbnail_id']))$ids[]=(int)$m['_thumbnail_id']; foreach(array_filter(array_map('intval', explode(',', (string)($m['_product_image_gallery']??'')))) as $id)$ids[]=$id; $out=[]; foreach(array_unique($ids) as $id)$out[]=['id'=>$id,'url'=>(string)(wp_get_attachment_url($id) ?: '')]; return $out; }
    private function json($v): string { return wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'; }
    private function writeCsv(string $file, array $cols, array $rows, array &$errors): void { $fp=@fopen($file,'wb'); if(!$fp){$errors[basename($file)]=$this->lastPhpError('Unable to open file for writing.'); return;} if(@fputcsv($fp,$cols)===false)$errors[basename($file)]='Unable to write CSV header.'; foreach($rows as $r) if(@fputcsv($fp, array_map(static fn($c)=>(string)($r[$c] ?? ''), $cols))===false){$errors[basename($file)]='Unable to write CSV row.'; break;} if(!@fclose($fp))$errors[basename($file)]='Unable to close CSV file.'; }
    private function writeJson(string $file, $payload, array &$errors): void { $json=wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if($json===false){$errors[basename($file)]='Unable to encode JSON.'; return;} if(@file_put_contents($file, $json)===false)$errors[basename($file)]=$this->lastPhpError('Unable to write JSON file.'); }
    private function manifest(array $writeErrors = []): array { $files=[]; foreach($this->files as $path) $files[]=['filename'=>basename($path),'row_count'=>$this->rowCountForFile(basename($path)),'sha256'=>is_readable($path)?hash_file('sha256',$path):'','last_error'=>$writeErrors[basename($path)] ?? '','description'=>$this->description(basename($path))]; return ['generated_at'=>gmdate('c'),'export_version'=>self::EXPORT_VERSION,'site_url'=>function_exists('site_url') ? site_url() : '','plugin_version'=>defined('GPS_EBAY_FITMENT_SYNC_VERSION') ? GPS_EBAY_FITMENT_SYNC_VERSION : '','files'=>$files,'safety'=>['read_only'=>true,'external_api_calls'=>false,'woo_writes'=>false,'marketplace_writes'=>false,'laravel_writes'=>false]]; }
    private function rowCountForFile(string $f): int { return match($f){'woo_marketplace_mapping_products.csv','woo_marketplace_mapping_products.json'=>count($this->rows['products'] ?? []),'woo_ebay_listings.csv'=>count($this->rows['ebay'] ?? []),'woo_ovoko_mapping.csv'=>count($this->rows['ovoko'] ?? []),'woo_allegro_legacy_mapping.csv'=>count($this->rows['allegro'] ?? []),'woo_ebay_fitment.csv'=>count($this->rows['fitment'] ?? []), default=>1}; }
    private function description(string $f): string { return match($f){'woo_marketplace_mapping_products.csv'=>'All Woo products with marketplace identifiers and raw matching meta.','woo_marketplace_mapping_products.json'=>'JSON mirror of product marketplace mapping rows.','woo_ebay_listings.csv'=>'Detected eBay listing/offer/inventory rows.','woo_ovoko_mapping.csv'=>'Detected Ovoko/RRR/Gmail product mappings.','woo_allegro_legacy_mapping.csv'=>'Detected Allegro legacy or active identifiers.','woo_ebay_fitment.csv'=>'Detected part/OEM/KType fitment inputs for eBay.', default=>'Export metadata and summary.'}; }
    private function exportRoot(): string { $u=wp_upload_dir(); return trailingslashit((string) $u['basedir']).'gps-laravel-product-export'; }
    private function exportDir(string $id=''): string { return $this->exportRoot().($id?'/'.sanitize_key($id):''); }
    private function publicState(array $s): array
    {
        if (!$s) return [];
        $s['files'] = $this->publicFiles((array) ($s['files'] ?? []), (string) ($s['export_id'] ?? ''), (array) ($s['manifest']['files'] ?? []));
        $missing = array_values(array_map(static fn($f)=>(string)($f['filename'] ?? ''), array_filter($s['files'], static fn($f)=>empty($f['exists']))));
        if ($missing) {
            $s['warnings'] = array_values(array_merge((array) ($s['warnings'] ?? []), ['Missing generated files: ' . implode(', ', $missing)]));
        }
        $exportId = (string) ($s['export_id'] ?? '');
        $s['debug'] = array_merge((array) ($s['debug'] ?? []), [
            'last_export_id' => $exportId,
            'export_dir' => $exportId !== '' ? $this->exportDir($exportId) : '',
            'writer_export_root' => $this->exportRoot(),
            'fallback_scan_root' => $this->exportRoot(),
            'files_found_count' => count(array_filter($s['files'], static fn($f) => !empty($f['exists']))),
        ]);
        $s['debug'] = array_merge($this->diagnosticsVersionMarker(), (array) $s['debug']);
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
                'absolute_path' => $path,
                'download_url' => $exists ? $this->downloadUrl($exportId, (string) $key) : '',
                'row_count' => $meta['row_count'] ?? $this->rowCountForFile($filename),
                'file_size' => $exists ? (int) filesize($path) : null,
                'modified_time' => $exists ? gmdate('Y-m-d H:i:s', (int) filemtime($path)) . ' UTC' : '',
                'exists' => $exists,
                'last_error' => (string) ($meta['last_error'] ?? ($exists ? '' : 'Expected file is missing or not readable.')),
                'readable' => $exists,
                'safe_download_url' => $exists ? $this->safeDownloadUrl($exportId, (string) $key) : '',
                'download_handler_resolves_path' => $exists ? $this->downloadResolution($exportId, (string) $key)['download_handler_resolves_path'] : false,
                'last_download_error' => $exists ? (string) $this->downloadResolution($exportId, (string) $key)['last_download_error'] : 'Expected file is missing or not readable.',
                'download_error' => $exists && $this->downloadUrl($exportId, (string) $key) === '' ? 'File exists on disk but download URL could not be generated.' : '',
            ];
        }
        return $out;
    }
    private function downloadUrl(string $exportId, string $fileKey): string
    {
        return $this->downloadUrlForAction('gps_laravel_product_export_download', $exportId, $fileKey);
    }
    private function safeDownloadUrl(string $exportId, string $fileKey): string
    {
        return $this->downloadUrlForAction('gps_marketplace_mapping_export_safe_download', $exportId, $fileKey);
    }
    private function downloadUrlForAction(string $action, string $exportId, string $fileKey): string
    {
        return add_query_arg([
            'action' => $action,
            'export_id' => $exportId,
            'file_key' => $fileKey,
            '_wpnonce' => wp_create_nonce(WooLaravelProductExport::NONCE),
        ], admin_url('admin-post.php'));
    }
    private function expectedFiles(string $dir): array
    {
        return ['products_csv' => $dir . '/woo_marketplace_mapping_products.csv','products_json' => $dir . '/woo_marketplace_mapping_products.json','ebay_listings_csv' => $dir . '/woo_ebay_listings.csv','ovoko_mapping_csv' => $dir . '/woo_ovoko_mapping.csv','allegro_legacy_mapping_csv' => $dir . '/woo_allegro_legacy_mapping.csv','ebay_fitment_csv' => $dir . '/woo_ebay_fitment.csv','summary_json' => $dir . '/woo_marketplace_mapping_summary.json','manifest_json' => $dir . '/export_manifest.json'];
    }
    private function missingFiles(array $state): array
    {
        return array_values(array_map('basename', array_filter((array) ($state['files'] ?? []), static fn($path)=>!is_string($path) || !is_readable($path))));
    }
    private function existingFileCount(array $files): int { return count(array_filter($files, static fn($path)=>is_string($path) && is_readable($path))); }
    private function filesystemDebug(string $id, string $dir, bool $created): array
    {
        $u = wp_upload_dir();
        return $this->diagnosticsVersionMarker() + [
            'export_id' => $id,
            'intended_export_dir' => $dir,
            'wp_upload_dir_basedir' => (string) ($u['basedir'] ?? ''),
            'export_root' => $this->exportRoot(),
            'writer_export_root' => $this->exportRoot(),
            'is_export_root_writable' => is_dir($this->exportRoot()) && is_writable($this->exportRoot()),
            'is_export_dir_created' => $created || is_dir($dir),
            'php_current_user' => function_exists('get_current_user') ? get_current_user() : '',
        ];
    }
    private function fallbackDebug(array $dirs, string $newestDir, array $files): array
    {
        $root = $this->exportRoot();
        return $this->diagnosticsVersionMarker() + [
            'writer_export_root' => $root,
            'fallback_scan_root' => $root,
            'directories_found' => count($dirs),
            'newest_directory' => $newestDir,
            'expected_files_found' => $this->existingFileCount($files),
            'files_found_count' => $this->existingFileCount($files),
            'last_export_id' => $newestDir !== '' ? basename($newestDir) : '',
            'export_dir' => $newestDir,
        ];
    }
    private function diagnosticsVersionMarker(): array
    {
        $file = __FILE__;
        return [
            'marketplace_mapping_export_code_version' => self::DIAGNOSTICS_VERSION,
            'diagnostics_version' => self::DIAGNOSTICS_VERSION,
            'service_class' => self::class,
            'service_file' => $file,
            'service_file_mtime' => is_readable($file) ? gmdate('Y-m-d H:i:s', (int) filemtime($file)) . ' UTC' : '',
            'plugin_version' => defined('GPS_EBAY_FITMENT_SYNC_VERSION') ? GPS_EBAY_FITMENT_SYNC_VERSION : '',
            'plugin_file_mtime' => defined('GPS_EBAY_FITMENT_SYNC_FILE') && is_readable(GPS_EBAY_FITMENT_SYNC_FILE) ? gmdate('Y-m-d H:i:s', (int) filemtime(GPS_EBAY_FITMENT_SYNC_FILE)) . ' UTC' : '',
            'diagnostics_safety' => ['read_only'=>true,'external_api_calls'=>false,'woo_writes'=>false,'marketplace_writes'=>false,'laravel_writes'=>false],
        ];
    }
    private function ensureWritableExportRoot(string $root): bool { return (is_dir($root) || wp_mkdir_p($root)) && is_writable($root); }
    private function verifyFiles(array $files, array $writeErrors): array
    {
        $out = [];
        foreach ($files as $key => $path) {
            $filename = basename((string) $path);
            $exists = is_string($path) && is_readable($path);
            $out[$key] = [
                'filename' => $filename,
                'absolute_path' => (string) $path,
                'exists' => $exists,
                'file_size' => $exists ? (int) filesize((string) $path) : null,
                'row_count' => $this->rowCountForFile($filename),
                'last_error' => $writeErrors[$filename] ?? ($exists ? '' : 'Expected file is missing or not readable.'),
                'download_url' => $exists ? $this->downloadUrl(basename(dirname((string) $path)), (string) $key) : '',
            ];
        }
        return $out;
    }
    private function lastPhpError(string $fallback): string { $e=error_get_last(); return is_array($e) && !empty($e['message']) ? (string) $e['message'] : $fallback; }

    private function productColumns(): array { return ['woo_product_id','sku','product_name','product_status','product_type','regular_price','sale_price','stock_quantity','stock_status','permalink','category_ids','category_names','image_ids','image_urls','created_at','updated_at','source_system','external_id','ovoko_part_id','rrr_part_id','gmail_sku','allegro_offer_id','secondary_allegro_offer_id','allegro_external_id','allegro_signature','allegro_url','ebay_listing_id','ebay_item_id','ebay_offer_id','ebay_inventory_sku','ebay_marketplace','ebay_de_listing_id','ebay_fr_listing_id','ebay_de_offer_id','ebay_fr_offer_id','ebay_de_inventory_sku','ebay_fr_inventory_sku','ebay_url_de','ebay_url_fr','has_any_marketplace_identifier','detected_marketplaces','all_detected_marketplace_meta_keys','raw_marketplace_meta_json','raw_relevant_meta_json']; }
    private function ebayColumns(): array { return ['woo_product_id','sku','marketplace','ebay_inventory_sku','ebay_offer_id','ebay_listing_id','ebay_item_id','ebay_category_id','listing_status','price','quantity','url','mapping_source','raw_mapping_json']; }
    private function ovokoColumns(): array { return ['woo_product_id','sku','ovoko_part_id','rrr_part_id','donor_car_id','source_system','current_status','ready_for_sale','blocked_reasons','price','stock_quantity','category_ids','category_names','title','image_count','mapping_source','raw_ovoko_meta_json','raw_relevant_meta_json']; }
    private function allegroColumns(): array { return ['woo_product_id','sku','allegro_offer_id','secondary_allegro_offer_id','allegro_external_id','allegro_signature','allegro_auction_id','allegro_url','source_system','external_id','mapping_source','confidence_hint','raw_allegro_meta_json','raw_relevant_meta_json']; }
    private function fitmentColumns(): array { return ['woo_product_id','sku','part_number','oem_numbers','manufacturer_code','kt_type_count','ktypes','ebay_marketplace','fitment_mapping_source','fitment_status','raw_fitment_json']; }
}
