<?php
if (!defined('ABSPATH')) {
    exit;
}
$gmail_batch_nonce = wp_create_nonce('gpswiss_ovoko_gmail_draft_batch_run');
?>
<div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #2271b1;">
    <h2>Ovoko → Woo Gmail draft update</h2>
    <p><strong>Safe Gmail/Ovoko updater.</strong> Updates only existing Woo products whose SKU starts with <code>GPS-GMAIL-</code> and that already have <code>_ovoko_part_id</code> or <code>ovoko_part_id</code>. It never creates Woo products and never imports or reorders images.</p>
    <ul style="list-style:disc;margin-left:20px;">
        <li><strong>Only Gmail SKU:</strong> ON, locked.</li>
        <li><strong>Preserve Woo images:</strong> ON, locked. Thumbnail/gallery are snapshotted before live update and restored if changed.</li>
        <li><strong>Browser batch update:</strong> processes small AJAX batches with a cursor, delay, and optional stop-on-error.</li>
    </ul>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:grid;gap:8px;border:1px solid #dcdcde;padding:12px;background:#fff;">
            <h3 style="margin-top:0;">Preview one product</h3>
            <?php wp_nonce_field('gpswiss_ovoko_gmail_draft_preview_one'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_gmail_draft_preview_one" />
            <label><strong>Product ID for single product</strong><br><input type="number" min="1" step="1" name="product_id" class="regular-text" required /></label>
            <label><input type="checkbox" name="dry_run" value="1" checked disabled /> Dry-run ON (locked for preview)</label>
            <label><input type="checkbox" name="publish_when_ready" value="1" checked /> Publish when ready for sale</label>
            <label><input type="checkbox" name="only_gmail_sku" value="1" checked disabled /> Only Gmail SKU ON (locked)</label>
            <label><input type="checkbox" name="preserve_woo_images" value="1" checked disabled /> Preserve Woo images ON (locked)</label>
            <label><input type="checkbox" name="raw_json" value="1" checked /> Raw JSON / Technical details</label>
            <?php submit_button('Preview one product', 'secondary', 'submit', false); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:grid;gap:8px;border:2px solid #d63638;padding:12px;background:#fff7f7;">
            <h3 style="margin-top:0;">Update one product</h3>
            <?php wp_nonce_field('gpswiss_ovoko_gmail_draft_update_one'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_gmail_draft_update_one" />
            <label><strong>Product ID for single product</strong><br><input type="number" min="1" step="1" name="product_id" class="regular-text" required /></label>
            <label><input type="checkbox" name="publish_when_ready" value="1" checked /> Publish when ready for sale</label>
            <label><input type="checkbox" name="only_gmail_sku" value="1" checked disabled /> Only Gmail SKU ON (locked)</label>
            <label><input type="checkbox" name="preserve_woo_images" value="1" checked disabled /> Preserve Woo images ON (locked)</label>
            <label><input type="checkbox" name="stop_on_first_error" value="1" checked /> Stop on first error</label>
            <label><strong>Live confirmation</strong><br><input type="text" name="confirmation" class="regular-text" placeholder="UPDATE ONE GMAIL DRAFT" required /></label>
            <p class="description">Type exactly <code>UPDATE ONE GMAIL DRAFT</code>. This updates one product only.</p>
            <?php submit_button('Update one product', 'primary', 'submit', false); ?>
        </form>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start;margin-top:14px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:grid;gap:8px;border:1px solid #dcdcde;padding:12px;background:#fff;">
            <h3 style="margin-top:0;">Preview eligible Gmail products</h3>
            <?php wp_nonce_field('gpswiss_ovoko_gmail_draft_preview_eligible'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_gmail_draft_preview_eligible" />
            <label><strong>Batch size</strong><br><input type="number" min="1" max="100" step="1" name="batch_size" value="10" /></label>
            <label><input type="checkbox" name="dry_run" value="1" checked disabled /> Dry-run ON (locked)</label>
            <label><input type="checkbox" name="publish_when_ready" value="1" checked /> Publish when ready for sale</label>
            <label><input type="checkbox" name="raw_json" value="1" checked /> Raw JSON / Technical details</label>
            <?php submit_button('Preview eligible Gmail products', 'secondary', 'submit', false); ?>
        </form>

        <div id="gpswiss-gmail-batch-runner" style="display:grid;gap:8px;border:2px solid #d63638;padding:12px;background:#fff7f7;">
            <h3 style="margin-top:0;">Run batch update</h3>
            <input type="hidden" id="gpswiss-gmail-batch-nonce" value="<?php echo esc_attr($gmail_batch_nonce); ?>" />
            <label><strong>Batch size</strong><br><input type="number" min="1" max="100" step="1" id="gpswiss-gmail-batch-size" value="10" /></label>
            <label><strong>Max batches (optional safety limit)</strong><br><input type="number" min="0" max="10000" step="1" id="gpswiss-gmail-max-batches" value="0" /> <span class="description">0 means run until done.</span></label>
            <label><strong>Delay between batches (ms)</strong><br><input type="number" min="0" max="60000" step="100" id="gpswiss-gmail-delay" value="1500" /></label>
            <label><input type="checkbox" id="gpswiss-gmail-publish" value="1" checked /> Publish when ready for sale</label>
            <label><input type="checkbox" id="gpswiss-gmail-stop-error" value="1" checked /> Stop on first error</label>
            <label><input type="checkbox" id="gpswiss-gmail-raw-json" value="1" /> Raw JSON / Technical details</label>
            <label><strong>Live confirmation</strong><br><input type="text" id="gpswiss-gmail-confirmation" class="regular-text" placeholder="RUN GMAIL BATCH UPDATE" /></label>
            <p class="description">Live update is enabled only after typing exactly <code>RUN GMAIL BATCH UPDATE</code>. Preview mode below performs no writes.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="button" id="gpswiss-gmail-preview-batch">Preview batch</button>
                <button type="button" class="button button-primary" id="gpswiss-gmail-live-batch" disabled>Start live batch update</button>
                <button type="button" class="button" id="gpswiss-gmail-stop-batch" disabled>Stop</button>
            </div>
            <div id="gpswiss-gmail-batch-status" style="padding:8px;background:#fff;border:1px solid #dcdcde;min-height:32px;">Idle.</div>
            <pre id="gpswiss-gmail-batch-output" style="display:none;max-height:360px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:10px;white-space:pre-wrap;"></pre>
        </div>
    </div>
</div>
<script>
(function () {
    const root = document.getElementById('gpswiss-gmail-batch-runner');
    if (!root) return;
    const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    const required = 'RUN GMAIL BATCH UPDATE';
    const fields = {
        nonce: document.getElementById('gpswiss-gmail-batch-nonce'),
        batchSize: document.getElementById('gpswiss-gmail-batch-size'),
        maxBatches: document.getElementById('gpswiss-gmail-max-batches'),
        delay: document.getElementById('gpswiss-gmail-delay'),
        publish: document.getElementById('gpswiss-gmail-publish'),
        stopError: document.getElementById('gpswiss-gmail-stop-error'),
        rawJson: document.getElementById('gpswiss-gmail-raw-json'),
        confirmation: document.getElementById('gpswiss-gmail-confirmation'),
        preview: document.getElementById('gpswiss-gmail-preview-batch'),
        live: document.getElementById('gpswiss-gmail-live-batch'),
        stop: document.getElementById('gpswiss-gmail-stop-batch'),
        status: document.getElementById('gpswiss-gmail-batch-status'),
        output: document.getElementById('gpswiss-gmail-batch-output')
    };
    let stopped = false;
    let running = false;
    let totals = {};

    function valueInt(el, fallback) {
        const parsed = parseInt(el.value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    }
    function updateLiveButton() {
        fields.live.disabled = running || fields.confirmation.value !== required;
    }
    function addCounters(counters) {
        Object.keys(counters || {}).forEach(function (key) {
            totals[key] = (totals[key] || 0) + (parseInt(counters[key], 10) || 0);
        });
    }
    function summary(counters) {
        const c = counters || totals;
        return 'scanned=' + (c.scanned || 0) + ', eligible=' + (c.eligible || 0) + ', updated=' + (c.updated || 0) + ', published=' + (c.published || 0) + ', skipped=' + (c.skipped || 0) + ', blocked=' + (c.blocked || 0) + ', errors=' + (c.errors || 0) + ', images_preserved_failures=' + (c.images_preserved_failures || 0);
    }
    function render(data, batchNo) {
        addCounters(data.counters || {});
        fields.status.textContent = 'Batch ' + batchNo + ': ' + summary(data.counters || {}) + ' | totals: ' + summary(totals) + ' | next cursor: ' + (data.next_cursor || 0) + (data.done ? ' | done' : '');
        const readable = (data.rows || []).map(function (row) {
            return '#' + row.product_id + ' ' + (row.sku || '') + ' part=' + (row.ovoko_part_id || '') + ' action=' + row.action + ' ready=' + (row.ready_for_sale ? 'yes' : 'no') + ' reasons=' + (row.blocked_reasons || []).join('|') + (row.error_message ? ' error=' + row.error_message : '');
        }).join('\n');
        fields.output.style.display = 'block';
        fields.output.textContent += '\n\nBatch ' + batchNo + '\n' + readable;
        if (fields.rawJson.checked) {
            fields.output.textContent += '\n' + JSON.stringify(data, null, 2);
        }
    }
    function requestBatch(mode, cursor) {
        const body = new URLSearchParams();
        body.set('action', 'gpswiss_ovoko_gmail_draft_batch_run');
        body.set('_ajax_nonce', fields.nonce.value);
        body.set('mode', mode);
        body.set('cursor', String(cursor));
        body.set('batch_size', String(Math.max(1, Math.min(100, valueInt(fields.batchSize, 10)))));
        body.set('publish_when_ready', fields.publish.checked ? '1' : '0');
        body.set('stop_on_first_error', fields.stopError.checked ? '1' : '0');
        body.set('confirmation', fields.confirmation.value);
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw data;
                return data;
            });
        });
    }
    function run(mode) {
        stopped = false;
        running = true;
        totals = {};
        let cursor = 0;
        let batchNo = 0;
        const maxBatches = Math.max(0, valueInt(fields.maxBatches, 0));
        const delay = Math.max(0, valueInt(fields.delay, 1500));
        fields.stop.disabled = false;
        fields.preview.disabled = true;
        fields.output.textContent = '';
        fields.output.style.display = fields.rawJson.checked ? 'block' : 'none';
        updateLiveButton();
        const next = function () {
            if (stopped) {
                fields.status.textContent = 'Stopped by user. totals: ' + summary(totals);
                finish();
                return;
            }
            if (maxBatches > 0 && batchNo >= maxBatches) {
                fields.status.textContent = 'Stopped at max batches safety limit. totals: ' + summary(totals);
                finish();
                return;
            }
            batchNo++;
            fields.status.textContent = 'Running batch ' + batchNo + '...';
            requestBatch(mode, cursor).then(function (data) {
                render(data, batchNo);
                cursor = parseInt(data.next_cursor || cursor, 10) || cursor;
                if (data.done || (fields.stopError.checked && data.counters && parseInt(data.counters.errors || 0, 10) > 0)) {
                    finish();
                    return;
                }
                window.setTimeout(next, delay);
            }).catch(function (error) {
                fields.output.style.display = 'block';
                fields.output.textContent += '\n\nERROR\n' + JSON.stringify(error, null, 2);
                fields.status.textContent = 'Error. ' + (error && error.error ? error.error : 'See technical output.');
                finish();
            });
        };
        const finish = function () {
            running = false;
            fields.stop.disabled = true;
            fields.preview.disabled = false;
            updateLiveButton();
        };
        next();
    }
    fields.confirmation.addEventListener('input', updateLiveButton);
    fields.preview.addEventListener('click', function () { run('preview'); });
    fields.live.addEventListener('click', function () { if (fields.confirmation.value === required) run('live'); });
    fields.stop.addEventListener('click', function () { stopped = true; });
    updateLiveButton();
}());
</script>
