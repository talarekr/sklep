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
