# GPSwiss Ovoko Integration

Standalone plugin for Ovoko→Woo callback ingestion in dry-run mode and integration readiness diagnostics.

## REST callback endpoint

`POST /wp-json/gpswiss-ovoko/v1/callback`

## Scope in current phase

- Callback receiver with header-secret validation.
- Deduplication by `event_id`.
- `part.status.changed` handling with dry-run action planning.
- Product mapping by configured part-id meta keys.
- Admin readiness page under `Tools → Ovoko Integration`.
- Local dry-run callback simulation.

No mass import/export and no external Ovoko push is implemented.
