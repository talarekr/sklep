document.documentElement.setAttribute('data-ovoko-autorun-js', 'loaded');
console.log('Ovoko auto-run JS loaded');

document.addEventListener('DOMContentLoaded', function () {
  const config = window.gpswissOvokoAutorunConfig || {};
  const form = document.querySelector('form[action*="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment"]');
  if (!form) {
    return;
  }

  const $ = function (id) { return document.getElementById(id); };
  const statusEl = $('gpswiss_autorun_status');
  const logsEl = $('gpswiss_autorun_logs');
  const jsLoadedEl = $('gpswiss_autorun_js_loaded');
  const stateKey = 'gpswiss_ovoko_autorun_state_v1';

  let st = {running:false,paused:false,stopped:false,status:'idle',mode:'dry_run',last_after_product_id:0,next_after_product_id:0,total_processed:0,total_updated:0,total_skipped:0,total_errors:0,batch_duration:0,memory_peak_mb:0,logs:[]};
  try { const raw = localStorage.getItem(stateKey); if (raw) { st = Object.assign(st, JSON.parse(raw)); } } catch (e) {}

  const save = function () { try { localStorage.setItem(stateKey, JSON.stringify(st)); } catch (e) {} };
  const setK = function (k, v) { const n = statusEl.querySelector('[data-k="' + k + '"]'); if (n) { n.textContent = String(v); } };
  const render = function () { ['status','mode','last_after_product_id','next_after_product_id','total_processed','total_updated','total_skipped','total_errors','batch_duration','memory_peak_mb'].forEach(function (k) { setK(k, st[k] || 0); }); logsEl.textContent = (st.logs || []).slice(-30).map(function (x) { return JSON.stringify(x); }).join("\n"); };
  const pushLog = function (o) { st.logs.push(Object.assign({ts:new Date().toISOString()}, o)); st.logs = st.logs.slice(-1000); save(); render(); };

  if (jsLoadedEl) {
    jsLoadedEl.textContent = 'yes';
  }

  const jsTestBtn = $('gpswiss_autorun_js_test');
  if (jsTestBtn) {
    jsTestBtn.addEventListener('click', function () {
      pushLog({info:'JS click test works'});
    });
  }

  const getBatchSize = function () {
    const raw = parseInt(($('bulk_batch_size').value || '2'), 10);
    const clamped = Math.max(1, Math.min(3, isNaN(raw) ? 2 : raw));
    if (String(clamped) !== String(raw)) { $('bulk_batch_size').value = String(clamped); pushLog({warning:'batch_size_clamped',requested:raw,used:clamped}); }
    return clamped;
  };

  const failUi = function (msg, details) { st.running=false; st.paused=false; st.stopped=true; st.status='error'; pushLog({error:msg,details:details||''}); console.error(msg, details||''); };
  const buildBody = function (mode) {
    const nonce = config.nonce || '';
    const action = config.action || '';
    const ajaxUrl = config.ajaxUrl || '';
    if (!ajaxUrl || !nonce || !action) { failUi('Missing ajaxUrl/nonce/action', {ajaxUrl:ajaxUrl,nonce_present:!!nonce,action:action}); return null; }
    const fd = new URLSearchParams();
    fd.set('action', action);
    fd.set('_ajax_nonce', nonce);
    fd.set('form_source', 'batch_update_form');
    fd.set('details_only', '1');
    fd.set('minimal_response', '1');
    fd.set('disable_debug_heavy_logs', '1');
    fd.set('batch_size', String(getBatchSize()));
    fd.set('limit', form.querySelector('[name="limit"]').value || '3');
    fd.set('after_product_id', String(st.next_after_product_id || parseInt(form.querySelector('[name="after_product_id"]').value || '0', 10)));
    fd.set('product_ids_csv', form.querySelector('[name="product_ids_csv"]').value || '');
    if ($('bulk_skip_already_enriched').checked) { fd.set('skip_already_enriched', '1'); }
    if (form.querySelector('[name="replace_description"]').checked) { fd.set('replace_description', '1'); }
    if (mode === 'apply') { fd.set('dry_run', '0'); fd.set('apply', '1'); } else { fd.set('dry_run', '1'); fd.set('apply', '0'); }
    return fd;
  };

  const shouldStop = function (res) { const stopOnError = $('bulk_stop_on_error').checked; const hasSafety = (res.sample_results||[]).some(function (x) { return x.error === 'safety_violation'; }); if (stopOnError && ((parseInt(res.errors||0,10)>0) || hasSafety)) return 'stopped_on_error'; if (res.done) return 'done'; if (parseInt(res.processed||0,10)===0) return 'processed_zero'; if (!res.next_after_product_id) return 'missing_next_after_product_id'; return ''; };
  const tick = async function () { if (!st.running || st.paused || st.stopped) { return; } const started = Date.now(); const body = buildBody(st.mode); if (!body) { return; } let res; try { const httpRes = await fetch(config.ajaxUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}); const text = await httpRes.text(); try { res = JSON.parse(text); } catch (e) { failUi('AJAX returned non-JSON response', text); return; } if (!httpRes.ok || !res.ok) { failUi('AJAX request failed', res); return; } } catch (e) { failUi('AJAX transport error', String(e)); return; }
    st.batch_duration=((Date.now()-started)/1000).toFixed(2); st.last_after_product_id=parseInt(body.get('after_product_id')||'0',10); st.next_after_product_id=parseInt(res.next_after_product_id||0,10); st.total_processed+=parseInt(res.processed||0,10); st.total_updated+=parseInt(res.updated||0,10); st.total_skipped+=parseInt(res.skipped||0,10); st.total_errors+=parseInt(res.errors||0,10); st.memory_peak_mb=res.memory_peak_mb||0;
    (res.sample_results||[]).forEach(function (x) { pushLog({product_id:x.product_id,action:x.action,error:x.error||'',matched_ovoko_part_id:x.matched_ovoko_part_id||'',matched_ovoko_car_id:x.matched_ovoko_car_id||'',vehicle_slug:x.vehicle_slug||'',attributes_count:x.attributes_count||x.would_write_attributes_count||0,would_write_attributes_count:x.would_write_attributes_count||0,no_price_change:!!x.no_price_change,no_stock_change:!!x.no_stock_change,no_images_change:!!x.no_images_change,no_title_change:!!x.no_title_change}); });
    const limitTotal = parseInt($('bulk_limit_total').value || '0', 10); if (limitTotal > 0 && st.total_processed >= limitTotal) { st.running=false; st.status='stopped'; save(); render(); return; }
    const reason = shouldStop(res); if (reason==='done') { st.running=false; st.status='done'; save(); render(); return; } if (reason) { st.running=false; st.status='stopped'; pushLog({warning:reason,payload:res}); save(); render(); return; }
    st.status='running'; save(); render(); setTimeout(tick, Math.max(250, parseInt($('bulk_sleep_ms').value || '1200', 10)));
  };

  const startAutorun = function (mode) { if (mode === 'apply' && !$('bulk_apply_confirm').checked) { alert('Confirm apply checkbox first.'); return; } st.mode=mode; st.running=true; st.paused=false; st.stopped=false; st.status='running'; st.logs=[]; st.total_processed=0; st.total_updated=0; st.total_skipped=0; st.total_errors=0; st.last_after_product_id=0; st.next_after_product_id=parseInt(form.querySelector('[name="after_product_id"]').value||'0',10); pushLog({info: mode==='apply' ? 'starting auto apply...' : 'starting auto dry-run...'}); save(); render(); tick(); };
  const pauseAutorun = function () { st.paused=true; st.status='paused'; save(); render(); };
  const resumeAutorun = function () { if (!st.running) { st.running=true; } st.paused=false; st.stopped=false; st.status='running'; save(); render(); tick(); };
  const stopAutorun = function () { st.running=false; st.stopped=true; st.status='stopped'; save(); render(); };

  window.gpswissOvokoStartAutorun = startAutorun;
  window.gpswissOvokoPauseAutorun = pauseAutorun;
  window.gpswissOvokoResumeAutorun = resumeAutorun;
  window.gpswissOvokoStopAutorun = stopAutorun;

  const startDryBtn = $('gpswiss_autorun_start_dry_run');
  const startApplyBtn = $('gpswiss_autorun_start_apply');
  const pauseBtn = $('gpswiss_autorun_pause');
  const resumeBtn = $('gpswiss_autorun_resume');
  const stopBtn = $('gpswiss_autorun_stop');
  if (startDryBtn) { startDryBtn.addEventListener('click', function (e) { e.preventDefault(); startAutorun('dry_run'); }); }
  if (startApplyBtn) { startApplyBtn.addEventListener('click', function (e) { e.preventDefault(); startAutorun('apply'); }); }
  if (pauseBtn) { pauseBtn.addEventListener('click', pauseAutorun); }
  if (resumeBtn) { resumeBtn.addEventListener('click', resumeAutorun); }
  if (stopBtn) { stopBtn.addEventListener('click', stopAutorun); }

  const download = function (name, blob) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.click(); URL.revokeObjectURL(a.href); };
  $('gpswiss_autorun_download_jsonl').addEventListener('click', function () { download('ovoko-autorun-log.jsonl', new Blob((st.logs||[]).map(function (x) { return JSON.stringify(x) + "\n"; }), {type:'application/jsonl'})); });
  $('gpswiss_autorun_download_csv').addEventListener('click', function () { const rows=st.logs||[]; const keys=['ts','product_id','action','matched_ovoko_part_id','matched_ovoko_car_id','vehicle_slug','attributes_count','would_write_attributes_count','no_price_change','no_stock_change','no_images_change','no_title_change','error']; const csv=[keys.join(',')].concat(rows.map(function (r) { return keys.map(function (k) { return '"' + String(r[k] || '').replace(/"/g, '""') + '"'; }).join(','); })).join("\n"); download('ovoko-autorun-log.csv', new Blob([csv], {type:'text/csv'})); });

  st.status = st.running ? (st.paused ? 'paused' : 'running') : (st.status || 'idle');
  render();
});
