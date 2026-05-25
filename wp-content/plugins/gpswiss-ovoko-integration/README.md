# GPSwiss Ovoko Integration

Standalone plugin for Ovoko→Woo callback ingestion and Supply Connector readiness scaffolding in disabled/dry-run mode.

## Ovoko listing image display compatibility with Allegro importer

- Frontend product card uses `_awi_listing_image_id` / Allegro helper preference. `_awi_listing_image_id` must point to a **processed listing attachment**, not just `_thumbnail_id`.
- Ovoko draft-create flow now runs a listing-image compatibility step after image import.
- Strategy:
  - `ovoko_copied_allegro_generator_exact`: Ovoko runs Allegro-style source selection + render processing and creates a **new** attachment.
  - source selection candidates = featured (`_thumbnail_id`) + gallery (`_product_image_gallery`), not only first image.
  - source scoring mirrors Allegro `listing_first_quality_score` inputs:
    - `aspect_ratio`, `aspect_distance_from_square`,
    - `object_area_ratio`,
    - `square_fill_ratio`,
    - quality tier: `degraded` / `acceptable` / `preferred`.
  - renderer crops to square (`crop_width = crop_height`) around detected non-white object bounding box, centers object, draws on white background, output JPEG.
  - target fill ratio matches Allegro profile behavior (`0.96` standard / boost profiles up to `0.995` when needed for extreme aspect cases).
  - metrics persisted and reported in diagnostics:
    - candidate/selected source image fields,
    - quality score/tier fields,
    - render metrics (`target_fill_ratio`, image/object/crop/final dimensions),
    - listing attachment linkage fields (`listing_image_id`, `listing_image_source_id`, `listing_image_is_same_as_thumbnail`).
  - `_awi_listing_image_id` must be different from `_thumbnail_id` when generation succeeds.
  - If real generation is unavailable, action returns `listing_image_generated=false`, `reason=real_generator_unavailable`, with explicit `errors[]` (no silent success fallback).
- Added admin diagnostics and repair actions:
  - **Preview listing image status**
  - **Generate listing image for Ovoko product**
- No eBay publish, no Allegro publish, no batch side effects.

## Create Woo draft product from RRR part (complete draft)

Manual admin action **Create Woo draft product from RRR part** now creates a complete Woo draft product in one step (no separate image-import action required):

- creates Woo product as `draft`,
- writes price from `internal_notes`-derived target price,
- writes Ovoko meta and part number mapping (`manufacturer_code -> _part_number` plus `_mpn`, `mpn`, `_manufacturer_code`, `_gpswiss_part_number`, `_ovoko_manufacturer_code`),
- writes technical attributes as **custom per-product attributes** (not global taxonomies),
- imports images using Allegro-compatible ordering model:
  - source: full-size `part_photo_gallery` (thumbnail `photo` is ignored when gallery exists),
  - preserves order,
  - deduplicates URLs,
  - first image -> featured (`_thumbnail_id`),
  - remaining images -> gallery (`_product_image_gallery`),
  - max 10 images per product.

Attachment URL deduplication:
- before sideload, plugin checks attachment by `_awi_source_url = image_url`,
- if found, attachment is reused,
- if not found, image is sideloaded and attachment meta is saved:
  - `_awi_source_url`
  - `_ovoko_source_url`
  - `_ovoko_part_id`
  - `_ovoko_imported_image = yes`.

Safety constraints remain unchanged:
- no eBay publish,
- no Allegro publish,
- no batch/cron,
- no auto mass updates,
- no stock/publish side effects beyond existing draft-create behavior.

## REST callback endpoint

`POST /wp-json/gpswiss-ovoko/v1/callback`

## Ovoko Supply Connector analysis

Public docs analyzed: `https://supply-connector.ms.ovoko.com/` (API Platform root + `index.json` resource listing).

Observed resource names from public listing:
- Category
- IntegrationAction
- IntegrationSettings
- WooCommerceIntegration
- IntegrationWebhook
- WebhookSignedUrl
- User
- BaselinkerIntegration
- BosabIntegration
- IntegrationRemoval
- SaasWebhook
- Baselinker
- Bosab
- Credentials

Interpretation for current phase:
- Public surface looks like API Platform integration resources, not a simple confirmed `GET /parts` pull importer.
- The model appears closer to integration-settings + integration-action + webhook/callback orchestration.
- We do **not** assume undocumented product endpoints.

What is still missing for full Ovoko→Woo import:
- Confirmed and authorized product/part retrieval endpoint(s) for WooCommerceIntegration.
- Auth method details (token/api key/header format/scopes).
- Tenant/integration identifier and environment activation details.
- Field-level payload contract for part/product objects and pagination/rate limits.

