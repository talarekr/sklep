# GPSwiss Ovoko Integration

Standalone plugin for Ovoko→Woo callback ingestion and Supply Connector readiness scaffolding in disabled/dry-run mode.

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

## Price source policy

- Ovoko main price (`price`/`original_price`) is **not** automatically treated as WooCommerce selling price.
- Woo target price must come from **Allegro channel price** from RRR/Ovoko payload.
- Policy in preview normalization:
  - `ovoko_price` = payload `price`
  - `ovoko_original_price` = payload `original_price`
  - `allegro_channel_price` = value found in channel/Allegro price fields (if present)
  - `woo_target_price` = `allegro_channel_price`
  - if `allegro_channel_price` is missing:
    - `woo_target_price = null`
    - `price_review_required = true`
    - `price_reason = "Allegro channel price not found; do not import Ovoko price as Woo price."`
- Woo `_price` / `_regular_price` preview mapping is shown as write-ready only when `price_source=allegro_channel_price`.
- If Allegro price is missing, price write preview is blocked in diagnostics.

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
