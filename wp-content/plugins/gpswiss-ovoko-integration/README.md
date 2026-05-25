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