Data needed from Ovoko:
- Credentials (token and/or api key) with scope for agreed integration resources.
- Integration ID / WooCommerceIntegration linkage details.
- Confirmed endpoint list for safe pull/preview and eventual production sync.
- Callback/webhook contract details beyond current tested event.

## Current scope (safe scaffold)

Implemented:
- Callback receiver with header-secret validation.
- Deduplication by `event_id`.
- `part.status.changed` handling with dry-run action planning.
- Unsupported callback events ignored as `unsupported` (not failed).
- Product mapping lookup by part-id related meta keys.
- Admin readiness page.
- Supply Connector client adapter placeholders (no guessed endpoints).
- Preview-only normalization/mapping/match logic for future sync.

Not implemented (by design):
- No production Ovoko→Woo product import.
- No product create/update.
- No stock updates.
- No batch or cron synchronization.
- No outbound production writes to Ovoko.

## Dry-run product sync scaffold

Default-safe options:
- `ovoko_supply_connector_enabled = false`
- `ovoko_supply_connector_base_url = https://supply-connector.ms.ovoko.com`
- `ovoko_sync_enabled = false`
- `ovoko_sync_dry_run = true`
- `ovoko_sync_mode = disabled|preview_only|manual_single|batch_dry_run`
- `ovoko_sync_batch_limit = 10`

Secrets behavior:
- Secret values are never rendered back in HTML inputs.
- Empty secret input keeps previous saved value.
- Secrets are not logged.

## Callback events currently handled

- Supported: `part.status.changed` (dry-run planning only).
- Unsupported events: logged and marked as ignored/unsupported.

## Developer sample fixture

A developer/sample fixture exists for preview flow only:
- sample only
- not production
- no outbound
- no import

Used for:
- normalized DTO preview,
- payload hash preview,
- Woo meta mapping preview,
- matching preview.

## RRR API / api.rrr.lt analysis

Documentation references:
- Docs UI: `https://api.rrr.lt/docs/`
- OpenAPI spec: `https://api.rrr.lt/openapi/swagger.yaml`

Important behavior:
- Auth fields: `username`, `password`, `user_token`.
- Request format: POST form-data (not JSON) for CRM API calls.
- Success semantics: HTTP 200 alone is not enough; evaluate `status_code` in JSON body.

Observed API areas (from docs guidance):
- CRM EXPORT: Part, Parts v2, Car, Cars v2.
- CRM INFO: categories, models and dictionaries.
- WEBHOOKS.

What we implemented in this phase (safe preview):
- RRR API settings with secure secret handling (blank input preserves stored value).
- Readiness check that confirms saved credentials presence and probes public docs URLs only.
- Preview helpers (dry-run only):
  - `preview_fetch_part_by_id($part_id)`
  - `preview_fetch_parts_page($limit)`
  - `normalize_rrr_part_payload($payload)`
  - `map_rrr_part_to_woo_meta($normalized)`

What is still required before safe import execution:
- Confirm exact non-mutating authenticated endpoint for production-grade connection test.
- Confirm canonical payload schema for parts export paging/filtering.
- Define mapping contract from RRR payload to Woo product/meta fields.
- Add guarded importer workflow (still disabled in this plugin currently).

## RRR API read-only auth probe

The plugin now includes a safe authentication probe that **does not import data**.

- Endpoint: `POST https://api.rrr.lt/v2/get/parts?limit=1&page=1`
- Auth transport: `application/x-www-form-urlencoded` form-data fields:
  - `username`
  - `password`
  - `user_token`
- Success rule: HTTP response code alone is not enough; success is only when JSON `status_code = "R200"`.
- Stable IDs:
  - `id` is the primary, stable part ID in RRR/Ovoko CRM.
  - `external_id` is an optional external ID from your system.
  - `id_bridge` is deprecated and should not be used.
- Pagination:
  - request: `page`, `limit` (1–100),
  - response: `pagination.page`, `pagination.limit`, `pagination.total_count`,
  - pages count formula: `ceil(total_count / limit)`.

Probe behavior in this plugin:
- Fetches exactly one record (`limit=1`) for connectivity/auth verification.
- Shows only a safe first-record summary (`id`, `external_id`, `name`, `status`, `updated_at`).
- Does not create/update Woo products.
- Does not write `_ovoko_part_id`.
- Does not update stock.
- Does not run batch/cron import.

## Preview RRR parts status distribution (admin action)

- Admin action: **Preview RRR parts status distribution**.
- Request: `POST /v2/get/parts?limit=50&page=1` with form-data auth fields:
  `username`, `password`, `user_token`.
- Defaults: `limit=50`, `page=1`; `limit` is capped at max `50`.
- Read-only preview only:
  - no product create/update,
  - no Woo meta writes,
  - no stock writes,
  - no cron/batch/import execution.
