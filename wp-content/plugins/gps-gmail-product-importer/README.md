# GPS Gmail Product Importer notes

## Ovoko category suggestion by part code investigation (2026-06-08)

The importer has a separate staging-only stage named **Ovoko category suggestion by part code**. It exists because the Ovoko panel may suggest a part category when a main part code is typed even when the read-only existing-part lookup (`POST /v2/get/parts?search=...`) returns no existing inventory match.

### Official API/doc findings

Reviewed the official RRR OpenAPI source at `https://api.rrr.lt/openapi/swagger.yaml` and repo RRR/Ovoko API client/category probes on 2026-06-08. The relevant documented/read-only areas found are:

- `POST /v2/get/parts` with `search`, described as searching within `name` or `manufacturer_code`.
- Category catalog/tree endpoints used elsewhere in this repo, especially `POST /get/categories/tree`, for resolving a known category tree/path after an Ovoko category ID is already known.
- CRM write endpoints such as `POST /crm/importPart`, `POST /crm/updatePart`, and `POST /crm/changePartStatus`.

No official documented endpoint or field was found for these searched concepts: category suggestion, category autocomplete, part-code category prediction, code recognition, category by `manufacturer_code`, category by part code, or `importPart` UI category prediction.

### Safe resolution policy

A wrong broad category is worse than no category. The stage now marks a suggestion `completed` only when it has an Ovoko/RRR category ID from a high-confidence, code-specific source.

Persisted confidence/source metadata:

- `_gps_ovoko_category_suggestion_confidence`: `high`, `medium`, `low`, or `none`.
- `_gps_ovoko_category_suggestion_source_type`: `official_code_prediction`, `panel_network_capture`, `api_candidate_code_lookup`, `public_ovoko_code_page`, `woo_term_exact_ovoko_id`, `category_tree_fallback`, or `unavailable`.
- Diagnostic endpoint classification: `official_api`, `api_candidate`, `panel_private`, or `unusable`, plus booleans for WordPress safety, browser-session-cookie requirements, and CRM credential usage.

Allowed mapping sources require `status=completed`, `confidence=high`, a non-empty category ID, and one of these source types:

- `official_code_prediction`
- `panel_network_capture`
- `api_candidate_code_lookup`
- `public_ovoko_code_page`
- `woo_term_exact_ovoko_id`

Blocked/downgraded sources:

- `POST /get/categories/tree` and broad category catalogs are **not** direct code-to-category resolvers.
- Generic category-name matching is not enough to complete a category-by-code suggestion.
- Low-confidence text guesses and broad catalog/category-tree fallbacks are stored as `unavailable` or `no_code_specific_suggestion` with `confidence=none` and no category ID/name.
- Example guardrail: part code `06K145654L` must not resolve to category ID `1` / `Brake system` unless a code-specific high-confidence endpoint explicitly returns that value. Until the real panel mechanism is confirmed, the safe expected result is blocked on `missing_ovoko_category_resolution`.

### Implemented safe resolution order

The stage now tries only staging-safe/read-only sources:

1. `gps_gmail_product_importer_ovoko_category_suggestion_by_part_code` filter. A confirmed Network-capture endpoint or custom connector must return `source_type` and `confidence`; otherwise the result is downgraded.
2. Read-only RRR API candidate probes for category-by-code shaped endpoints. These are treated as code-specific candidates only when the response itself contains a parseable category ID and category name.
3. Public Ovoko code landing page, for example `https://ovoko.pl/oferta/06K145654L`, parsed as a public no-auth read-only source. It may complete only when a category ID is present; broad tree lookup is not used to manufacture one.

Persisted statuses are:

- `completed`: category name and Ovoko/RRR category ID were resolved from a high-confidence, code-specific source.
- `no_code_specific_suggestion`: a source returned only broad/weak/non-code-specific category data, so the category was discarded.
- `no_suggestion`: the source returned no category for the code.
- `endpoint_error`: a checked source failed.
- `unavailable`: no configured/safe source could provide a suggestion.

Category mapping uses a category-by-code suggestion only when status is `completed`, confidence is `high`, the source type is trusted, and a category ID is present. This prevents a category name or category-tree fallback from silently unblocking readiness.

### Diagnostic tool: Test Ovoko category prediction by code

The admin page includes **Test Ovoko category prediction by code**. Default input is part code `06K145654L` and expected panel category `Turbina`. The diagnostic is read-only and reports:

- every attempted source and endpoint, including safe API candidates and any pasted capture,
- request parameters with password/token/session values redacted,
- response status/body excerpt,
- parsed category ID/name,
- confidence and source type,
- endpoint classification: `official_api`, `api_candidate`, `panel_private`, or `unusable`,
- whether the endpoint is safe to call from WordPress, requires browser session cookies, or uses the same RRR credentials as `/crm/importPart`,
- whether the parsed category matches the expected panel result (`Turbina` for `06K145654L`),
- final selected category source, or the exact next Network capture action required.

The diagnostic probes likely read-only API/category shapes, including category-by-code candidates and catalog/tree endpoints (`/v2/get/categories`, `/v2/get/categories/tree`, `/get/categories`, `/get/categories/tree`, `/v2/get/parts/categories`, `/get/part/categories`, `/get/parts/categories`). Catalog/tree endpoints are reported for evidence only and are never accepted as code prediction unless Ovoko exposes a code-specific endpoint returning the category for the submitted code.

If a captured/API endpoint returns `Turbina` and an Ovoko category ID for `06K145654L`, it is accepted only when classified as `official_api` or `api_candidate`, safe for WordPress server-to-server use, code-specific, and not dependent on browser cookies. If it is `panel_private` or session-only, the plugin does not silently automate it; the blocker is an Ovoko-confirmed official/API-equivalent endpoint or written confirmation that the captured endpoint is stable and permitted for server-to-server use.

### Manual browser Network capture instructions

To confirm whether the panel category prediction is an official API candidate or a panel-private endpoint:

1. Open the Ovoko panel create/import/add part form.
2. Open browser DevTools → Network and preserve logs.
3. Clear the Network tab.
4. Type main part code `06K145654L`.
5. Wait until category `Turbina` is auto-selected.
6. Capture the request that returns `Turbina` and/or the category ID.
7. Record URL, method, request payload/query parameters, response body, response status, and auth style.
8. Redact cookies, bearer tokens, CSRF values, session IDs, usernames, passwords, and `user_token` values.
9. Paste the redacted request/response into **Test Ovoko category prediction by code** so it can extract the detected input code, category ID/name, endpoint classification (`official_api`, `api_candidate`, `panel_private`, `unusable`), WordPress safety, browser-cookie requirements, and whether it uses the same API credentials as `/crm/importPart`.
10. Do not enable automation for a panel-private endpoint without explicit confirmation from Ovoko that the endpoint is stable and allowed for server-to-server use.

### Current staging behavior

- Existing-part lookup still writes only `_gps_ovoko_*` existing-match metadata.
- If no existing match is found, category-by-code metadata is persisted separately under `_gps_ovoko_category_suggestion_*` keys.
- Category suggestion does not create a fake selected existing Ovoko match and does not create a price suggestion.
- Full preparation is item-scoped and staging-only; it does not create Woo products, write to Ovoko, call Allegro, or run CRM-only import.
