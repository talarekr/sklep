document.documentElement.setAttribute('data-ovoko-autorun-js', 'loaded');

document.addEventListener('DOMContentLoaded', function () {
  const config = window.gpswissOvokoAutorunConfig || {};
  const $ = function (id) { return document.getElementById(id); };
  const jsLoadedEl = $('gpswiss_autorun_js_loaded');
  if (jsLoadedEl) { jsLoadedEl.textContent = 'yes'; }

  const form = $('gpswiss_ovoko_batch_update_form');
  if (!form) { return; }

  const statusEl = $('gpswiss_autorun_status');
  const logsEl = $('gpswiss_autorun_logs');
  const stateKey = 'gpswiss_ovoko_autorun_state_v2';
  const MAX_LOCAL_LOGS = 200;
  const MAX_RENDER_LOGS = 30;
  let fullLog = [];

  const defaultState = {
    running: false, paused: false, stopped: false, status: 'idle', mode: 'dry_run', run_id: '',
    started_at: '', finished_at: '', duration_seconds: 0,
    start_after_product_id: 0, last_after_product_id: 0, next_after_product_id: 0,
    total_scanned: 0, total_processed: 0, total_updated: 0, total_skipped: 0, total_errors: 0,
    total_safety_violations: 0, total_no_csv_match: 0, total_ambiguous_csv_match: 0,
    total_already_enriched_skipped: 0, total_not_allegro_product: 0, total_api_error: 0,
    total_memory_guard: 0, total_other_error: 0, total_csv_matched: 0,
    batch_duration: 0, memory_peak_mb: 0, logs: [], localstorage_warning: ''
  };
  let st = Object.assign({}, defaultState);

  try {
    const raw = localStorage.getItem(stateKey);
    if (raw) { st = Object.assign({}, defaultState, JSON.parse(raw)); }
  } catch (e) { st.localstorage_warning = 'Failed to load localStorage state'; }

  const safeSave = function () {
    st.logs = (fullLog.slice(-MAX_LOCAL_LOGS));
    try {
      localStorage.setItem(stateKey, JSON.stringify(st));
      st.localstorage_warning = '';
      return true;
    } catch (e) {
      st.localstorage_warning = 'localStorage limit reached; keeping full log in-memory only for this session.';
      return false;
    }
  };

  const setK = function (k, v) { const n = statusEl ? statusEl.querySelector('[data-k="' + k + '"]') : null; if (n) { n.textContent = String(v); } };
  const render = function () {
    const keys = ['status','mode','started_at','finished_at','duration_seconds','start_after_product_id','last_after_product_id','next_after_product_id','total_scanned','total_processed','total_updated','total_skipped','total_errors','total_csv_matched','total_no_csv_match','total_ambiguous_csv_match','total_already_enriched_skipped','total_not_allegro_product','total_safety_violations','total_api_error','total_memory_guard','total_other_error','batch_duration','memory_peak_mb'];
    keys.forEach(function (k) { setK(k, st[k] || 0); });
    setK('localstorage_warning', st.localstorage_warning || '');
    if (logsEl) { logsEl.textContent = fullLog.slice(-MAX_RENDER_LOGS).map(function (x) { return JSON.stringify(x); }).join('\n'); }
  };

  const nowIso = function () { return new Date().toISOString(); };
  const updateDuration = function () {
    if (!st.started_at) { st.duration_seconds = 0; return; }
    const end = st.finished_at ? Date.parse(st.finished_at) : Date.now();
    const start = Date.parse(st.started_at);
    st.duration_seconds = Math.max(0, Math.round((end - start) / 1000));
  };

  const pushLog = function (o) {
    fullLog.push(o);
    safeSave();
    render();
  };

  const logProduct = function (row, reqAfter, resNext) {
    pushLog({
      product_id: row.product_id || 0,
      action: row.action || '',
      skip_reason: row.skip_reason || '',
      error: row.error || '',
      matched_ovoko_part_id: row.matched_ovoko_part_id || '',
      matched_ovoko_car_id: row.matched_ovoko_car_id || '',
      vehicle_label: row.vehicle_label || '',
      vehicle_slug: row.vehicle_slug || '',
      vehicle_parts_url: row.vehicle_parts_url || '',
      attributes_count: row.attributes_count || row.would_write_attributes_count || 0,
      no_price_change: !!row.no_price_change,
      no_stock_change: !!row.no_stock_change,
      no_images_change: !!row.no_images_change,
      no_title_change: !!row.no_title_change,
      no_status_change: !!row.no_status_change,
      no_ebay_publish: !!row.no_ebay_publish,
      no_allegro_publish: !!row.no_allegro_publish,
      no_batch: !!row.no_batch,
      timestamp: nowIso(),
      run_id: st.run_id,
      request_after_product_id: reqAfter,
      response_next_after_product_id: resNext,
      current_part_number: row.current_part_number || '',
      product_title: row.product_title || ''
    });
  };

  const reasonToAction = function (reason, err) {
    if (reason === 'no_csv_match') return 'check part number / add CSV mapping';
    if (reason === 'ambiguous_csv_match') return 'review duplicate CSV codes / choose correct Ovoko ID';
    if (reason === 'api_error') return 'retry / check Ovoko API';
    if (err === 'safety_violation') return 'manual review before retry';
    if (reason === 'already_enriched_skipped') return 'no action';
    if (reason === 'not_allegro_product') return 'ignore unless should be matched';
    if ((reason === 'memory_guard') || (err === 'memory_guard')) return 'retry smaller batch / check memory';
    return 'manual review';
  };

  const getBatchSize = function () { return Math.max(1, Math.min(3, parseInt(($('bulk_batch_size').value || '2'), 10) || 2)); };
  const buildBody = function (mode) {
    const fd = new URLSearchParams();
    fd.set('action', config.action || ''); fd.set('_ajax_nonce', config.nonce || '');
    fd.set('form_source', 'batch_update_form'); fd.set('details_only', '1'); fd.set('minimal_response', '1'); fd.set('disable_debug_heavy_logs', '1');
    fd.set('batch_size', String(getBatchSize())); fd.set('limit', form.querySelector('[name="limit"]').value || '3');
    fd.set('after_product_id', String(st.next_after_product_id || parseInt(form.querySelector('[name="after_product_id"]').value || '0', 10)));
    fd.set('product_ids_csv', form.querySelector('[name="product_ids_csv"]').value || '');
    if ($('bulk_skip_already_enriched').checked) { fd.set('skip_already_enriched', '1'); }
    if (form.querySelector('[name="replace_description"]').checked) { fd.set('replace_description', '1'); }
    fd.set('dry_run', mode === 'apply' ? '0' : '1'); fd.set('apply', mode === 'apply' ? '1' : '0');
    return fd;
  };

  let requestInFlight = false; let pendingTimer = null;
  const scheduleNextTick = function () { if (!st.running || st.paused || st.stopped) return; pendingTimer = setTimeout(tick, Math.max(250, parseInt($('bulk_sleep_ms').value || '1200', 10))); };

  const tick = async function () {
    if (!st.running || st.paused || st.stopped || requestInFlight) return;
    requestInFlight = true;
    const startedMs = Date.now();
    const body = buildBody(st.mode);
    body.set('autorun_run_id', st.run_id);
    let res;
    try {
      const r = await fetch(config.ajaxUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()});
      res = JSON.parse(await r.text());
      if (!r.ok || !res.ok) throw new Error('ajax_failed');
    } catch (e) {
      st.running = false; st.status = 'error'; st.finished_at = nowIso(); updateDuration(); pushLog({timestamp: nowIso(), run_id: st.run_id, action: 'error', error: 'ajax_error'});
      requestInFlight = false; return;
    }
    requestInFlight = false;
    st.batch_duration = ((Date.now() - startedMs) / 1000).toFixed(2);
    const reqAfter = parseInt(body.get('after_product_id') || '0', 10);
    st.last_after_product_id = reqAfter;
    st.next_after_product_id = parseInt(res.next_after_product_id || 0, 10);
    st.total_scanned += parseInt(res.total_scanned || 0, 10);
    st.total_processed += parseInt(res.processed || 0, 10);
    st.total_updated += parseInt(res.updated || 0, 10);
    st.total_skipped += parseInt(res.skipped || 0, 10);
    st.total_errors += parseInt(res.errors || 0, 10);
    st.memory_peak_mb = res.memory_peak_mb || 0;

    const c = res.counts_by_reason || {};
    st.total_no_csv_match += parseInt(c.no_csv_match || 0, 10);
    st.total_ambiguous_csv_match += parseInt(c.ambiguous_csv_match || 0, 10);
    st.total_already_enriched_skipped += parseInt(c.already_enriched_skipped || 0, 10);
    st.total_not_allegro_product += parseInt(c.not_allegro_product || 0, 10);
    st.total_safety_violations += parseInt(c.safety_violation || 0, 10);
    st.total_api_error += parseInt(c.api_error || 0, 10);
    st.total_memory_guard += parseInt(c.memory_guard || 0, 10);
    st.total_other_error += parseInt(c.error || 0, 10);
    st.total_csv_matched += parseInt(c.updated || 0, 10) + parseInt(c.dry_run || 0, 10) + parseInt(c.api_error || 0, 10) + parseInt(c.safety_violation || 0, 10) + parseInt(c.error || 0, 10);

    (res.sample_results || []).forEach(function (x) { logProduct(x, reqAfter, st.next_after_product_id); });

    const stopOnError = $('bulk_stop_on_error').checked;
    const shouldStop = (stopOnError && parseInt(res.errors || 0, 10) > 0) || res.done || parseInt(res.processed || 0, 10) === 0 || !res.next_after_product_id;
    if (shouldStop) { st.running = false; st.status = res.done ? 'done' : 'stopped'; st.finished_at = nowIso(); }
    updateDuration(); safeSave(); render();
    if (!shouldStop) scheduleNextTick();
  };

  const resetRunCounters = function () {
    Object.assign(st, defaultState, {mode: st.mode, next_after_product_id: st.next_after_product_id});
  };

  const startAutorun = function (mode) {
    if (mode === 'apply' && !$('bulk_apply_confirm').checked) { alert('Confirm apply checkbox first.'); return; }
    fullLog = [];
    resetRunCounters();
    st.mode = mode; st.running = true; st.status = 'running'; st.run_id = 'run_' + Date.now(); st.started_at = nowIso(); st.start_after_product_id = parseInt(form.querySelector('[name="after_product_id"]').value || '0', 10); st.next_after_product_id = st.start_after_product_id;
    safeSave(); render(); tick();
  };

  const pauseAutorun = function () { if (pendingTimer) clearTimeout(pendingTimer); st.paused = true; st.status = 'paused'; updateDuration(); safeSave(); render(); };
  const resumeAutorun = function () { st.paused = false; st.running = true; st.status = 'running'; safeSave(); render(); tick(); };
  const stopAutorun = function () { if (pendingTimer) clearTimeout(pendingTimer); st.running = false; st.stopped = true; st.status = 'stopped'; st.finished_at = nowIso(); updateDuration(); safeSave(); render(); };
  const resetAutorunState = function () { if (pendingTimer) clearTimeout(pendingTimer); st = Object.assign({}, defaultState); fullLog = []; try { localStorage.removeItem(stateKey); } catch (e) {} safeSave(); render(); };

  const toCsv = function (rows, keys) { return [keys.join(',')].concat(rows.map(function (r) { return keys.map(function (k) { return '"' + String(r[k] || '').replace(/"/g, '""') + '"'; }).join(','); })).join('\n'); };
  const download = function (name, blob) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.click(); URL.revokeObjectURL(a.href); };

  $('gpswiss_autorun_start_dry_run')?.addEventListener('click', function (e) { e.preventDefault(); startAutorun('dry_run'); });
  $('gpswiss_autorun_start_apply')?.addEventListener('click', function (e) { e.preventDefault(); startAutorun('apply'); });
  $('gpswiss_autorun_pause')?.addEventListener('click', pauseAutorun);
  $('gpswiss_autorun_resume')?.addEventListener('click', resumeAutorun);
  $('gpswiss_autorun_stop')?.addEventListener('click', stopAutorun);
  $('gpswiss_autorun_reset_state')?.addEventListener('click', resetAutorunState);

  $('gpswiss_autorun_download_jsonl')?.addEventListener('click', function () { download('ovoko-autorun-log.jsonl', new Blob(fullLog.map(function (x) { return JSON.stringify(x) + '\n'; }), {type:'application/jsonl'})); });
  $('gpswiss_autorun_download_csv')?.addEventListener('click', function () { const keys=['timestamp','run_id','product_id','action','skip_reason','error','matched_ovoko_part_id','matched_ovoko_car_id','vehicle_label','vehicle_slug','vehicle_parts_url','attributes_count','no_price_change','no_stock_change','no_images_change','no_title_change','no_status_change','no_ebay_publish','no_allegro_publish','no_batch','request_after_product_id','response_next_after_product_id']; download('ovoko-autorun-log.csv', new Blob([toCsv(fullLog, keys)], {type:'text/csv'})); });
  $('gpswiss_autorun_download_skipped_errors_csv')?.addEventListener('click', function () {
    const rows = fullLog.filter(function (r) { return (r.action === 'skipped' || r.action === 'error' || r.error); }).map(function (r) { return {product_id:r.product_id,skip_reason:r.skip_reason,error:r.error,matched_ovoko_part_id:r.matched_ovoko_part_id,matched_ovoko_car_id:r.matched_ovoko_car_id,current_part_number:r.current_part_number || '',product_title:r.product_title || '',suggested_action:reasonToAction(r.skip_reason,r.error)}; });
    const keys=['product_id','skip_reason','error','matched_ovoko_part_id','matched_ovoko_car_id','current_part_number','product_title','suggested_action'];
    download('ovoko-autorun-skipped-errors.csv', new Blob([toCsv(rows, keys)], {type:'text/csv'}));
  });
  $('gpswiss_autorun_download_and_clear')?.addEventListener('click', function () { download('ovoko-autorun-log.jsonl', new Blob(fullLog.map(function (x) { return JSON.stringify(x) + '\n'; }), {type:'application/jsonl'})); resetAutorunState(); });

  render();

  const descForm = $('gpswiss_ovoko_description_update_form');
  const descStatusEl = $('gpswiss_desc_autorun_status');
  const descLogsEl = $('gpswiss_desc_autorun_logs');
  if (!descForm || !descStatusEl || !descLogsEl) { return; }

  const descState = { running: false, status: 'stopped', started_at: '', duration_seconds: 0, start_after_product_id: 0, request_after_product_id: 0, response_next_after_product_id: 0, current_after_product_id: 0, last_next_after_product_id: 0, last_safe_next_after_product_id: 0, total_scanned: 0, total_with_ovoko_id: 0, total_updated: 0, total_old_allegro_removed: 0, total_missing_ovoko_id: 0, total_listing_missing: 0, total_errors: 0, desc_after_product_id_element_found: false, desc_after_product_id_raw_value: '""', parsed_start_after_product_id: 0, admin_autorun_js_url: '', admin_autorun_js_version: '', descriptionAction: '', descriptionNonce_present: false, js_asset_version: '' };
  let descTimer = null;
  let descInFlight = false;
  const descLogRows = [];

  const descRender = function () {
    ['status','started_at','duration_seconds','start_after_product_id','request_after_product_id','response_next_after_product_id','current_after_product_id','last_next_after_product_id','last_safe_next_after_product_id','total_scanned','total_with_ovoko_id','total_updated','total_old_allegro_removed','total_missing_ovoko_id','total_listing_missing','total_errors','desc_after_product_id_element_found','desc_after_product_id_raw_value','parsed_start_after_product_id','admin_autorun_js_url','admin_autorun_js_version','descriptionAction','descriptionNonce_present','js_asset_version']
      .forEach(function (k) {
        const n = descStatusEl.querySelector('[data-k="' + k + '"]');
        if (n) { n.textContent = String(descState[k] || 0); }
      });
    descLogsEl.textContent = descLogRows.slice(-30).map(function (x) { return JSON.stringify(x); }).join('\n');
  };
  const descUpdateDuration = function () {
    if (!descState.started_at) { descState.duration_seconds = 0; return; }
    descState.duration_seconds = Math.max(0, Math.round((Date.now() - Date.parse(descState.started_at)) / 1000));
  };
  const getFieldNumber = function (name, fallback) {
    const el = descForm.querySelector('#desc_' + name) || descForm.querySelector('[name="' + name + '"]');
    return Math.max(0, parseInt((el && el.value) || String(fallback || 0), 10) || 0);
  };
  const getDescAfterProductIdDebug = function () {
    const el = document.getElementById('desc_after_product_id');
    const found = !!el;
    const rawValue = found ? String(el.value || '') : '';
    const parsedValue = Math.max(0, parseInt(rawValue || '0', 10) || 0);
    return { el: el, found: found, rawValue: rawValue, parsedValue: parsedValue };
  };
  const getStopOnError = function () { return !!descForm.querySelector('[name="stop_on_error"]')?.checked; };
  const getSleepMs = function () { return Math.max(100, getFieldNumber('sleep_ms', 1200)); };
  const getMaxRuntime = function () { return getFieldNumber('max_runtime', 0); };

  const descBuildBody = function () {
    const fd = new URLSearchParams();
    fd.set('action', config.descriptionAction || 'gpswiss_ovoko_update_description_from_listing_text');
    fd.set('_ajax_nonce', config.descriptionNonce || '');
    fd.set('after_product_id', String(descState.current_after_product_id));
    fd.set('limit', String(getFieldNumber('limit', 1)));
    fd.set('batch_size', String(getFieldNumber('batch_size', 1)));
    fd.set('dry_run', '0');
    fd.set('save_to_meta_only', '0');
    fd.set('update_only_empty_description', '0');
    fd.set('replace_existing_description', '1');
    fd.set('prepend_to_existing_description', '0');
    fd.set('stop_on_error', '1');
    return fd;
  };

  const descStop = function (status) {
    if (descTimer) clearTimeout(descTimer);
    descState.running = false;
    descState.status = status || 'stopped';
    descUpdateDuration();
    descRender();
  };

  const descTick = async function () {
    if (!descState.running || descInFlight) return;
    if (getMaxRuntime() > 0 && descState.duration_seconds >= getMaxRuntime()) { descStop('stopped'); return; }
    descInFlight = true;
    const body = descBuildBody();
    let res;
    try {
      const r = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      res = JSON.parse(await r.text());
      if (!r.ok) throw new Error('network');
    } catch (e) {
      descLogRows.push({ timestamp: nowIso(), type: 'ajax_error', last_after_product_id: descState.current_after_product_id, last_next_after_product_id: descState.last_next_after_product_id });
      descInFlight = false;
      descStop('error');
      return;
    }
    descInFlight = false;
    const counts = res.counts || {};
    const reqAfter = descState.current_after_product_id;
    const nextAfter = parseInt(res.next_after_product_id || 0, 10);
    descState.request_after_product_id = reqAfter;
    descState.response_next_after_product_id = nextAfter;
    descState.total_scanned += parseInt(counts.total_scanned || 0, 10);
    descState.total_with_ovoko_id += parseInt(counts.with_ovoko_id || 0, 10);
    descState.total_updated += parseInt(counts.description_updated || 0, 10);
    descState.total_old_allegro_removed += parseInt(counts.old_allegro_description_removed || 0, 10);
    descState.total_missing_ovoko_id += parseInt(counts.missing_ovoko_id || 0, 10);
    descState.total_listing_missing += parseInt(counts.ovoko_listing_text_missing || 0, 10);
    descState.total_errors += parseInt(counts.errors || 0, 10);
    descState.last_next_after_product_id = nextAfter;
    if (nextAfter > 0) descState.last_safe_next_after_product_id = nextAfter;
    descLogRows.push({ timestamp: nowIso(), form_after_product_id_at_start: descState.start_after_product_id, request_after_product_id: reqAfter, response_next_after_product_id: nextAfter, ok: !!res.ok, done: !!res.done, counts: counts, results: res.results || [] });

    const hardStop = !res.ok || parseInt(counts.errors || 0, 10) > 0;
    const doneStop = !!res.done || !nextAfter || nextAfter <= reqAfter || !((res.results || []).length);
    if (hardStop && getStopOnError()) { descState.status = 'error'; descStop('error'); return; }
    if (doneStop) { descStop('done'); return; }
    descState.current_after_product_id = nextAfter;
    descUpdateDuration();
    descRender();
    descTimer = setTimeout(descTick, getSleepMs());
  };

  $('gpswiss_desc_autorun_start')?.addEventListener('click', function (e) {
    e.preventDefault();
    if (descState.running) return;
    descState.running = true;
    descState.status = 'running';
    descState.started_at = nowIso();
    descState.duration_seconds = 0;
    const descAfterIdDebug = getDescAfterProductIdDebug();
    descState.desc_after_product_id_element_found = descAfterIdDebug.found;
    descState.desc_after_product_id_raw_value = '"' + descAfterIdDebug.rawValue + '"';
    descState.parsed_start_after_product_id = descAfterIdDebug.parsedValue;
    descState.admin_autorun_js_url = String(config.adminAutorunJsUrl || '');
    descState.admin_autorun_js_version = String(config.adminAutorunJsVersion || '');
    descState.js_asset_version = String(config.adminAutorunJsVersion || '');
    descState.descriptionAction = String(config.descriptionAction || '');
    descState.descriptionNonce_present = !!(config.descriptionNonce && String(config.descriptionNonce).length > 0);
    if (!descAfterIdDebug.found) {
      descLogRows.length = 0;
      descLogRows.push({ timestamp: nowIso(), type: 'startup_error', message: 'desc_after_product_id input not found. Aborting auto-run.' });
      descState.running = false;
      descState.status = 'error';
      descRender();
      return;
    }
    const formAfterProductId = descAfterIdDebug.parsedValue;
    descState.start_after_product_id = formAfterProductId;
    descState.request_after_product_id = formAfterProductId;
    descState.response_next_after_product_id = formAfterProductId;
    descState.current_after_product_id = formAfterProductId;
    descState.last_next_after_product_id = 0;
    descState.last_safe_next_after_product_id = 0;
    descState.total_scanned = 0; descState.total_with_ovoko_id = 0; descState.total_updated = 0; descState.total_old_allegro_removed = 0; descState.total_missing_ovoko_id = 0; descState.total_listing_missing = 0; descState.total_errors = 0;
    descLogRows.length = 0;
    descRender();
    descTick();
  });
  $('gpswiss_desc_autorun_stop')?.addEventListener('click', function (e) { e.preventDefault(); descStop('stopped'); });

  const catForm = $('gpswiss_ovoko_categories_update_form');
  const catStatusEl = $('gpswiss_cat_autorun_status');
  const catLogsEl = $('gpswiss_cat_autorun_logs');
  if (!catForm || !catStatusEl || !catLogsEl) { return; }
  const catState = { running: false, status: 'stopped', started_at: '', duration_seconds: 0, start_after_product_id: 0, request_after_product_id: 0, response_next_after_product_id: 0, current_after_product_id: 0, last_safe_next_after_product_id: 0, total_scanned: 0, with_ovoko_id: 0, missing_ovoko_id: 0, ovoko_category_found: 0, ovoko_category_missing: 0, categories_created: 0, categories_existing: 0, products_categories_updated: 0, products_categories_verified: 0, products_skipped: 0, errors: 0, category_after_product_id_element_found: false, category_after_product_id_raw_value: '""', parsed_start_after_product_id: 0, js_asset_version: '', admin_autorun_js_url: '', admin_autorun_js_version: '', categoryAction: '', categoryNonce_present: false };
  let catTimer = null; let catInFlight = false; const catLogRows = [];
  const catRender = function () {
    ['status','started_at','duration_seconds','start_after_product_id','request_after_product_id','response_next_after_product_id','current_after_product_id','last_safe_next_after_product_id','total_scanned','with_ovoko_id','missing_ovoko_id','ovoko_category_found','ovoko_category_missing','categories_created','categories_existing','products_categories_updated','products_categories_verified','products_skipped','errors','category_after_product_id_element_found','category_after_product_id_raw_value','parsed_start_after_product_id','js_asset_version','admin_autorun_js_url','admin_autorun_js_version','categoryAction','categoryNonce_present'].forEach(function (k) { const n = catStatusEl.querySelector('[data-k="' + k + '"]'); if (n) n.textContent = String(catState[k] || 0); });
    catLogsEl.textContent = catLogRows.slice(-30).map(function (x) { return JSON.stringify(x); }).join('\n');
  };
  const catUpdateDuration = function () { if (!catState.started_at) { catState.duration_seconds = 0; return; } catState.duration_seconds = Math.max(0, Math.round((Date.now() - Date.parse(catState.started_at)) / 1000)); };
  const catAfterDebug = function () { const el = $('category_after_product_id'); const raw = el ? String(el.value || '') : ''; return { found: !!el, rawValue: raw, parsedValue: Math.max(0, parseInt(raw || '0', 10) || 0) }; };
  const catField = function (id, fallback) { const el = $(id); return Math.max(0, parseInt((el && el.value) || String(fallback || 0), 10) || 0); };
  const catSleepMs = function () { return Math.max(100, catField('category_sleep_ms', 1200)); };
  const catMaxRuntime = function () { return catField('category_max_runtime', 0); };
  const catBuildBody = function () {
    const fd = new URLSearchParams();
    fd.set('action', config.categoryAction || 'gpswiss_ovoko_update_categories_from_ovoko');
    fd.set('_ajax_nonce', config.categoryNonce || '');
    fd.set('dry_run', '0');
    fd.set('create_missing_categories', '1');
    fd.set('replace_existing_categories', '1');
    fd.set('stop_on_error', '1');
    fd.set('product_id', '');
    fd.set('after_product_id', String(catState.current_after_product_id));
    fd.set('limit', String(catField('category_limit', 10)));
    fd.set('batch_size', String(catField('category_batch_size', 10)));
    return fd;
  };
  const catStop = function (status) { if (catTimer) clearTimeout(catTimer); catState.running = false; catState.status = status || 'stopped'; catUpdateDuration(); catRender(); };
  const catTick = async function () {
    if (!catState.running || catInFlight) return;
    catUpdateDuration();
    if (catMaxRuntime() > 0 && catState.duration_seconds >= catMaxRuntime()) { catStop('max_runtime_exceeded'); return; }
    catInFlight = true;
    const reqAfter = catState.current_after_product_id;
    let res;
    try {
      const r = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: catBuildBody().toString() });
      res = JSON.parse(await r.text());
      if (!r.ok) throw new Error('network');
    } catch (e) {
      catLogRows.push({ timestamp: nowIso(), type: 'ajax_error', request_after_product_id: reqAfter, error: String(e && e.message || 'network') });
      catInFlight = false; catStop('ajax_error'); return;
    }
    catInFlight = false;
    const counts = res.counts || {};
    const nextAfter = parseInt(res.next_after_product_id || 0, 10);
    catState.request_after_product_id = reqAfter;
    catState.response_next_after_product_id = nextAfter;
    catState.total_scanned += parseInt(counts.total_scanned || 0, 10);
    catState.with_ovoko_id += parseInt(counts.with_ovoko_id || 0, 10);
    catState.missing_ovoko_id += parseInt(counts.missing_ovoko_id || 0, 10);
    catState.ovoko_category_found += parseInt(counts.ovoko_category_found || 0, 10);
    catState.ovoko_category_missing += parseInt(counts.ovoko_category_missing || 0, 10);
    catState.categories_created += parseInt(counts.categories_created || 0, 10);
    catState.categories_existing += parseInt(counts.categories_existing || 0, 10);
    catState.products_categories_updated += parseInt(counts.products_categories_updated || 0, 10);
    catState.products_categories_verified += parseInt(counts.products_categories_verified || 0, 10);
    catState.products_skipped += parseInt(counts.products_skipped || 0, 10);
    catState.errors += parseInt(counts.errors || 0, 10);
    if (nextAfter > 0) catState.last_safe_next_after_product_id = nextAfter;
    const riskyConfidence = (res.results || []).some(function (r) { return (parseInt(r.ovoko_id || 0, 10) > 0) && String(r.category_resolution_confidence || '') !== 'high'; });
    catLogRows.push({ timestamp: nowIso(), request_after_product_id: reqAfter, response_next_after_product_id: nextAfter, counts: counts, product_issues: (res.results || []).filter(function (r) { return r.error || r.skip_reason; }).map(function (r) { return { product_id: r.product_id, error: r.error || '', skip_reason: r.skip_reason || '' }; }) });
    if (!res.ok || catState.errors > 0 || parseInt(counts.ovoko_category_missing || 0, 10) > 0 || riskyConfidence) { catStop('error'); return; }
    if (res.done || !nextAfter || nextAfter <= reqAfter) { catStop('done'); return; }
    catState.current_after_product_id = nextAfter;
    catUpdateDuration(); catRender();
    catTimer = setTimeout(catTick, catSleepMs());
  };
  $('gpswiss_cat_autorun_start')?.addEventListener('click', function (e) {
    e.preventDefault();
    if (catState.running) return;
    const dbg = catAfterDebug();
    catState.category_after_product_id_element_found = dbg.found;
    catState.category_after_product_id_raw_value = '"' + dbg.rawValue + '"';
    catState.parsed_start_after_product_id = dbg.parsedValue;
    catState.admin_autorun_js_url = String(config.adminAutorunJsUrl || '');
    catState.admin_autorun_js_version = String(config.adminAutorunJsVersion || '');
    catState.js_asset_version = String(config.adminAutorunJsVersion || '');
    catState.categoryAction = String(config.categoryAction || '');
    catState.categoryNonce_present = !!(config.categoryNonce && String(config.categoryNonce).length > 0);
    if (!dbg.found) { catState.status = 'error'; catLogRows.push({ timestamp: nowIso(), type: 'startup_error', message: 'category_after_product_id input not found' }); catRender(); return; }
    catState.running = true; catState.status = 'running'; catState.started_at = nowIso(); catState.duration_seconds = 0;
    catState.start_after_product_id = dbg.parsedValue; catState.request_after_product_id = dbg.parsedValue; catState.response_next_after_product_id = dbg.parsedValue; catState.current_after_product_id = dbg.parsedValue; catState.last_safe_next_after_product_id = 0;
    ['total_scanned','with_ovoko_id','missing_ovoko_id','ovoko_category_found','ovoko_category_missing','categories_created','categories_existing','products_categories_updated','products_categories_verified','products_skipped','errors'].forEach(function (k) { catState[k] = 0; });
    catLogRows.length = 0; catRender(); catTick();
  });
  $('gpswiss_cat_autorun_stop')?.addEventListener('click', function (e) { e.preventDefault(); catStop('stopped'); });

  const rebuildBox = $('gpswiss_category_rebuild_autorun_box');
  const rebuildStatusEl = $('gpswiss_rebuild_autorun_status');
  const rebuildLogsEl = $('gpswiss_rebuild_autorun_logs');
  if (rebuildBox && rebuildStatusEl && rebuildLogsEl) {
    let rebuildTimer = null;
    let rebuildInFlight = false;
    const rebuildLogs = [];
    const rebuildSetK = function (k, v) {
      const n = rebuildStatusEl.querySelector('[data-k="' + k + '"]');
      if (n) { n.textContent = typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v == null ? '' : v); }
    };
    const rebuildRender = function (status) {
      status = status || {};
      ['status','current_after_product_id','next_after_product_id','last_safe_next_after_product_id','processed_total','fixed_total','skipped_total','errors_total','missing_ovoko_id_total','missing_category_id_total','missing_category_path_total','api_errors_total','categories_created_total','categories_existing_total','category_assignments_changed_total','menu_cache_rebuild','menu_cache_category_count','menu_cache_build_duration','batches_done_since_cache_rebuild','started_at','updated_at','duration','last_error'].forEach(function (k) { rebuildSetK(k, status[k] || 0); });
      rebuildSetK('last_batch_result', status.last_batch_result || null);
      rebuildLogsEl.textContent = rebuildLogs.slice(-40).map(function (x) { return JSON.stringify(x); }).join('\n');
    };
    const rebuildField = function (id, fallback) { const el = $(id); return Math.max(0, parseInt((el && el.value) || String(fallback || 0), 10) || 0); };
    const rebuildBody = function (command) {
      const fd = new URLSearchParams();
      fd.set('action', config.categoryRebuildAutorunAction || 'gpswiss_ovoko_category_rebuild_autorun');
      fd.set('_ajax_nonce', config.categoryRebuildAutorunNonce || '');
      fd.set('command', command);
      fd.set('confirmation', String(($('gpswiss_rebuild_autorun_confirmation') || {}).value || ''));
      fd.set('start_after_product_id', String(rebuildField('gpswiss_rebuild_autorun_start_after', 0)));
      fd.set('batch_size', String(Math.max(1, rebuildField('gpswiss_rebuild_autorun_batch_size', 5))));
      fd.set('cache_rebuild_every_n_batches', String(Math.max(1, rebuildField('gpswiss_rebuild_autorun_cache_every', 5))));
      fd.set('stop_on_error', $('gpswiss_rebuild_autorun_stop_on_error')?.checked ? '1' : '0');
      return fd;
    };
    const rebuildRequest = async function (command) {
      const r = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: rebuildBody(command).toString() });
      const text = await r.text();
      let res;
      try { res = JSON.parse(text); } catch (e) { res = { ok: false, error: 'invalid_json_response', raw: text }; }
      if (!r.ok && !res.error) { res.error = 'ajax_http_' + r.status; }
      return res;
    };
    const rebuildTick = async function () {
      if (rebuildInFlight) return;
      rebuildInFlight = true;
      let res;
      try { res = await rebuildRequest('tick'); } catch (e) { res = { ok: false, error: String(e && e.message || e) }; }
      rebuildInFlight = false;
      rebuildLogs.push({ timestamp: nowIso(), command: 'tick', ok: !!res.ok, error: res.error || '', status: (res.status || {}).status || '', batch: res.batch ? { after_product_id: res.batch.after_product_id, next_after_product_id: res.batch.next_after_product_id, counts: res.batch.counts, menu_cache_rebuild: res.batch.menu_cache_rebuild } : null });
      rebuildRender(res.status || {});
      if ((res.status || {}).status === 'running' && !res.done) { rebuildTimer = setTimeout(rebuildTick, 1200); }
    };
    const rebuildCommand = async function (command) {
      if (rebuildTimer) clearTimeout(rebuildTimer);
      let res;
      try { res = await rebuildRequest(command); } catch (e) { res = { ok: false, error: String(e && e.message || e) }; }
      rebuildLogs.push({ timestamp: nowIso(), command: command, ok: !!res.ok, error: res.error || '', status: (res.status || {}).status || '' });
      rebuildRender(res.status || {});
      if ((res.status || {}).status === 'running' && (command === 'start' || command === 'resume')) { rebuildTick(); }
    };
    $('gpswiss_rebuild_autorun_start')?.addEventListener('click', function (e) { e.preventDefault(); rebuildCommand('start'); });
    $('gpswiss_rebuild_autorun_pause')?.addEventListener('click', function (e) { e.preventDefault(); rebuildCommand('pause'); });
    $('gpswiss_rebuild_autorun_resume')?.addEventListener('click', function (e) { e.preventDefault(); rebuildCommand('resume'); });
    $('gpswiss_rebuild_autorun_stop')?.addEventListener('click', function (e) { e.preventDefault(); rebuildCommand('stop'); });
    $('gpswiss_rebuild_autorun_reset')?.addEventListener('click', function (e) { e.preventDefault(); rebuildCommand('reset'); });
  }

});