- Preview output includes:
  - `status_code`, `msg`,
  - `pagination.page`, `pagination.limit`, `pagination.total_count`,
  - `records_count`,
  - status distribution from sample (for example `status=0: X`, `status=1: Y`),
  - per-record safe summary: `id`, `external_id`, `name`, `status`, `updated_at`.
- Admin diagnostic note:
  - `API total_count may include inactive/sold/archived parts until status semantics are confirmed by Ovoko/RRR.`
- Current fields expected from `/v2/get/parts` list endpoint are treated as minimal list payload only (no assumptions about full photos/price/OE schema in this step).

## RRR API docs check: status/activity filters

- Attempted source of truth: `https://api.rrr.lt/docs/` and `https://api.rrr.lt/openapi/swagger.yaml`.
- In this environment, direct fetch currently returns HTTP `403`, so filter parameters could not be confirmed from docs in-session.
- Because docs were not readable from here, this plugin does **not** guess any undocumented status/activity filter.

Questions to confirm with Ovoko/RRR:
- What does `status=0` mean?
- What are all possible values of `status`?
- How to filter only active/available parts in `/v2/get/parts`?
- Does `pagination.total_count` include sold/archived/inactive parts?

## Gearbox standalone catalog exclusion

Products managed by `wp-content/plugins/gpswiss-allegro-gearboxes/` are treated as a standalone gearbox catalog and are outside Ovoko scope.

- They are excluded from Ovoko sync/import/matching when `ovoko_exclude_gearbox_products` is enabled (default: enabled).
- Name/title matching must not auto-link gearbox standalone products to Ovoko parts.
- These products remain Woo-only gearbox catalog entries.

## Preview RRR single part

Admin action: **Preview RRR single part**

- Endpoint: `POST /get/part/{id}`
- Content type: `application/x-www-form-urlencoded`
- Auth form fields: `username`, `password`, `user_token` (from plugin settings; never displayed)
- Read-only mode only: no import, no product creation, no product update, no stock/meta writes.
- Purpose: inspect which full fields are returned by `/get/part/{id}` before any future importer work.

- RRR `/get/part/{id}` may return part data nested under `list[0][0]`, so parser normalizes nested list responses.

### Normalized preview fields for `/get/part/{id}`

Part fields now preview-normalized (read-only, no import):
- `part_id` (from `id`)
- `car_id`
- `title` (from `name`)
- `status`
- `price`, `currency`
- `original_price`, `original_currency`
- `manufacturer_code`, `visible_code`, `other_code`
- `quality`, `notes`

## Ovoko Woo title builder

For Woo product titles created from Ovoko/RRR parts, this plugin now uses a dedicated builder and **does not use raw `name` alone as the final title**.

Title strategy:
- Ideal title pattern:
  - `[MAKE] [MODEL] [GENERATION] [ENGINE/MARKETING NAME] [PART NOTES] [MANUFACTURER_CODE]`
- Example:
  - `VW TOURAN III 1.4 TSI EKRAN WYŚWIETLACZ RADIA NAWIGACJI EUROPA 3G0919605D`

Rules:
- If full vehicle data is available (`make`, `model`, `generation`, `engine`), the builder uses the full ideal title.
- If only `car_id` or partial vehicle data is available, title falls back to:
  - `notes + manufacturer_code` (or `name + notes + manufacturer_code` when needed),
  - and marks review metadata:
    - `_ovoko_title_review_required = yes`
    - `_ovoko_title_source = fallback_missing_vehicle_data`
    - `_ovoko_title_missing_vehicle_fields` with missing fields list
    - `_ovoko_title_generated_from` with rule identifier

Notes:
- Full title quality like `VW TOURAN III 1.4 TSI ...` requires resolved vehicle details, not only `car_id`.
- In preview mode, title builder output is shown without creating/updating Woo products.
- `category_id`, `category_title_path`
- `position`
- `shop_url`, `show_url`
- `photo`, `part_photo_gallery`
- `create_date`, `updated_at`
- `external_id`
- `place`
- `allegro_channel`, `allegro_id` (only if provided by payload)
- `ovoko_price`, `ovoko_currency`
- `ovoko_original_price`, `ovoko_original_currency`
- `allegro_channel_price`, `allegro_channel_currency`
- `woo_target_price`, `woo_target_currency`
- `price_source`, `price_review_required`, `price_reason`
- `allegro_price_available`, `allegro_price_location`

Safety/visibility notes:
- `internal_notes`, `reserved_user`, `reserved_date` are not shown as user data; only `*_field_exists` booleans are shown in preview.
- No Woo product/meta writes are performed in preview mode.

