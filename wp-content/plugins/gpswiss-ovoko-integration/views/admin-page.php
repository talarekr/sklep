<?php
/** @var array $data */
/** @var array|null $notice */

$csvStatus = (array) ($data['csv_mapping_status'] ?? []);
$memoryLimitRaw = (string) ini_get('memory_limit');
$memoryLimitMb = (int) preg_replace('/[^0-9]/', '', $memoryLimitRaw);
$blockFullBulk = $memoryLimitMb > 0 && $memoryLimitMb <= 128;
$showAdvancedTools = defined('GPSWISS_OVOKO_SHOW_ADVANCED_TOOLS') ? (bool) GPSWISS_OVOKO_SHOW_ADVANCED_TOOLS : true;

$apiConnection = (array) ($data['api_connection_test'] ?? []);
$apiOk = !empty($apiConnection['ok']);
$apiStatusText = $apiOk ? 'OK' : ('ERROR — ' . (string) ($apiConnection['error'] ?? $apiConnection['reason'] ?? 'not tested'));
$csvLoaded = !empty($csvStatus['rows_total']);
$lastSyncStatus = (string) ($data['settings']['ovoko_sync_mode'] ?? 'unknown');

$noticePayload = null;
if (!empty($notice['text']) && is_string($notice['text'])) {
    $decoded = json_decode($notice['text'], true);
    if (is_array($decoded)) {
        $noticePayload = $decoded;
    }
}

