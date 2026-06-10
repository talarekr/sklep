<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #2271b1;">
    <h2>Ovoko → Woo Gmail draft update</h2>
    <p><strong>Stage 1 safe MVP.</strong> Updates only existing Woo products whose SKU starts with <code>GPS-GMAIL-</code> and that already have <code>_ovoko_part_id</code> or <code>ovoko_part_id</code>. It never creates Woo products and never imports or reorders images.</p>
    <ul style="list-style:disc;margin-left:20px;">
        <li><strong>Only Gmail SKU:</strong> ON, locked.</li>
        <li><strong>Preserve Woo images:</strong> ON, locked. Thumbnail/gallery are snapshotted before live update and restored if changed.</li>
        <li><strong>Batch live update:</strong> disabled until stage 2 browser-based auto-runner.</li>
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
            <p class="description">Type exactly <code>UPDATE ONE GMAIL DRAFT</code>. This is one product only; no batch live update is wired in stage 1.</p>
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

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:grid;gap:8px;border:1px dashed #8c8f94;padding:12px;background:#f6f7f7;">
            <h3 style="margin-top:0;">Run batch update — stage 2 placeholder</h3>
            <?php wp_nonce_field('gpswiss_ovoko_gmail_draft_batch_placeholder'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_gmail_draft_batch_placeholder" />
            <label><strong>Batch size</strong><br><input type="number" min="1" max="100" step="1" name="batch_size" value="10" disabled /></label>
            <label><input type="checkbox" name="dry_run" value="1" checked disabled /> Dry-run ON</label>
            <label><input type="checkbox" name="publish_when_ready" value="1" checked disabled /> Publish when ready for sale</label>
            <label><input type="checkbox" name="stop_on_first_error" value="1" checked disabled /> Stop on first error</label>
            <p class="description">Browser-based Start/Stop, delay, max batches, one admin-post/AJAX request per batch, and raw JSON details remain for stage 2.</p>
            <?php submit_button('Run batch update', 'secondary', 'submit', false, ['disabled' => 'disabled']); ?>
        </form>
    </div>
</div>