## Preview Woo product create from RRR part

Admin action: **Preview Woo product create from RRR part**

- Input: `part_id` (default for testing: `10994`).
- Flow:
  - fetches `POST /get/part/{part_id}`,
  - normalizes part payload,
  - checks Woo match candidate,
  - returns either `would_update_existing_product` or `would_create_new_product`.
- Preview-only behavior:
  - no Woo product create,
  - no Woo product update,
  - no meta writes,
  - no stock writes,
  - no media downloads/imports.
- Product draft preview:
  - `post_status` is proposed as non-public (`draft`),
  - `regular_price` is suggested **only** when `price_source=internal_notes_plain_price` and `price_review_required=false`,
  - currency is `PLN`,
  - Ovoko `price/original_price` are informational only.
- Price safety:
  - if valid internal-notes price is missing, preview sets:
    - `create_blocked=true`
    - `reason=missing_valid_woo_price`
  - no fallback regular price is suggested from Ovoko `price/original_price`.
- Gearbox exclusion safety:
  - if matched product is in gearbox standalone catalog, preview marks:
    - `excluded_from_ovoko_sync=true`
    - blocked reason: `gearbox_standalone_catalog`.
- UI always states:
  - `Preview only — no Woo product was created or updated.`

## Woo price from Ovoko internal notes

- Ovoko **internal notes** field is now the manual source of Woo price in preview policy.
- Required format in `internal_notes`: plain numeric value only, for example:
  - `250`
  - `250.50`
  - `250,50` (comma is accepted and normalized to `.`)
- Currency is fixed to `PLN`.
- `internal_notes` must contain only the price value (no text like `250 zł`, `cena 250`, `Allegro 250`).
- Ovoko `price` / `original_price` are informational only (`_ovoko_price`, `_ovoko_original_price`) and are **not** imported as final Woo price.
- If no valid numeric value is found in internal notes, `price_review_required = true`.

### Price source policy (preview only)

Woo target price priority:
1. valid parsed price from `internal_notes`,
2. Allegro channel price (if available in payload),
3. otherwise missing price → review required.

Outcomes:
- valid internal notes price:
  - `price_source = internal_notes_plain_price`
  - `woo_target_price = parsed value`
  - `woo_target_currency = PLN`
  - `price_review_required = false`
- invalid internal notes format:
  - `price_source = invalid_internal_notes_price`
  - `woo_target_price = null`
  - `woo_target_currency = ""`
  - `price_review_required = true`
- missing internal notes price and missing Allegro channel price:
  - `price_source = missing_woo_price`
  - `woo_target_price = null`
  - `woo_target_currency = ""`
  - `price_review_required = true`

Preview mapping includes:
- `_ovoko_internal_notes_price_source` (when internal notes price is valid),
- `_ovoko_woo_target_price`,
- `_ovoko_woo_target_currency`.

Safety:
- preview/dry-run only,
- no writes to Woo `_price` / `_regular_price`,
- no import/batch/cron/product/stock updates.

Diagnostic key search in `/get/part/{id}` preview checks for channel-related fields including:
- `allegro`
- `channels`
- `sales_channels`
- `channel_prices`
- `marketplace_prices`
- `integrations`
- `offers`
- `allegro_price`
- `price_allegro`
- `sale_price`
- `external_offers`

Open integration question:
- **Which endpoint or field returns channel-specific Allegro price for a part?**
- **Which endpoint returns sales channels / Allegro offer ID / Allegro channel price for a part?**

## Ovoko car_id / same vehicle grouping

- `car_id` is treated as the grouping key for parts from the same donor vehicle.
- Target Woo meta key for future mapping is `_ovoko_car_id`.
- This enables future CTA/link blocks like **“Other parts from this vehicle”** (`Andere Teile aus diesem Fahrzeug ansehen`).
- Preview includes **Same vehicle grouping** diagnostics:
  - `car_id`
  - `car_id_available` yes/no
  - future query preview: `products where _ovoko_car_id = {car_id}`
  - message: `This will enable ‘Other parts from this vehicle’ links after import/mapping.`

Vehicle data behavior:
- If `/get/part/{id}` includes full embedded vehicle data, those fields are normalized in preview.
- If payload contains only `car_id`, preview sets `vehicle_data_status = car_id_only`.
- Open question to confirm with RRR/Ovoko:
  - `Which endpoint returns full car details for car_id? Possibly /get/car/{id} or Cars v2 — confirm with RRR/Ovoko.`
- The plugin does **not** guess undocumented vehicle endpoints.

## Create Woo draft product from RRR part

Admin action: **Create Woo draft product from RRR part**

