# eBay admin panel workflow

The eBay admin page is organized around a daily operational workflow and keeps technical tools collapsed under **Advanced diagnostics**. Existing action handlers, nonces and capability checks remain in use; the refactor changes the admin page layout and labels rather than the eBay business logic.

## Target workflow

1. Open **Main actions**.
2. Enter a WooCommerce `product_id`.
3. Click **Check readiness** to run the existing product preflight handler.
4. Click **Preview listing** to render the existing eBay description/template preview.
5. If the preview is acceptable, click **Publish single product** to use the existing single-offer publish handler.
6. If the product is blocked by category, go to **Category mapping** and import the manual CSV.
7. After category mapping is fixed, use **Bulk publish** to build/refresh candidates and publish in controlled batches.

## New section layout

1. **Dashboard / Status** — account/API status, marketplace, last sync, last error, ready/blocked/missing-aspect counts, published listing count, queue status and cron state.
2. **Main actions** — single-product readiness, preview, publish, export payload, content refresh status and last JSON result.
3. **Category mapping** — manual CSV import/export, mapped/unmapped counts, blocked category links and last import/export status.
4. **Bulk publish** — batch size, cursor, published total, remaining count, success/failed/skipped status, pause/resume and last batch log.
5. **eBay sync** — scheduled sync, order import and inventory/offer sync controls separate from product publishing.
6. **Advanced diagnostics** — raw API/account tools, audits, endpoint-style diagnostics, queue tools, SKU generation, checkpoint reset and other rare or risky actions.
7. **Recent logs** — last logs with eBay/publish/order/sync/category filters and expandable technical JSON.

## Module relocation map

| Existing module/tool | New location |
| --- | --- |
| Manual preflight/export/publish forms | **Main actions** with operational labels |
| API readiness, token status, policy refresh, cached policies JSON | **Advanced diagnostics → Account / API diagnostics** |
| Readiness scans and full category audit raw tables | **Advanced diagnostics → Readiness scans and category reports** |
| Automapping repairs and category teaching rule tests | **Advanced diagnostics → Category mapping technical tools** |
| Listing quality audit, condition cleanup and dry-run payload tools | **Advanced diagnostics → Listing quality / cleanup tools** |
| Shipping mapping reports and fulfillment policy update tests | **Advanced diagnostics → Shipping, queue, SKU and destructive tools** |
| Queue processing, ready queue rebuild, SKU generation and reset tools | **Advanced diagnostics → Shipping, queue, SKU and destructive tools** |
| Product sync meta table | **Advanced diagnostics → Product sync status rows** |
| Logs/debug table | **Recent logs** |

## Manual category CSV

The category CSV UI is intended for manual mappings. The importer accepts the existing teaching CSV columns and also recognizes this minimal manual format:

```csv
woo_category_id,ebay_category_id,woo_category_path,mapping_status,note
123,33559,Gearboxes > Automatic,manual,verified manually
```

Required columns for the minimal format:

- `woo_category_id`
- `ebay_category_id`

Optional columns:

- `woo_category_path`
- `mapping_status`
- `note`

If `woo_category_path` is missing, the importer derives it from `woo_category_id` before saving the manual mapping rule.

## Safety notes

- Advanced diagnostics is collapsed by default.
- Risky or heavy actions are kept out of the daily workflow and remain behind explicit buttons.
- The refactor does not change cron schedules, queue workers, pricing, stock sync, order sync, listing templates or the core eBay publish API flow.
