# GPS Gmail Product Importer notes

## Ovoko category suggestion by part code investigation (2026-06-08)

The importer has a separate staging-only stage named **Ovoko category suggestion by part code**. It exists because the Ovoko panel may suggest a part category when a main part code is typed even when the read-only existing-part lookup (`POST /v2/get/parts?search=...`) returns no existing inventory match.

### Official API/doc findings

Reviewed the official RRR OpenAPI source at `https://api.rrr.lt/openapi/swagger.yaml` and repo RRR/Ovoko API client/category probes on 2026-06-08. The relevant documented/read-only areas found are:

- `POST /v2/get/parts` with `search`, described as searching within `name` or `manufacturer_code`.
- Category catalog/tree endpoints used elsewhere in this repo, especially `POST /get/categories/tree`, for resolving category names/IDs after a category name is known.
- CRM write endpoints such as `POST /crm/importPart`, `POST /crm/updatePart`, and `POST /crm/changePartStatus`.

No official documented endpoint or field was found for these searched concepts: category suggestion, category autocomplete, part-code category prediction, code recognition, category by `manufacturer_code`, category by part code, or `importPart` UI category prediction.

### Implemented safe resolution order

The stage now tries only staging-safe/read-only sources:

1. `gps_gmail_product_importer_ovoko_category_suggestion_by_part_code` filter. This lets a confirmed Network-capture endpoint or custom connector return a parsed result without changing the workflow.
2. Read-only RRR API candidate probes for category-by-code shaped endpoints. These are treated as candidates only and must return a parseable category name before they are used.
3. Public Ovoko code landing page, for example `https://ovoko.pl/oferta/06K145654L`, parsed as a public no-auth read-only source.
4. Category ID resolution from Woo `product_cat` Ovoko mapping meta (`_gpswiss_ovoko_category_id`, `gpswiss_ovoko_category_id`, `_ovoko_category_id`, `ovoko_category_id`) and then from `POST /get/categories/tree` when RRR API credentials are available.

Persisted statuses are:

- `completed`: category name and Ovoko/RRR category ID were both resolved.
- `needs_manual_category_id`: category name was found, but no Ovoko/RRR category ID could be resolved safely.
- `no_suggestion`: the source returned no category for the code.
- `endpoint_error`: a checked source failed.
- `unavailable`: no configured/safe source could provide a suggestion.

Category mapping uses a category-by-code suggestion only when status is `completed` and a category ID is present. This prevents a category name without a confirmed Ovoko/RRR ID from silently unblocking readiness.

### Manual browser Network capture instructions

To confirm whether the panel category prediction is an official API candidate or a panel-private endpoint:

1. Open the Ovoko panel create/import part form.
2. Open browser DevTools → Network and preserve logs.
3. Clear the Network tab.
4. Type main part code `06K145654L`.
5. Wait until category `Turbina` is auto-selected.
6. Capture the request(s) triggered by the typed part code.
7. Record URL, method, request payload/query parameters, response body, and auth style.
8. Redact cookies, bearer tokens, CSRF values, session IDs, usernames, passwords, and `user_token` values.
9. Classify the request as one of: official API endpoint, API-looking but undocumented, panel-private/session endpoint, or unusable/unsafe.
10. Do not enable automation for a panel-private endpoint without explicit confirmation from Ovoko that the endpoint is stable and allowed for server-to-server use.

### Current staging behavior

- Existing-part lookup still writes only `_gps_ovoko_*` existing-match metadata.
- If no existing match is found, category-by-code metadata is persisted separately under `_gps_ovoko_category_suggestion_*` keys.
- Category suggestion does not create a fake selected existing Ovoko match and does not create a price suggestion.
- Full preparation is item-scoped and staging-only; it does not create Woo products, write to Ovoko, call Allegro, or run CRM-only import.