- Manual test action only (admin-triggered).
- Input: `part_id` (default: `10994`).
- Scope: creates only WooCommerce `draft` simple product.
- Safety guarantees:
  - no publish,
  - no eBay actions,
  - no Allegro actions,
  - no batch/cron,
  - no stock updates on existing products,
  - no media library image import.

Validation gates before create:
- `POST /get/part/{id}` must return business `status_code=R200`.
- Existing Woo match must not exist (checked against part identifiers).
- `create_blocked=false` equivalent checks:
  - `price_source=internal_notes_plain_price`,
  - `price_review_required=false`,
  - `woo_target_price > 0`.
- Gearbox exclusion must be false.
- Missing photos produces warning only (does not block create).

Created product fields:
- `post_status=draft`
- product type `simple`
- title from Ovoko/RRR `title`
- `regular_price` from `woo_target_price`
- SKU `GPSW-OVK-{part_id}`
- `description` and `short_description` from Ovoko/RRR `notes`

Saved meta:
- `_ovoko_part_id`
- `_ovoko_car_id`
- `_ovoko_status`
- `_ovoko_updated_at`
- `_ovoko_category`
- `_ovoko_category_id`
- `_ovoko_source_url`
- `_ovoko_images` (URLs only)
- `_ovoko_price`
- `_ovoko_original_price`
- `_ovoko_internal_notes_price_source`
- `_ovoko_woo_target_price`
- `_ovoko_woo_target_currency`
- `_ovoko_manufacturer_code`
- `_ovoko_quality`
- `_ovoko_position`
- `source=ovoko_master`

Duplicate protection:
- If `_ovoko_part_id={part_id}` (or equivalent existing match) already exists, action does not create second product and returns existing product link.

## Ovoko image handling and Allegro image model compatibility

Produkty Ovoko muszą używać tego samego modelu zdjęć Woo co importer Allegro (`allegro-woo-importer`), aby zachować identyczne zachowanie miniatury, galerii i kolejności zdjęć.

Przeanalizowane elementy Allegro dotyczące zdjęć:
- `includes/class-product-mapper.php`:
  - `sync_product_images()` — normalizacja URL-i, deduplikacja, przypisanie featured + gallery.
  - `extract_image_urls_from_offer_payload()` i `extract_single_image_url_from_payload_item()` — ekstrakcja i porządkowanie URL-i obrazów.
  - `find_existing_attachment_by_source()` — ponowne użycie attachmentów po źródłowym URL.
  - `sideload_image_attachment()` — bezpieczne pobieranie zdjęć i zapis do media library.
  - `ensure_listing_image_for_product()` — logika obrazu listingowego oparta o istniejące zdjęcia produktu.

Źródła zdjęć Ovoko (RRR) do modelu kompatybilnego z Allegro:
- `photo` (główne zdjęcie),
- `part_photo_gallery` (galeria).

Na tym etapie wdrożony jest wyłącznie preview/scaffold planu importu zdjęć (`preview_image_import_plan`) bez żadnego zapisu do Woo/media:
- bez pobierania zdjęć,
- bez tworzenia attachmentów,
- bez ustawiania featured image,
- bez ustawiania `_product_image_gallery`.

Realny import zdjęć będzie osobnym krokiem po zatwierdzeniu modelu kompatybilności.

## Woo image source policy (preview/import plan)

For Woo featured/gallery image ordering, the plugin now prefers full-size images from `part_photo_gallery`.

- If `part_photo_gallery` contains at least one URL, only those URLs are used for Woo image order.
- `photo` is treated as a thumbnail field and is ignored in that case.
- `photo` is used only as a fallback when `part_photo_gallery` is empty.

This policy is preview-only in current scope: no image download, no attachment creation, no `_thumbnail_id`, and no `_product_image_gallery` write.

## Ovoko technical attributes and part number mapping

The plugin now persists `manufacturer_code` to Woo fields commonly used by storefront themes for **Numer części / MPN**:
- `_ovoko_manufacturer_code`
- `_mpn`
- `mpn`
- `_manufacturer_code`
- `_gpswiss_part_number`
- `_part_number` (**frontend-critical in this theme**)

In this codebase, frontend product templates call `gp_get_product_part_number()` from theme `functions.php`, and that function reads only `_part_number` with fallback to `Brak`.

It also writes per-product custom attributes (`_product_attributes`, no global taxonomy attributes):
- `Numer części` (from `manufacturer_code`) — visible=true, variation=false
- Optional: `Kod widoczny` (from `visible_code`), `Inny kod części` (from `other_code`)
- `ID części Ovoko`, `ID pojazdu Ovoko`, `Kategoria Ovoko`, `Stan części`, `Pozycja`, `Źródło`