$noticeActionName = (string) ($noticePayload['action_name'] ?? '');
$productActionNames = [
    'Create Woo draft product from RRR part',
    'Update product cards from Ovoko CSV mapping',
    'Single enrichment dry-run',
    'Apply Allegro to Ovoko details enrichment',
];
$hasApiMarkers = isset($noticePayload['status_label']) || isset($noticePayload['tested_endpoint']) || isset($noticePayload['http_status']);
$hasProductId = !empty($noticePayload['product_id']) || !empty($noticePayload['sample_results'][0]['product_id']);
$isApiTestResult = is_array($noticePayload) && $hasApiMarkers && !$hasProductId;
$isKnownProductAction = in_array($noticeActionName, $productActionNames, true);
$showProductSummary = is_array($noticePayload) && !$isApiTestResult && ($isKnownProductAction || $hasProductId);
?>
<div class="wrap" style="max-width:1180px;">
    <h1>Ovoko / RRR Integration</h1>

    <div class="notice notice-info"><p>
        <strong>API connection:</strong> <?php echo esc_html($apiStatusText); ?> |
        <strong>CSV mapping:</strong> <?php echo $csvLoaded ? 'loaded' : 'not loaded'; ?> |
        <strong>CSV rows:</strong> <?php echo esc_html((string) ($csvStatus['rows_total'] ?? 0)); ?> |
        <strong>Unique codes:</strong> <?php echo esc_html((string) ($csvStatus['unique_part_codes'] ?? 0)); ?> |
        <strong>Duplicates:</strong> <?php echo esc_html((string) ($csvStatus['duplicate_part_codes_count'] ?? 0)); ?> |
        <strong>PHP memory_limit:</strong> <?php echo esc_html($memoryLimitRaw); ?> |
        <strong>Last sync status:</strong> <?php echo esc_html($lastSyncStatus); ?>
    </p></div>


    <div style="margin:8px 0 14px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_test_api_connection'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_test_api_connection" />
            <?php submit_button('Test API connection', 'secondary', 'submit', false); ?>
        </form>
        <?php if (!empty($apiConnection)): ?>
            <details style="display:inline-block; vertical-align:middle;"><summary>Show API test details</summary><pre><?php echo esc_html(wp_json_encode($apiConnection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        <?php endif; ?>
    </div>

    <?php if (!empty($notice)): ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
            <p><?php echo esc_html((string) ($notice['text'] ?? '')); ?></p>
            <?php if (is_array($noticePayload)): ?>
                <?php if ($isApiTestResult): ?>
                    <div class="postbox" style="padding:12px; margin:10px 0 0; max-width:760px;">
                        <h3 style="margin-top:0;">API connection: <?php echo esc_html((string) ($noticePayload['status_label'] ?? ($noticePayload['ok'] ? 'OK' : 'ERROR'))); ?></h3>
                        <ul style="margin:0 0 0 18px;">
                            <li><strong>Endpoint:</strong> <code><?php echo esc_html((string) ($noticePayload['tested_endpoint'] ?? '')); ?></code></li>
                            <li><strong>HTTP status:</strong> <code><?php echo esc_html((string) ($noticePayload['http_status'] ?? '')); ?></code></li>
                            <li><strong>Base URL:</strong> <code><?php echo esc_html((string) ($noticePayload['base_url'] ?? '')); ?></code></li>
                            <li><strong>Token present:</strong> <code><?php echo !empty($noticePayload['token_present']) ? 'yes' : 'no'; ?></code></li>
                            <li><strong>Credentials present:</strong> <code><?php echo !empty($noticePayload['credentials_present']) ? 'yes' : 'no'; ?></code></li>
                            <li><strong>Reason:</strong> <code><?php echo esc_html((string) ($noticePayload['reason'] ?? '')); ?></code></li>
                            <li><strong>Checked at:</strong> <code><?php echo esc_html((string) ($noticePayload['checked_at'] ?? $noticePayload['tested_at'] ?? $noticePayload['timestamp'] ?? '')); ?></code></li>
                        </ul>
                    </div>
                <?php elseif ($showProductSummary): ?>
                    <ul style="margin-left:18px;">
                        <li><strong>product_id:</strong> <code><?php echo esc_html((string) ($noticePayload['product_id'] ?? ($noticePayload['sample_results'][0]['product_id'] ?? ''))); ?></code></li>
                        <li><strong>matched_ovoko_part_id:</strong> <code><?php echo esc_html((string) ($noticePayload['matched_ovoko_part_id'] ?? ($noticePayload['sample_results'][0]['matched_ovoko_part_id'] ?? ''))); ?></code></li>
                        <li><strong>matched_ovoko_car_id:</strong> <code><?php echo esc_html((string) ($noticePayload['matched_ovoko_car_id'] ?? ($noticePayload['sample_results'][0]['matched_ovoko_car_id'] ?? ''))); ?></code></li>
                        <?php
                        $summaryAttributesCount = $noticePayload['attributes_count'] ?? null;
                        if ($summaryAttributesCount === null && isset($noticePayload['attributes_written']) && is_array($noticePayload['attributes_written'])) {
                            $summaryAttributesCount = count($noticePayload['attributes_written']);
                        }
                        if ($summaryAttributesCount === null) {
                            $sample = (array) ($noticePayload['sample_results'][0] ?? []);
                            $summaryAttributesCount = $sample['attributes_count'] ?? null;
                            if ($summaryAttributesCount === null && isset($sample['attributes_written']) && is_array($sample['attributes_written'])) {
                                $summaryAttributesCount = count($sample['attributes_written']);
                            }
                            if ($summaryAttributesCount === null) {
                                $summaryAttributesCount = $sample['would_write_attributes_count'] ?? ($noticePayload['would_write_attributes_count'] ?? '');
                            }
                        }
                        ?>
                        <li><strong>attributes_count:</strong> <code><?php echo esc_html((string) $summaryAttributesCount); ?></code></li>
                        <li><strong>no_price_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_price_change'] ?? ($noticePayload['sample_results'][0]['no_price_change'] ?? ''))); ?></code></li>
                        <li><strong>no_stock_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_stock_change'] ?? ($noticePayload['sample_results'][0]['no_stock_change'] ?? ''))); ?></code></li>
                        <li><strong>no_images_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_images_change'] ?? ($noticePayload['sample_results'][0]['no_images_change'] ?? ''))); ?></code></li>
                        <li><strong>no_title_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_title_change'] ?? ($noticePayload['sample_results'][0]['no_title_change'] ?? ''))); ?></code></li>
                        <li><strong>memory_peak_mb:</strong> <code><?php echo esc_html((string) ($noticePayload['memory_peak_mb'] ?? ($noticePayload['sample_results'][0]['memory_peak_mb'] ?? ''))); ?></code></li>
                    </ul>
                <?php endif; ?>
                <details><summary>Show technical JSON</summary><pre><?php echo esc_html(wp_json_encode($noticePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Main actions</h2>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Create Woo draft product from RRR part</h3>
        <p>Creates Woo draft product. Does not publish to Allegro/eBay/batches.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_create_rrr_woo_draft'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_create_rrr_woo_draft" />
            <label for="create_draft_part_id">part_id:</label>
            <input id="create_draft_part_id" type="number" min="1" name="part_id" value="10994" />
            <?php submit_button('Create draft product', 'primary', 'submit', false); ?>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update single existing product from CSV mapping</h3>
        <p>Updates only Ovoko/RRR detail attributes and meta. Does not change price, stock, images, title or publication status.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_single_enrichment_dry_run'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_single_enrichment_dry_run" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="minimal_response" value="1" />
            <input type="hidden" name="disable_debug_heavy_logs" value="1" />
            <input type="hidden" name="form_source" value="single_update_form" />
            <label for="single_product_id">product_id:</label>
            <input id="single_product_id" type="number" min="1" name="product_id" value="2081" />
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_apply_allegro_to_ovoko_details'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_apply_allegro_to_ovoko_details" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="form_source" value="single_update_form" />
            <label for="single_product_id_apply">product_id:</label>
            <input id="single_product_id_apply" type="number" min="1" name="product_id" value="2081" />
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <label><input type="checkbox" name="force_api_override" value="1" /> Force apply even when API test fails</label>
            <button class="button button-primary" type="submit" name="apply" value="1" <?php disabled($blockFullBulk); ?>>Apply update</button>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update product cards from CSV mapping</h3>
        <p>CSV maps part number to Ovoko part ID.</p>
        <?php if ($blockFullBulk): ?><div class="notice notice-warning"><p>Apply is blocked for low memory_limit. Use dry-run or increase to 256M.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="minimal_response" value="1" />
            <input type="hidden" name="disable_debug_heavy_logs" value="1" />
            <input type="hidden" name="form_source" value="batch_update_form" />
            <label for="bulk_product_ids_csv">product_ids_csv (optional):</label>
            <input id="bulk_product_ids_csv" type="text" class="regular-text" name="product_ids_csv" value="" />
            <label for="bulk_after_product_id">after_product_id:</label>
            <input id="bulk_after_product_id" type="number" min="0" name="after_product_id" value="0" />
            <label for="bulk_limit">limit:</label>
            <input id="bulk_limit" type="number" min="1" max="50" name="limit" value="3" />
            <label for="bulk_batch_size">batch_size:</label>
            <input id="bulk_batch_size" type="number" min="1" max="3" name="batch_size" value="2" />
            <label for="bulk_sleep_ms">sleep_ms:</label>
            <input id="bulk_sleep_ms" type="number" min="250" max="10000" step="250" value="1200" />
            <label for="bulk_limit_total">limit_total:</label>
            <input id="bulk_limit_total" type="number" min="0" step="1" value="0" />
            <label><input type="checkbox" id="bulk_stop_on_error" checked="checked" /> Stop on error</label>
            <label><input type="checkbox" id="bulk_skip_already_enriched" checked="checked" /> Skip already enriched</label>
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <label><input type="checkbox" id="bulk_apply_confirm" /> I understand this will update product details/meta for matching products.</label>
            <br><br>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run selected batch</button>
            <label><input type="checkbox" name="force_api_override" value="1" /> Force apply even when API test fails</label> <button class="button button-primary" type="submit" name="apply" value="1" <?php disabled($blockFullBulk); ?>>Apply batch</button>
            <button class="button button-secondary" type="button" id="gpswiss_autorun_start_dry_run">Start auto dry-run</button>
            <button class="button button-primary" type="button" id="gpswiss_autorun_start_apply" style="background:#b32d2e;border-color:#8f2223;color:#fff;">Start auto apply</button>
            <span style="display:inline-block;margin-left:8px;color:#b32d2e;font-weight:600;">Warning: apply mode writes product details/meta changes.</span>
            <button class="button" type="button" id="gpswiss_autorun_pause">Pause</button>
            <button class="button" type="button" id="gpswiss_autorun_resume">Resume</button>
            <button class="button" type="button" id="gpswiss_autorun_stop">Stop</button>
            <button class="button" type="button" id="gpswiss_autorun_download_jsonl">Download log JSONL</button>
            <button class="button" type="button" id="gpswiss_autorun_download_csv">Download log CSV</button>
        </form>
        <div id="gpswiss_autorun_status" style="margin-top:10px;padding:10px;background:#f6f7f7;">
            <strong>Status:</strong> <span data-k="status">idle</span> |
            <strong>mode:</strong> <span data-k="mode">dry_run</span> |
            <strong>last_after_product_id:</strong> <span data-k="last_after_product_id">0</span> |
            <strong>next_after_product_id:</strong> <span data-k="next_after_product_id">0</span> |
            <strong>total_processed:</strong> <span data-k="total_processed">0</span> |
            <strong>total_updated:</strong> <span data-k="total_updated">0</span> |
            <strong>total_skipped:</strong> <span data-k="total_skipped">0</span> |
            <strong>total_errors:</strong> <span data-k="total_errors">0</span> |
            <strong>batch_duration:</strong> <span data-k="batch_duration">0</span>s |
            <strong>memory_peak_mb:</strong> <span data-k="memory_peak_mb">0</span>
        </div>
        <pre id="gpswiss_autorun_logs" style="max-height:260px;overflow:auto;background:#111;color:#e6e6e6;padding:10px;"></pre>
    </div>
    <script>
document.addEventListener('DOMContentLoaded', function () {
  console.log('Ovoko auto-run JS loaded');
  const form = document.querySelector('form[action*="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment"]');
  if (!form) { return; }
  const $ = (id) => document.getElementById(id);
  const statusEl = $('gpswiss_autorun_status');
  const logsEl = $('gpswiss_autorun_logs');
  const stateKey = 'gpswiss_ovoko_autorun_state_v1';
  let st = {running:false,paused:false,stopped:false,status:'idle',mode:'dry_run',last_after_product_id:0,next_after_product_id:0,total_processed:0,total_updated:0,total_skipped:0,total_errors:0,batch_duration:0,memory_peak_mb:0,logs:[]};
  try { const raw = localStorage.getItem(stateKey); if (raw) { st = Object.assign(st, JSON.parse(raw)); } } catch (e) {}
  const save = ()=>{ try { localStorage.setItem(stateKey, JSON.stringify(st)); } catch (e) {} };
  const setK = (k,v)=>{ const n=statusEl.querySelector(`[data-k="${k}"]`); if(n){ n.textContent=String(v); } };
  const render = ()=>{ ['status','mode','last_after_product_id','next_after_product_id','total_processed','total_updated','total_skipped','total_errors','batch_duration','memory_peak_mb'].forEach(k=>setK(k, st[k] ?? 0)); logsEl.textContent=(st.logs||[]).slice(-30).map(x=>JSON.stringify(x)).join("\n"); };
  const pushLog=(o)=>{ st.logs.push(Object.assign({ts:new Date().toISOString()},o)); st.logs=st.logs.slice(-1000); save(); render(); };
  const failUi=(msg,details)=>{ st.running=false; st.paused=false; st.stopped=true; st.status='error'; pushLog({error:msg,details:details||''}); console.error(msg, details||''); };
  const getBatchSize=()=>{ const raw=parseInt(($('bulk_batch_size').value||'2'),10); const clamped=Math.max(1, Math.min(3, isNaN(raw)?2:raw)); if(String(clamped)!==String(raw)){ $('bulk_batch_size').value=String(clamped); pushLog({warning:'batch_size_clamped',requested:raw,used:clamped}); } return clamped; };
  const buildBody=(mode)=>{ const nonce=(form.querySelector('input[name="_wpnonce"]')||{}).value||''; const action='gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'; if(!window.ajaxurl || !nonce || !action){ failUi('Missing ajaxurl/nonce/action',{ajaxurl:window.ajaxurl||'',nonce_present:!!nonce,action}); return null; } const fd=new URLSearchParams(); fd.set('action',action); fd.set('_ajax_nonce',nonce); fd.set('form_source','batch_update_form'); fd.set('details_only','1'); fd.set('minimal_response','1'); fd.set('disable_debug_heavy_logs','1'); fd.set('batch_size', String(getBatchSize())); fd.set('limit', form.querySelector('[name="limit"]').value||'3'); fd.set('after_product_id', String(st.next_after_product_id||parseInt(form.querySelector('[name="after_product_id"]').value||'0',10))); fd.set('product_ids_csv', form.querySelector('[name="product_ids_csv"]').value||''); if($('bulk_skip_already_enriched').checked) fd.set('skip_already_enriched','1'); if(form.querySelector('[name="replace_description"]').checked) fd.set('replace_description','1'); if(mode==='apply'){ fd.set('dry_run','0'); fd.set('apply','1'); } else { fd.set('dry_run','1'); fd.set('apply','0'); } return fd; };
  const shouldStop=(res)=>{ const stopOnError=$('bulk_stop_on_error').checked; const hasSafety=(res.sample_results||[]).some(x=>x.error==='safety_violation'); if(stopOnError && ((parseInt(res.errors||0,10)>0)||hasSafety)) return 'stopped_on_error'; if(res.done) return 'done'; if(parseInt(res.processed||0,10)===0) return 'processed_zero'; if(!res.next_after_product_id) return 'missing_next_after_product_id'; return ''; };
  const tick=async()=>{ if(!st.running || st.paused || st.stopped){ return; } const started=Date.now(); const body=buildBody(st.mode); if(!body){ return; } let res; try { const httpRes=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}); const text=await httpRes.text(); try { res=JSON.parse(text); } catch (e) { failUi('AJAX returned non-JSON response', text); return; } if(!httpRes.ok || !res.ok){ failUi('AJAX request failed', res); return; } } catch (e) { failUi('AJAX transport error', String(e)); return; }
  st.batch_duration=((Date.now()-started)/1000).toFixed(2); st.last_after_product_id=parseInt(body.get('after_product_id')||'0',10); st.next_after_product_id=parseInt(res.next_after_product_id||0,10); st.total_processed+=parseInt(res.processed||0,10); st.total_updated+=parseInt(res.updated||0,10); st.total_skipped+=parseInt(res.skipped||0,10); st.total_errors+=parseInt(res.errors||0,10); st.memory_peak_mb=res.memory_peak_mb||0;
  (res.sample_results||[]).forEach((x)=>pushLog({product_id:x.product_id,action:x.action,error:x.error||'',matched_ovoko_part_id:x.matched_ovoko_part_id||'',matched_ovoko_car_id:x.matched_ovoko_car_id||'',vehicle_slug:x.vehicle_slug||'',attributes_count:x.attributes_count||x.would_write_attributes_count||0,would_write_attributes_count:x.would_write_attributes_count||0,no_price_change:!!x.no_price_change,no_stock_change:!!x.no_stock_change,no_images_change:!!x.no_images_change,no_title_change:!!x.no_title_change}));
  const limitTotal=parseInt($('bulk_limit_total').value||'0',10); if(limitTotal>0 && st.total_processed>=limitTotal){ st.running=false; st.status='stopped'; save(); render(); return; }
  const reason=shouldStop(res); if(reason==='done'){ st.running=false; st.status='done'; save(); render(); return; }
  if(reason){ st.running=false; st.status='stopped'; pushLog({warning:reason,payload:res}); save(); render(); return; }
  st.status='running'; save(); render(); setTimeout(tick, Math.max(250, parseInt($('bulk_sleep_ms').value||'1200',10)));
 };
  const startAutorun=(mode)=>{ if(mode==='apply' && !$('bulk_apply_confirm').checked){ alert('Confirm apply checkbox first.'); return; } st.mode=mode; st.running=true; st.paused=false; st.stopped=false; st.status='running'; st.logs=[]; st.total_processed=0; st.total_updated=0; st.total_skipped=0; st.total_errors=0; st.last_after_product_id=0; st.next_after_product_id=parseInt(form.querySelector('[name="after_product_id"]').value||'0',10); pushLog({info: mode==='apply' ? 'starting auto apply...' : 'starting auto dry-run...'}); save(); render(); tick(); };
  $('gpswiss_autorun_start_dry_run').addEventListener('click', (e)=>{ e.preventDefault(); startAutorun('dry_run'); });
  $('gpswiss_autorun_start_apply').addEventListener('click', (e)=>{ e.preventDefault(); startAutorun('apply'); });
  $('gpswiss_autorun_pause').addEventListener('click', ()=>{ st.paused=true; st.status='paused'; save(); render(); });
  $('gpswiss_autorun_resume').addEventListener('click', ()=>{ if(!st.running){ st.running=true; } st.paused=false; st.stopped=false; st.status='running'; save(); render(); tick(); });
  $('gpswiss_autorun_stop').addEventListener('click', ()=>{ st.running=false; st.stopped=true; st.status='stopped'; save(); render(); });
  const download=(name,blob)=>{ const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=name; a.click(); URL.revokeObjectURL(a.href); };
  $('gpswiss_autorun_download_jsonl').addEventListener('click', ()=> download('ovoko-autorun-log.jsonl', new Blob((st.logs||[]).map(x=>JSON.stringify(x)+"\n"),{type:'application/jsonl'})));
  $('gpswiss_autorun_download_csv').addEventListener('click', ()=>{ const rows=st.logs||[]; const keys=['ts','product_id','action','matched_ovoko_part_id','matched_ovoko_car_id','vehicle_slug','attributes_count','would_write_attributes_count','no_price_change','no_stock_change','no_images_change','no_title_change','error']; const csv=[keys.join(',')].concat(rows.map(r=>keys.map(k=>`"${String(r[k]??'').replace(/"/g,'""')}"`).join(','))).join('\n'); download('ovoko-autorun-log.csv', new Blob([csv],{type:'text/csv'})); });
  st.status = st.running ? (st.paused ? 'paused' : 'running') : (st.status || 'idle'); render();
});
</script>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>CSV mapping</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('gpswiss_ovoko_import_csv_mapping'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_import_csv_mapping" />
            <label for="csv_mapping_file">Upload/import CSV:</label>
            <input id="csv_mapping_file" type="file" name="csv_mapping_file" accept=".csv,text/csv" />
            <label for="csv_file_path">or local path:</label>
            <input id="csv_file_path" type="text" class="regular-text" name="csv_file_path" value="/workspace/sklep/parts-stock-2026-05-25.csv" />
            <?php submit_button('Upload/import CSV', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_import_csv_mapping'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_import_csv_mapping" />
            <input type="hidden" name="csv_file_path" value="/workspace/sklep/parts-stock-2026-05-25.csv" />
            <?php submit_button('Rebuild CSV index', 'secondary', 'submit', false); ?>
        </form>
        <ul>
            <li>rows_total: <code><?php echo esc_html((string) ($csvStatus['rows_total'] ?? '0')); ?></code></li>
            <li>unique_part_codes: <code><?php echo esc_html((string) ($csvStatus['unique_part_codes'] ?? '0')); ?></code></li>
            <li>duplicate_part_codes_count: <code><?php echo esc_html((string) ($csvStatus['duplicate_part_codes_count'] ?? '0')); ?></code></li>
            <li>detected delimiter: <code><?php echo esc_html((string) ($csvStatus['delimiter'] ?? 'n/a')); ?></code></li>
            <li>current CSV file name/date: <code><?php echo esc_html((string) (($csvStatus['file_name'] ?? 'n/a') . ' / ' . ($csvStatus['imported_at'] ?? 'n/a'))); ?></code></li>
        </ul>
    </div>

    <?php if ($showAdvancedTools): ?>
    <details style="margin-top:18px;"><summary><strong>Advanced / Diagnostics (developer tools)</strong></summary>
        <div class="postbox" style="padding:16px; margin-top:10px;">
            <p>Technical and legacy tools moved here.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                <?php wp_nonce_field('gpswiss_ovoko_bulk_diagnostics_ping'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bulk_diagnostics_ping" />
                <input type="text" class="regular-text" name="product_ids_csv" value="" placeholder="product_ids_csv" />
                <?php submit_button('Bulk diagnostics / ping', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_single_enrichment_dry_run'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_single_enrichment_dry_run" />
                <label>Product ID:</label><input type="number" min="1" name="product_id" value="2081" />
                <?php submit_button('Single enrichment dry-run (JSON)', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_preview_allegro_to_ovoko_match'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_preview_allegro_to_ovoko_match" />
                <label>Product ID:</label><input type="number" min="1" name="product_id" value="0" />
                <?php submit_button('Legacy preview match', 'secondary', 'submit', false); ?>
            </form>
        </div>
    </details>
    <?php endif; ?>
</div>