Manual admin action added:
- **Apply Ovoko technical attributes to Woo product** (`product_id` input).
- Scope: technical meta + attributes only.
- Explicitly does not modify price, stock, publication status, eBay, Allegro, or batch flows.

Frontend mapping diagnostics/actions added:
- **Frontend part number mapping** (`product_id` input) preview/debug output:
  - `expected_frontend_meta_key`
  - `current_value`
  - `ovoko_manufacturer_code`
  - `would_write_value`
  - `frontend_should_show`
- **Apply frontend part number mapping** (`product_id` input) writes `_ovoko_manufacturer_code` -> `_part_number` only.

## car_id vehicle details endpoint status

Status: **not confirmed in-session**.

- Attempted official source URLs:
  - `https://api.rrr.lt/docs/`
  - `https://api.rrr.lt/openapi/swagger.yaml`
- In this environment, direct fetch currently returns HTTP `403`, so a full read-only vehicle endpoint path could not be verified from docs.
- Because endpoint is not confirmed, plugin does **not** guess mutating/read paths.
- Added diagnostic preview output with question:
  - `Which endpoint returns full car details for car_id?`

Additional preview added:
- **Preview Ovoko title with vehicle data** (current title, fallback title, ideal title, missing fields, can_build_full_vehicle_title yes/no).

## RRR vehicle data by car_id

- Endpoint for full vehicle data by `car_id` is currently **not confirmed** in accessible RRR docs from this environment (HTTP 403 on docs/spec URL).
- Plugin now has preview diagnostics (`preview_fetch_car_by_id` and `preview_rrr_car_details`) that keep requests read-only and explicitly ask RRR/Ovoko to confirm official car endpoint.
- Vehicle title builder uses vehicle prefix when data is available: `[MAKE_SHORT] [MODEL] [GENERATION] [ENGINE_MARKETING] [NOTES] [MANUFACTURER_CODE]`.
- Fallback remains `[NOTES] [MANUFACTURER_CODE]` with `_ovoko_title_review_required=yes` when vehicle fields are missing.
- No eBay/Allegro publish flow is changed here.

## RRR vehicle data discovery and car_id mapping

Added read-only diagnostics action **Probe RRR vehicle endpoints** for candidate endpoints:
`/get/car/{id}`, `/get/cars`, `/v2/get/cars`, `/get/vehicles`, `/v2/get/vehicles` (including `limit=1&page=1` variants).

The probe reports: path, executed, http_code, status_code, msg, success, response keys, candidate record keys, car-id match flags, vehicle fields flag, safe sample, and `full_payload_omitted=true`.

If vehicle endpoint is confirmed, create-draft and repair flow can persist vehicle meta/attributes and generate vehicle-aware title format:
`[MAKE_SHORT] [MODEL] [GENERATION] [ENGINE_MARKETING] [NOTES] [MANUFACTURER_CODE]`.

Fallback stays enabled when vehicle endpoint is not confirmed:
- title: `[NOTES] [MANUFACTURER_CODE]`
- `_ovoko_title_review_required=yes`
- `_ovoko_title_source=fallback_missing_vehicle_data`

No automatic eBay/Allegro publish was added and no batch/cron was introduced.

## Vehicle data (/get/car/{id}) update

- Endpoint `/get/car/{id}` is confirmed and parser now supports `list[0][0]`, `list[0]`, `data[0]`, `data`, and root fallback.
- Some vehicle fields come as raw IDs (`car_model`, `car_model_category`, `car_fuel`, `car_gearbox_type`, `car_wheel_drive`, `car_body_type`, `car_color`).
- Local confirmed dictionary fallback is implemented only for `car_id=458` and only for confirmed values from Ovoko UI.
- Next step: confirm full dictionary endpoints in RRR/Ovoko API and replace local partial fallback.
- Title builder is confirmed for `part_id=10994` + `car_id=458` with full vehicle prefix.

## Ovoko product frontend rendering

- For Ovoko products (`source=ovoko_master` or existing `_ovoko_part_id`) product tabs are merged into one tab: **Opis oraz informacje szczegółowe** (description first, details table below).
- Separate **Informacje dodatkowe** tab is hidden only for Ovoko products (meta/attributes remain stored for technical use).
- Technical fields hidden from customer-facing details: Ovoko part ID, Ovoko car ID, part status, position, source, Ovoko category, period.
- Customer-facing details table shows only curated vehicle/part fields (number, make/model, fuel, engine, gearbox, body, drive, steering, color, year) and skips empty/`-`/`Brak` values.
- SKU and stock availability rendering is hidden on single-product frontend for Ovoko card presentation (data still remains in Woo/meta).
- In the top product info card, short description is replaced (for Ovoko with `_ovoko_car_id`) by button **Pokaż więcej części z tego pojazdu** linking to product archive filtered by `ovoko_car_id`.
- Product archive/shop/search/listing supports safe filtering by query param `ovoko_car_id`, mapped to `_ovoko_car_id` product meta.

## Ovoko image source / watermark policy

- Publiczne URL-e obrazów Ovoko (np. `images.ovoko.com`) mogą zawierać znak wodny Ovoko.
- Integracja **nie usuwa watermarka** (bez crop/AI/retuszu).
- Integracja najpierw diagnozuje źródła URL i preferuje oryginalne/czyste źródło, jeśli API je udostępnia.
- Jeśli nie znaleziono czystego źródła, zwracany jest warning: `Only public Ovoko watermarked image URLs found or authenticated original image probe failed.`
- Dostępna jest akcja diagnostyczna `Probe Ovoko original image auth` (read-only, limitowana), która testuje wybrane warianty URL bez zapisu danych.

## Authenticated Ovoko original images

- Publiczne URL-e `images.ovoko.com` są traktowane jako potencjalnie watermarked.
- `original` images mogą wymagać nagłówka `Authorization: Bearer <token>`.
- Dodano opcjonalne ustawienie: `ovoko_original_image_bearer_token` (oddzielne od `rrr_api_user_token`).
- Token nie jest ujawniany w HTML; panel pokazuje wyłącznie `original_image_token_configured: yes/no`.
- Probe testuje kandydaty `/original/` z `Authorization` + `User-Agent: GPSwissWooImporter/1.0`.
- Import clean images nie jest przełączany globalnie „na sztywno”; najpierw probe musi potwierdzić działający authenticated-original URL.

## Existing Allegro products → Ovoko details enrichment

Manual-only flow (single product):
- **Preview Allegro to Ovoko match** (`admin_post_gpswiss_ovoko_preview_allegro_to_ovoko_match`) reports detected Allegro markers, current Ovoko IDs, old description preview, proposed enrichment, and no-write safety flags.
- **Apply Allegro to Ovoko details enrichment** (`admin_post_gpswiss_ovoko_apply_allegro_to_ovoko_details`) writes only safe details fields for one product.

### Allegro product detection
Product is treated as Allegro when at least one of these is present:
- `_allegro_offer_id`, `allegro_offer_id`, `_awi_offer_id`
- `_awi_source`/`source` contains `allegro`

### Matching strategy to Ovoko/RRR
1. If `_ovoko_part_id` already exists: validate via read-only `/get/part/{id}`.
2. Otherwise try part number keys: `_part_number`, `_mpn`, `mpn`, `_manufacturer_code`, `_gpswiss_part_number`.
3. If `_ovoko_part_id` is missing and part number exists, plugin uses read-only endpoint: `/v2/get/parts?limit=10&page=1&search={part_number}`.
4. High confidence is allowed only when: `status_code=R200`, `pagination.total_count=1`, `records_count=1`, and exact code match is in one of: `manufacturer_code|visible_code|other_code|external_id` with non-empty `id`.
5. On high match, preview fetches `/get/part/{id}` (and `/get/car/{car_id}` when available) to build enrichment preview.
6. If search does not satisfy strict exact-single criteria, preview uses safe low-confidence response and returns:
   - `match_confidence=unknown_low_coverage`
   - `coverage=sample_only`
   - `review_required=true`
   - reason: `Only first page sampled; cannot conclude no match.`
7. Apply remains blocked unless confidence is high.

RRR part search uses `/v2/get/parts?search={part_number}` for exact-code matching, with high confidence only when `total_count=1` and exact code field matches.

## RRR part code search / Allegro enrichment matching coverage

- Why page=1 sample is not enough:
  - `/v2/get/parts?limit=100&page=1` can represent only a small slice of catalog.
  - Missing code on page 1 is **not evidence** that part code does not exist globally.
- New diagnostic action: **Probe RRR part search by code** (read-only).
  - Input: `part_number`
  - Reports per-candidate path: `status_code`, `msg`, `pagination.total_count`, `records_count`, `candidate_record_keys`, exact matches, match fields, safe summary, and `filter_effective`.
- `filter_effective=true` only when result set differs from baseline sample, or exact match appears, or API message clearly confirms filtering.
- If no endpoint supports code filtering:
  - Use **Preview paginated RRR part code lookup** (read-only dry-run only).
  - Inputs: `part_number`, `limit` (default 100), `max_pages` (default 3).
  - Reports: `pages_scanned`, `records_scanned`, `total_count`, `exact_matches`, `stopped_reason`.
- Safety rule remains unchanged:
  - apply enrichment requires high-confidence exact single match.
  - no price/stock/images/title/publication/eBay/Allegro/batch changes in this flow.

### What apply may write
Only:
- `_ovoko_part_id` (when high-confidence)
- `_ovoko_car_id`
- `_part_number` (only if missing)
- customer-visible technical attributes for the frontend details table
- `post_content` cleanup when `replace_description=true`

### Old description policy
`replace_description=true` clears legacy text description to align legacy Allegro cards with Ovoko-style details presentation in “Opis oraz informacje szczegółowe”.

### Explicit non-goals in this stage
No changes to:
- prices
- stock
- images/gallery/listing image
- titles
- publication status
- eBay/Allegro publishing
- batch/cron automation

## Ovoko CSV mapping for existing Allegro products

- CSV export z Ovoko (np. `parts-stock-2026-05-25.csv`) może być zaimportowany w panelu **Ovoko CSV mapping file**.
- `Kod producenta` z CSV jest traktowany jako Woo `Numer części` i meta `_part_number` (główny klucz matchingu Allegro -> Ovoko).
- Parser CSV obsługuje delimitery: `;`, `,`, `TAB` (auto-detekcja z fallbackiem na wariant z najbardziej sensownym nagłówkiem).
- Parser normalizuje nagłówki: trim, usunięcie BOM UTF-8, lowercase, normalizacja polskich znaków, redukcja wielokrotnych spacji, sanitizacja znaków specjalnych.
- Rozpoznawanie kolumn (case-insensitive / diakrytyki-insensitive):
  - part code: `Kod producenta`, `kod_producenta`, `manufacturer_code`, `Part number`, `Numer części`, `nr czesci` i warianty,
  - Ovoko part id: `ID`, `Part ID`, `part_id`, `Ovoko ID`, `ID części` i warianty.
- Normalizacja `Kod producenta`: `trim` + `uppercase` + usunięcie spacji + traktowanie jako string + usunięcie końcówki `.0`.
- Diagnostyka importu (`ovoko_csv_mapping_status`) zawiera: `detected_delimiter`, `raw_headers`, `normalized_headers`, `header_map`, `first_row_safe_sample`, `part_code_column_found`, `part_code_column_name`, `id_column_found`, `id_column_name`.
- Matching:
  - 1 rekord po kodzie => `csv_exact_code` (high confidence),
  - >1 rekord po kodzie => `ambiguous_csv_duplicate_code` (review required, bez auto-zapisu),
  - brak => fallback do API search.
- Statystyki: `unique_part_codes` oznacza liczbę unikalnych kodów po normalizacji, `duplicate_part_codes_count` liczbę kodów z wieloma rekordami, a `duplicate_rows_count` sumę wierszy należących do zduplikowanych kodów.
- CSV **nie aktualizuje**: cen, stocków, zdjęć, galerii, listing image, tytułów, statusu publikacji.
- Apply enrichment pozostaje ręczne dla jednego produktu (preview one product / apply one product).

### Plan batch (future)

- dry-run mode,
- limit,
- offset,
- skip ambiguous,
- log zmian.

## Product details style rendering (unified)
- Allegro style is no longer used as product-card style baseline.
- Product details style is enabled when product has at least one of:
  - `_ovoko_part_id`
  - `_ovoko_car_id`
  - customer-visible whitelist detail attributes
- Unified tab layout is: **Opis oraz informacje szczegółowe**.
- Legacy Allegro description is hidden/replaced for products using this style.
- Details table renders only non-empty whitelist fields (no empty table output).
- Same-vehicle button is shown when `_ovoko_car_id` exists.

## Existing Allegro products → Ovoko details enrichment
- `Apply Allegro to Ovoko details enrichment` now writes both:
  - visible custom Woo attributes (`_product_attributes`, visible=true, variation=false),
  - Ovoko detail meta keys read by frontend details table.
- Apply result includes:
  - `details_style_enabled_after_apply`
  - `table_rows_after_apply`
  - `attributes_written`
  - `meta_written_for_table`
- Safety guarantees remain:
  - no price/stock/images/title/publication changes,
  - no eBay/Allegro publish,
  - no batch mode.

## CSV parser: "Informacje o samochodzie"
- Parser supports pattern: `{label} ({period}), {year}, {power} kw, {capacity} cm3/cm³`.
- Extraction:
  - vehicle label (before bracket),
  - period (inside bracket),
  - year (4-digit),
  - power via `(\d+)\s*kw`,
  - capacity via `(\d+)\s*cm3|cm³`.
- Known make dictionary is used for safe split (e.g. Mercedes-Benz, Volkswagen, VW, Audi, BMW, Peugeot, Citroen/Citroën, Renault, Opel, Ford, Toyota, Nissan, Hyundai, Kia, Fiat, Volvo, Skoda/Škoda, Seat).
