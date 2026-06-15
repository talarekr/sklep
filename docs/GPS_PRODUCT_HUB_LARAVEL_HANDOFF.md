# GPS Product Hub — Laravel Repository Handoff Specification

This is the self-contained implementation handoff for the future Laravel repository for **gpswiss.pl / GPS Product Hub**. It consolidates storefront rebuild planning and broader Product Hub architecture into one file that can be copied into the new Laravel GitHub repository and used by a Laravel developer or Codex agent as the implementation specification.

This document is planning-only. Do **not** modify the existing WordPress theme, WooCommerce templates, plugins, marketplace integrations, database, production configuration, or production behavior while using this handoff.

---

## 1. Executive summary

### Why GPS Product Hub is needed

GPSWISS currently relies on WordPress/WooCommerce plus several marketplace-specific integrations. The long-term goal is to create **GPS Product Hub**, a Laravel-based operational source of truth for product intake, enrichment, pricing, category/shipping mapping, readiness checks, stock, orders, marketplace publishing, and the future customer-facing Laravel storefront.

The new system is needed because product data and publishing logic currently flow through a fragile dependency chain where WooCommerce and marketplace plugins act as both storefront and integration layer. This makes it harder to maintain consistent product data, safely onboard parts, publish to multiple channels, audit errors, manage stock, and eventually add new partners.

### Current dependency problem

The current architecture has marketplace and storefront dependencies in the wrong order:

- **Ovoko → Allegro**
- **Ovoko → WooCommerce**
- **WooCommerce → eBay DE/FR**

Problems caused by this chain:

- WooCommerce is overloaded as storefront, product data store, marketplace bridge, and operational workflow tool.
- Marketplace-specific data and category logic can leak into frontend behavior.
- Product enrichment, pricing research, publishing readiness, stock sync, and error handling are distributed across plugins.
- Adding future marketplaces/partners requires more plugin coupling instead of a clean domain model.
- Operational decisions such as duplicate detection, price confidence, fitment readiness, and channel-specific validation are difficult to audit centrally.

### Target source-of-truth architecture

The target direction is:

**GPS Product Hub → Laravel storefront → eBay DE/FR → Allegro → Ovoko → future partners**

Interpretation:

- **GPS Product Hub** becomes the product and workflow source of truth.
- **Laravel storefront** becomes the customer-facing shop when ready.
- **eBay DE/FR, Allegro, Ovoko, and future partners** become channel adapters fed by Product Hub readiness and publishing services.
- WooCommerce remains live during migration and can be updated by Product Hub during transitional phases.

### Migration posture

- WordPress/WooCommerce remains live until Laravel data sync, storefront parity, checkout/payment, SEO, redirects, and operational readiness are approved.
- Laravel should first run alongside WooCommerce on staging/subdomain.
- No live marketplace writes should happen from Laravel until explicit approval gates and duplicate guards are implemented.
- The first Laravel repository tasks should build skeletons, schemas, admin workflows, and placeholder storefront DTOs—not live channel publishing.

---

## 2. Technology stack decision

### Core application

- **Framework:** Laravel.
- **PHP target:** PHP 8.3+ recommended. PHP 8.2 is acceptable if hosting constraints require it, but new code should be typed and compatible with current Laravel LTS/current stable requirements.
- **Admin panel:** Filament for Product Hub operations, queue visibility links, resource CRUD, review screens, readiness dashboards, and role-aware workflows.

### Database recommendation

Preferred: **PostgreSQL** for the new Product Hub.

Reasoning:

- Strong relational integrity for product/channel/order/stock/audit data.
- Better JSONB support for raw marketplace payload snapshots, readiness detail payloads, channel errors, and staging enrichment data.
- Good indexing options for structured plus semi-structured product metadata.
- Clean support for future reporting, reconciliation, and audit workflows.

Acceptable alternative: **MySQL/MariaDB** if operational familiarity or hosting constraints dominate. If MySQL is used, design JSON columns and indexes carefully and keep channel payload snapshots bounded/archived.

### Cache, queues, and jobs

- **Redis** for queues, cache, rate limiting, locks, and idempotency/duplicate guards.
- Use Laravel Horizon if Redis queues are used in production.
- All marketplace/API work should run through queued jobs with retry, backoff, idempotency keys, and audit logging.

### Storefront stack

- **Blade** for SEO-friendly server-rendered pages.
- **Livewire** for mini-cart, cart, filters where appropriate, checkout interactions, account flows, and stateful UI islands.
- **Alpine.js** for dropdowns, mega menu, mobile menu, modal toggles, tabs, gallery controls, and lightweight loading states.
- **Vite** for storefront/admin asset builds.
- **Custom CSS first** for storefront parity. Preserve useful `.gp-*` naming and visual tokens from the current storefront. Tailwind can be used for Filament/admin or internal tooling, but the public storefront should not be redesigned through utility classes before 1:1 parity is approved.

### Images and storage

- Use object storage for product images and generated variants, e.g. S3-compatible storage, Cloudflare R2, DigitalOcean Spaces, or another durable object store.
- Store original images, derived web variants, thumbnails, and legacy source URLs.
- Preserve old `/wp-content/uploads/...` URLs during migration via redirects/proxy or a compatibility map.

### Search

- **MVP:** database search using normalized fields for product title, SKU, OEM/part number, category, and vehicle/model terms.
- **Future:** Meilisearch, Typesense, or OpenSearch once catalog size, latency requirements, typo tolerance, synonyms, and part-number normalization needs justify it.

### Monitoring and backups

- Application monitoring: Laravel logs, queue failure alerts, Horizon metrics, uptime checks, exception reporting.
- Backups: database dumps, object storage lifecycle/replication, encrypted credential backups, tested restore process.
- Channel monitoring: failed jobs, channel API errors, stale listings, stock mismatches, publish readiness regressions.

---

## 3. Modular architecture

Implement as a modular Laravel monolith first. Keep module boundaries clear with namespaces, services, DTOs, policies, jobs, events, and Filament resources.

### Modules

1. **Product Catalog**
   - Canonical products, identifiers, attributes, categories, descriptions, status, and channel-neutral product state.
2. **Staging / Intake**
   - Captured parts before becoming products; supports manual, mobile PWA, API, and Gmail legacy intake.
3. **Mobile PWA Intake**
   - Warehouse/mobile capture interface for photos, OEM, bin, condition, notes, duplicate detection, and upload progress.
4. **Images**
   - Original images, image variants, ordering, source URLs, placeholders, watermarks caution, and channel-specific image rules.
5. **Vehicle Fitment**
   - Vehicle data, compatibility rows, OEM/part fitment, source confidence, and channel payload mapping.
6. **Pricing**
   - Base price, channel price rules, Allegro price research, manual overrides, currency conversion, and price audit history.
7. **Category / Shipping Mapping**
   - Internal categories mapped to Woo, eBay DE/FR, Allegro, Ovoko, required aspects/attributes, and shipping group rules.
8. **Readiness Engine**
   - Validates products before product creation, storefront publication, Woo sync, and marketplace publication.
9. **Channel Listings**
   - Stores per-channel listing state, external IDs, URLs, policies, category IDs, quantities, status, errors, and sync timestamps.
10. **Marketplace Publishing**
    - Jobs/adapters for Woo, eBay DE/FR, Allegro, Ovoko future publishing, stock/order sync, and reconciliation.
11. **Orders**
    - Orders from storefront/Woo/channels, order items, customer data, status, channel references, and fulfillment sync.
12. **Stock**
    - Stock items, reservations, movements, channel allocations, stock truth, and mismatch reconciliation.
13. **Users / Roles / Permissions**
    - Admin users, warehouse users, pricing reviewers, publishing approvers, support users, partner users later.
14. **Audit Logs**
    - Append-only history of changes, approvals, publish attempts, price changes, stock changes, and external sync payloads.
15. **Partner Portal later**
    - Future external partner access to selected stock/catalog/order workflows, permissioned separately from internal admin.

---

## 4. Core database model

The exact schema can evolve, but the following entities should exist early. Use UUIDs only if the team prefers; integer primary keys are acceptable. Always include timestamps. Add `created_by`, `updated_by`, or audit records where operationally important.

| Table | Purpose | Key fields | Relationships |
|---|---|---|---|
| `products` | Canonical sellable products | `id`, `sku`, `slug`, `name`, `display_name`, `description`, `short_description`, `status`, `source`, `base_price`, `currency`, `stock_status`, `primary_image_id`, `published_at`, `archived_at` | Has many identifiers, images, categories, attributes, fitments, stock items, channel listings, order items |
| `product_identifiers` | OEM, MPN, SKU aliases, marketplace IDs | `id`, `product_id`, `type`, `value`, `normalized_value`, `source`, `confidence`, `is_primary` | Belongs to product; used by duplicate detection, search, fitment, pricing |
| `product_images` | Product image records and variants | `id`, `product_id`, `storage_path`, `url`, `legacy_url`, `alt`, `role`, `sort_order`, `width`, `height`, `checksum`, `source`, `is_primary` | Belongs to product; may originate from staging item images |
| `categories` | Internal and storefront/channel category tree | `id`, `parent_id`, `slug`, `path`, `name`, `description`, `type`, `is_visible_storefront`, `sort_order`, `external_source` | Self-referencing tree; many-to-many products; mapped to channels |
| `product_categories` | Product/category assignment | `product_id`, `category_id`, `is_primary` | Belongs to product and category |
| `product_attributes` | Product details/specifications | `id`, `product_id`, `name`, `value`, `normalized_name`, `is_visible`, `source`, `sort_order` | Belongs to product; feeds details tabs and channel aspects |
| `vehicles` | Vehicle models/versions for fitment | `id`, `make`, `model`, `generation`, `year_from`, `year_to`, `engine`, `body`, `k_type`, `source` | Has many vehicle fitments |
| `vehicle_fitments` | Compatibility between products and vehicles | `id`, `product_id`, `vehicle_id`, `oem`, `notes`, `source`, `confidence`, `payload` | Belongs to product and vehicle; feeds eBay compatibility |
| `stock_items` | Current stock/warehouse state | `id`, `product_id`, `location`, `bin`, `quantity`, `reserved_quantity`, `available_quantity`, `condition`, `status` | Belongs to product; has many stock movements |
| `stock_movements` | Stock history and reservations | `id`, `stock_item_id`, `product_id`, `type`, `quantity_delta`, `reason`, `channel`, `order_id`, `created_by` | Belongs to stock item/product/order; auditable |
| `staging_items` | Intake records before product creation | `id`, `status`, `raw_oem`, `normalized_oem`, `title`, `notes`, `condition`, `location`, `bin`, `duplicate_product_id`, `source`, `payload`, `created_by` | Can create product; has images; has price suggestions/readiness checks |
| `price_suggestions` | Suggested prices from research/rules | `id`, `product_id`, `staging_item_id`, `source`, `suggested_price`, `currency`, `min_price`, `median_price`, `max_price`, `confidence`, `payload`, `accepted_at`, `accepted_by` | Belongs to product or staging item |
| `channel_listings` | Per-channel listing state | `id`, `product_id`, `channel`, `status`, `external_listing_id`, `external_offer_id`, `external_sku`, `url`, `category_id`, `policy_ids`, `price`, `currency`, `quantity`, `published_at`, `last_synced_at`, `error_status`, `readiness_status` | Belongs to product; has many channel errors/publish jobs |
| `channel_errors` | Channel API/readiness/sync errors | `id`, `channel_listing_id`, `channel`, `code`, `message`, `severity`, `payload`, `resolved_at`, `resolved_by` | Belongs to channel listing; visible in Error Center |
| `publish_jobs` | Publishing attempts and state | `id`, `product_id`, `channel_listing_id`, `channel`, `job_type`, `status`, `idempotency_key`, `attempts`, `payload`, `response`, `started_at`, `finished_at` | Belongs to product/listing; associated with queued jobs |
| `orders` | Storefront/channel orders | `id`, `channel`, `external_order_id`, `customer_email`, `customer_name`, `status`, `currency`, `subtotal`, `shipping_total`, `tax_total`, `total`, `placed_at`, `synced_at` | Has many order items; may create stock movements |
| `order_items` | Order product lines | `id`, `order_id`, `product_id`, `external_item_id`, `sku`, `name`, `quantity`, `unit_price`, `line_total` | Belongs to order/product |
| `users` | Internal/admin/customer users as appropriate | `id`, `name`, `email`, `password`, `status`, `last_login_at` | Has roles/permissions; creates audit logs |
| `roles` | Role names and scopes | `id`, `name`, `guard_name`, `description` | Many-to-many users/permissions |
| `permissions` | Permission names | `id`, `name`, `guard_name`, `description` | Many-to-many roles/users |
| `audit_logs` | Append-only audit trail | `id`, `actor_id`, `subject_type`, `subject_id`, `action`, `before`, `after`, `metadata`, `ip_address`, `created_at` | Belongs to actor; polymorphic subject |

---

## 5. Product lifecycle and staging flow

### Statuses

Use these statuses for `staging_items`, product workflow, or readiness state as appropriate:

1. `captured` — item entered through mobile/manual/API/Gmail legacy intake.
2. `needs_oem_review` — OCR/manual input is missing, invalid, or low confidence.
3. `duplicate_candidate` — possible existing product/listing detected.
4. `enrichment_pending` — waiting for enrichment from Ovoko/read-only sources or internal rules.
5. `enriched` — enough enrichment data has been attached for review.
6. `price_suggested` — price research/rules produced a suggestion.
7. `category_mapped` — internal and channel category/shipping mapping exists.
8. `ready_to_product` — staging item can be converted into canonical product.
9. `product_created` — canonical product exists.
10. `ready_to_publish` — readiness engine passed for selected channels.
11. `published` — published to selected channel/storefront.
12. `error` — blocked by validation, enrichment, publishing, sync, or operator error.
13. `archived` — intentionally removed from active workflow.

### Intake sources

- **Mobile PWA intake:** warehouse capture with camera/photos, OEM, condition, bin, notes.
- **Manual admin intake:** Filament form for staff-entered parts.
- **Gmail legacy intake:** importer/adapter can create staging records from legacy email flows; treat as transitional.
- **API intake:** future endpoint for partners/internal systems.

### Flow

1. Create `staging_items` with raw metadata and photos.
2. Normalize OEM/identifiers and run duplicate detection.
3. Enrich read-only from Ovoko/internal sources where allowed.
4. Research Allegro prices by OEM/part number.
5. Generate price suggestion and confidence.
6. Map internal category, channel categories, required attributes, and shipping group.
7. Run readiness checks.
8. Convert approved staging item to product.
9. Generate images/variants and storefront DTO data.
10. Publish/sync to Woo during migration and marketplaces only after approval gates.

---

## 6. Mobile intake PWA

The mobile PWA is a warehouse-first intake interface. It should be simple, fast, and resilient to imperfect connectivity.

### Required capabilities

- Camera capture directly from mobile device.
- Multiple photos per item with preview, remove, and reorder.
- Optional OEM OCR/scan from image or barcode where practical.
- Manual OEM correction and normalized OEM preview.
- Location/bin capture.
- Condition selection.
- Notes field for staff observations.
- Duplicate detection by normalized OEM, title, vehicle, source IDs, or image checksum where available.
- Upload progress for each image and the staging item.
- Staging item creation with `captured` or review status.
- Optional offline-tolerant behavior: local draft queue, retry uploads, conflict warning if duplicate detected after reconnect.

### Acceptance criteria

- A warehouse user can capture a part with three photos, OEM, bin, condition, and notes in under one minute on mobile.
- Failed uploads can be retried without duplicating staging items.
- Duplicate warnings do not block capture but require review before product creation.
- No live marketplace write occurs from mobile intake.

---

## 7. Current storefront rebuild summary

Existing storefront documentation inspected the current WordPress/WooCommerce theme and customer-facing behavior. The Laravel storefront must visually reproduce this before replacing Woo.

### Theme/template areas inspected

- Theme bootstrap and hooks: `functions.php`.
- Layout templates: `header.php`, `footer.php`, `front-page.php`, `page.php`, `search.php`, static page templates.
- Home/header partials: `template-parts/home/*.php`.
- Product card partial: `template-parts/product/product-card.php`.
- WooCommerce overrides: `woocommerce/archive-product.php`, `woocommerce/single-product.php`, `woocommerce/content-single-product.php`, `woocommerce/content-product.php`, `woocommerce/cart/cart-totals.php`, `woocommerce/checkout/review-order.php`, email header/footer overrides.
- Assets: `style.css`, `assets/css/woocommerce.css`, `assets/js/home.js`, `assets/js/profile-auth.js`, `assets/js/cart-checkout.js`, `assets/js/single-product.js`, `assets/js/language-switcher.js`.

### Storefront areas to copy visually

- **Homepage:** top/header, hero banner, popular/new product sections, footer.
- **Header:** logo, language selector, Rzetelna Firma badge, search, profile dropdown, cart action, phone/contact links.
- **Navigation/mega menu:** `Menu` trigger, root categories, level-2 panels, level-3 links, category shortcuts.
- **Mobile menu:** accordion-style nested category navigation below current responsive breakpoint.
- **Footer:** legal/contact columns and mobile collapse.
- **Category archives:** category hero/search panel, breadcrumbs, sidebar filters, toolbar, sorting, product grid, pagination.
- **Search:** header search, category part-number/model search modes, empty results behavior.
- **Product card:** image fallback, wishlist heart, part number, formatted title, price, delivery text/cutoff.
- **Product detail:** gallery, info card, trust blocks, purchase box, tabs/specifications, same-vehicle CTA for Ovoko products.
- **Cart:** item list, quantities, remove, totals, free delivery row, checkout CTA.
- **Checkout:** billing/shipping/payment/order summary, terms, validation, gateway-rendered payment methods.
- **Account/auth:** profile dropdown, login/register, Google OAuth if enabled, account/orders/returns.
- **Static pages:** contact, terms, privacy, returns/shipping.
- **404:** same header/footer/search context with helpful navigation.

---

## 8. Laravel storefront component plan

| Component | Data requirements | Acceptance criteria |
|---|---|---|
| App layout | HeaderDTO, FooterDTO, SeoMetaDTO, flash notices, content slot | All public pages share correct header/footer, meta, notices, and responsive container |
| Header | logo, selected language, contact links, account state, cart count, category shortcuts | Matches current desktop/tablet/mobile screenshots |
| Top bar | promo text, variant, closable flag | Closes when configured and does not shift layout unexpectedly |
| Search bar | query, action URL, placeholder, mode, hidden params | Submits legacy-compatible product search and is usable at 390px |
| Mega category menu | CategoryTreeDTO roots/children/grandchildren, active root | Desktop hover/focus panel and visible category tree match current behavior |
| Mobile menu | same CategoryTreeDTO, shortcut links, phones | Nested mobile accordion opens/closes and does not expose technical categories |
| Mini-cart drawer | CartDTO, CartItemDTO rows, totals, checkout/cart URLs | Empty/populated drawer, qty/remove, count updates, and loading states work without reload |
| Auth modal | auth state, login/register URLs, Google OAuth config, checkout URL | Guest checkout CTA opens modal; logged-in state shows account/order links |
| Footer | legal links, contact data, optional badges/social | Footer content and mobile collapse match current storefront |
| Product card | ProductListItemDTO, ImageDTO, PriceDTO, delivery labels | Image fallback, part number, title, price, delivery labels match current cards |
| Product grid | paginator, ProductListItemDTO collection, sort/filter state | Archive grid, result count, pagination, empty state match Woo behavior |
| Category archive | CategoryArchiveDTO, breadcrumbs, filters, category hero data | Hero/sidebar/toolbar/grid/sorting/pagination match current pages |
| Search results | SearchResultDTO, query/mode, paginator | Title and part-number searches return comparable results and empty state |
| Product gallery | ImageDTO list, active image, alt text | Gallery, thumbnails, arrows, lightbox/zoom match Woo gallery behavior |
| Purchase box | product ID, PriceDTO, StockDTO, quantity, cart state, contact URL | Price note, add-to-cart, quantity, contact CTA, helper copy match current product page |
| Product tabs | description HTML, visible attributes, warranty/seller copy, FitmentDTO | Standard and Ovoko tabs/specification behavior match source |
| Related products | ProductListItemDTO collection | Related/upsell grid uses standard product cards and hides when empty |
| Cart | CartDTO, CartItemDTO, coupons/totals | Item cleanup, qty/remove, totals, free delivery label, checkout CTA match current behavior |
| Checkout | CheckoutDTO, CartDTO, payment/shipping methods, terms/privacy URLs | Required fields, order summary, payment methods, shipping display, validation match approved production behavior |
| Order received | order summary, payment status, customer email | Confirmation page displays order number/status/totals/payment status |
| Account pages | user, orders, returnable items, statuses | Login/register/account/orders/returns work on staging with migrated/synced data |
| Static pages | slug, title, body, SEO, contact form config | Contact/legal/privacy/returns content and forms match current pages |
| 404 | search props, category shortcuts, home/shop URLs | Returns HTTP 404 with helpful storefront navigation |

---

## 9. Storefront DTO contracts

| DTO | Required fields | Optional fields / notes |
|---|---|---|
| `ProductListItemDTO` | `id`, `slug`, `url`, `title`, `displayTitle`, `price`, `listingImage`, `inStock`, `deliveryLabel`, `deliveryCutoffLabel` | `sku`, `partNumber`, `regularPrice`, `source`, `ovokoCarId` |
| `ProductDetailDTO` | list item fields, `stock`, `gallery`, `categories`, `descriptionHtml`, `visibleAttributes`, `seo` | `oemNumber`, `shortDescriptionHtml`, `fitment`, `sameVehicleUrl`, `relatedProducts` |
| `CategoryDTO` | `id`, `slug`, `path`, `name`, `url`, `isVisible` | `description`, `parentId`, `level`, `productCount`, `seo` |
| `CategoryTreeDTO` | `roots`, `childrenByParent`, `activeCategoryId`, `lineageIds` | `debugVersion`, cache/build metadata |
| `ImageDTO` | `url`, `alt`, `role` | `id`, `srcset`, `width`, `height`, `sortOrder`, `legacyUrl`, `placeholder` |
| `PriceDTO` | `amount`, `currency`, `formatted` | `regularAmount`, `saleAmount`, `taxIncluded`, `convertedFromCurrency`, `exchangeRate` |
| `StockDTO` | `status`, `isPurchasable`, `isInStock` | `quantity`, `availabilityLabel`, `backordersAllowed` |
| `CartDTO` | `items`, `subtotal`, `total`, `currency`, `itemCount` | `shippingTotal`, `discountTotal`, `fees`, `taxTotal`, `checkoutUrl`, `requiresAuthModal` |
| `CartItemDTO` | `key`, `productId`, `displayName`, `quantity`, `unitPrice`, `lineTotal` | `name`, `url`, `thumbnail`, `regularPrice`, `meta` |
| `CheckoutDTO` | `cart`, `customer`, `billingFields`, `paymentMethods`, `termsUrl` | `shippingFields`, `shippingMethods`, `privacyUrl`, `orderNotesEnabled`, `validationErrors` |
| `SearchResultDTO` | `query`, `results`, `pagination` | `mode`, `normalizedQuery`, `filters`, `sort`, `emptyState`, `suggestions` |
| `FitmentDTO` | none globally; required only where fitment is visible | `rows`, `source`, `vehicleModels`, `oemNumbers`, `ovokoCarId`, `sameVehicleUrl`, `confidence` |
| `SeoMetaDTO` | `title`, `canonicalUrl`, `robots`, `breadcrumbs` for public pages | `description`, `openGraph`, `structuredData`, `paginationLinks` |

---

## 10. Design system and visual parity

### Visual tokens

Current storefront CSS tokens to preserve:

- `--gp-bg: #f5f5f5`
- `--gp-text: #1f1f1f`
- `--gp-muted: #6a6a6a`
- `--gp-border: #d8d8d8`
- `--gp-primary: #d82a2a`
- `--gp-navy: #122a66`
- `--gp-red: #e10613`

### Typography

- Poppins, weights 400/500/600/700.
- Base body: 14px, line-height 1.45.
- Preserve current header search, product card, price, and section title sizes until screenshot parity is approved.

### Layout and UI rules

- Main container max width: 1320px with 12px horizontal padding.
- Header desktop grid: logo, large search, account/cart actions.
- Product archive desktop grid: 3 columns; verify tablet/mobile counts by screenshot.
- Home product grid and archive grid may differ; validate separately.
- Buttons: navy primary, navy outline, bold search/checkout CTAs.
- Forms: preserve border radius, heights, labels, placeholders, validation styles.
- Product cards: preserve image ratio/object-fit, part number label, title formatting, price, delivery labels, favorite heart behavior.
- Notices: create Laravel equivalents for Woo success/error/info notices.
- Loading states: add subtle disabled/loading states for Livewire actions without layout shift.

### Breakpoints and screenshot QA

Validate at:

- Desktop: `1440px`
- Laptop: `1280px`
- Tablet: `768px`
- Mobile: `390px`

For each viewport capture:

- Homepage
- Category archive
- Product detail
- Cart
- Checkout
- Mobile menu where applicable
- Search
- Mini-cart

Additional captures:

- Desktop mega menu open.
- Mobile nested menu expanded.
- Logged-out profile dropdown.
- Auth modal from checkout CTA.
- Standard product and Ovoko product detail.
- Empty search/category state.
- 404 page.

---

## 11. Marketplace/channel model

### Channels

- Laravel storefront
- Woo during migration
- eBay DE
- eBay FR
- Allegro
- Ovoko
- Future partners

### `channel_listings` fields

Each product can have one or more channel listings. Store at least:

- `channel`
- `status`
- `external_listing_id`
- `external_offer_id`
- `external_sku`
- `url`
- `category_id`
- `policy_ids`
- `price`
- `quantity`
- `published_at`
- `last_synced_at`
- `error_status`
- `readiness_status`

### Channel principles

- Every publish/update action must be idempotent.
- Duplicate guards must run before creating external listings.
- Channel payloads and responses should be stored or summarized for audit/debugging.
- Stock/order sync must reconcile differences, not blindly overwrite without rules.
- Channel-specific validation belongs in readiness checks before publish jobs run.

---

## 12. eBay DE/FR requirements

### API approach

- Prefer eBay **Inventory API** for inventory items, offers, and publication flow.
- Use marketplace-specific offer/listing configuration for DE and FR.
- Use product compatibility endpoint/payload where category supports vehicle fitment.

### Required capabilities

- Marketplace-specific content for **DE** and **FR**:
  - translated title/description where needed,
  - marketplace category IDs,
  - required item aspects,
  - business policies,
  - price/currency rules.
- Category mapping from internal category to eBay DE and eBay FR categories.
- Required aspects validation before publish.
- Product compatibility payload generated from `vehicle_fitments`/`FitmentDTO`.
- NBP EUR conversion for FR if business rules require pricing from PLN base.
- Shipping groups: `shipping_30`, `shipping_50`, `shipping_130`.
- Business policies: payment, return, fulfillment/shipping policy IDs per marketplace.
- Stock sync and order sync.
- Duplicate guards using SKU, external listing IDs, active listing validation, and channel listing records.
- Active listing validation before publish/update to prevent duplicate live offers.

### Acceptance criteria

- A product cannot publish to eBay DE/FR until required category, aspects, shipping group, business policies, price, stock, images, and duplicate checks pass.
- Failed API responses create channel errors with actionable messages.
- Re-running a publish job does not create duplicate listings.

---

## 13. Ovoko adaptor requirements

### Phase 1: read-only enrichment

- Use Ovoko only for allowed read-only enrichment first.
- Store source metadata and confidence.
- Do not perform blind scraping or undocumented automated extraction.
- Respect API/terms limitations.

### Future phases

- Future publishing can be considered after Product Hub source-of-truth and readiness rules are stable.
- Stock sync later, only after channel listing reconciliation and stock truth are defined.

### Image/watermark caution

- Treat Ovoko images carefully.
- Preserve source attribution and license/usage constraints.
- Avoid publishing watermarked or restricted images to other channels unless explicitly allowed.

---

## 14. Allegro adaptor requirements

### Price research first

- Search by OEM/part number and relevant title terms.
- Capture comparable offers and compute min/median/max.
- Filter outliers and irrelevant listings.
- Store confidence and raw/reference payload summary.
- Do not automatically apply price suggestions without rules and approval thresholds.

### Later publishing

- Publishing to Allegro can be added after pricing, category mapping, readiness checks, stock, and duplicate guards are stable.
- Channel listing records must store external offer ID, SKU, URL, status, price, quantity, category, and errors.

---

## 15. Pricing strategy

### Fields and concepts

- `base_price`: canonical internal price, likely PLN.
- `price_source`: manual, Allegro research, imported Woo price, rule-based, channel override.
- `allegro_suggestion`: min/median/max, chosen suggestion, confidence.
- `manual_override`: explicit approved price that supersedes suggestions.
- `channel_price_rules`: marketplace-specific markup, rounding, currency conversion, fees, shipping inclusion if required.
- `currency_conversion`: NBP EUR conversion for FR/euro channels where required.
- `rounding`: define per channel, e.g. nearest 1/5/9 ending.
- `audit_history`: every price suggestion, acceptance, override, and channel price change.

### Acceptance criteria

- No automatic price changes without recorded source and audit trail.
- Manual override is visible and protected from accidental overwrite.
- Channel prices can be explained from base price + rules + conversion + rounding.

---

## 16. Category/shipping mapping

### Mapping model

Each internal category should map to:

- Internal storefront category.
- Woo category during migration.
- eBay DE category.
- eBay FR category.
- Allegro category.
- Ovoko category if needed.
- Required attributes/aspects per channel.
- Shipping group: `shipping_30`, `shipping_50`, or `shipping_130`.
- Shipping cost/rule metadata.

### Acceptance criteria

- Readiness engine blocks publishing when category mapping is missing or stale.
- Shipping group is visible to staff and auditable.
- Required aspects are derived from channel category and shown before publish.

---

## 17. Readiness engine

The readiness engine should produce channel-specific and global checks with severity, message, responsible module, and resolution guidance.

### Required checks

- Missing OEM.
- Missing images.
- Missing price.
- Missing category.
- Missing vehicle data.
- Missing eBay aspects.
- Missing shipping group.
- Missing business policy.
- Duplicate listing.
- Stock unavailable.
- Translation/content missing.
- Stale external mapping.

### Acceptance criteria

- Staff can see why a product is blocked.
- Checks can be rerun after edits.
- Channel publish jobs refuse to run if blocking readiness checks fail.
- Readiness status is stored in `channel_listings` and/or readiness tables for filtering.

---

## 18. Admin UI plan

Use Filament resources/pages for these screens:

1. **Dashboard** — product counts, staging counts, publish errors, stock/order sync health.
2. **Product Command Center** — searchable product list with readiness and channel status.
3. **Mobile Intake Queue** — captured staging items needing review.
4. **Staging Items** — intake records, duplicate candidates, enrichment state.
5. **Product Details** — canonical product editing and audit context.
6. **Images** — upload, reorder, variants, source/legacy URLs.
7. **Vehicle/Fitment** — vehicle compatibility and confidence.
8. **Pricing** — base price, suggestions, override, channel price rules.
9. **Channel Listings** — per-channel listing state, IDs, URLs, sync status.
10. **Readiness** — blocking checks and remediation actions.
11. **Publish Center** — approval and controlled publish/update jobs.
12. **Orders** — storefront/Woo/marketplace orders and sync status.
13. **Stock** — stock items, movements, reservations, mismatches.
14. **Error Center** — failed jobs, channel errors, retry/resolution workflow.
15. **Import/Export** — CSV/report imports/exports and migration tools.
16. **Settings** — channel credentials, business policies, shipping groups, mappings.
17. **Users/Roles** — staff, roles, permissions.
18. **Partner Portal later** — restricted future partner views/actions.

---

## 19. Queue/job list

Implement jobs with idempotency, audit logging, retries/backoff, and error records where appropriate.

- `ProcessStagingItem`
- `NormalizeOem`
- `DetectDuplicates`
- `EnrichFromOvoko`
- `ResearchAllegroPrices`
- `GeneratePriceSuggestion`
- `MapCategoryAndShipping`
- `RunReadinessChecks`
- `CreateOrUpdateWooProduct`
- `PublishEbayDE`
- `PublishEbayFR`
- `SyncEbayOrders`
- `SyncStock`
- `GenerateImageVariants`
- `ExportReports`
- `ReconcileChannelListings`

No live channel write job should be enabled in production until credentials, duplicate guards, readiness checks, approval workflow, and rollback/reconciliation procedures are approved.

---

## 20. Migration plan

### Phase 1 — Laravel side system while Woo remains live

- Create Laravel repo, infrastructure, database, queues, Filament admin skeleton, roles, and base modules.
- Import or sync read-only product/category/image snapshots for development.
- No live marketplace writes.

### Phase 2 — Mobile intake/staging in Laravel

- Build mobile PWA intake.
- Create staging items, images, duplicate checks, enrichment placeholders.
- Staff review happens in Filament.

### Phase 3 — Product Hub creates/updates Woo via API

- Product Hub can create/update Woo products after approval.
- Woo remains live storefront and order source.
- Audit all writes and keep rollback/reconciliation reports.

### Phase 4 — Product Hub controls marketplace publish logic while Woo remains frontend

- Move eBay DE/FR readiness/publishing decisions into Product Hub.
- Use duplicate guards and channel listing records.
- Woo still serves customer frontend.

### Phase 5 — Laravel storefront/checkout staging

- Build Laravel storefront with imported/read-model data.
- Stage checkout/payment flows.
- Run screenshot QA and SEO crawl comparisons.

### Phase 6 — Switch storefront from Woo to Laravel

- Preserve or redirect product/category/static URLs.
- Monitor orders, payments, SEO, logs, stock, and performance.
- Keep Woo fallback/rollback plan until stable.

### Phase 7 — Archive/remove old WordPress plugins once stable

- Remove old marketplace logic only after Product Hub proves stable.
- Keep historical data and audit exports.
- Document decommission steps and rollback limitations.

---

## 21. Infrastructure/security

- Production on VPS/cloud with separate staging environment.
- Redis queues and Horizon monitoring.
- Automated database backups and tested restores.
- Object storage for images and variants.
- Monitoring for uptime, exceptions, queue failures, disk, memory, and external API failures.
- Roles/permissions with least privilege.
- Encrypted API credentials and secrets management.
- Audit logs for product edits, pricing, stock, publishing, orders, settings, and credential changes.
- Approval workflow for publishing, price overrides, and risky bulk operations.
- Rate limits and locks around marketplace APIs.
- Webhook signature validation for payment/channel callbacks.

---

## 22. Six-month roadmap

### Month 1 — Foundation

- Create Laravel project and environments.
- Configure PostgreSQL/MySQL, Redis, object storage, queues, backups.
- Install Filament and roles/permissions.
- Create core migrations for products, staging, images, categories, channel listings, audit logs.
- Build admin skeleton and dashboard placeholders.

### Month 2 — Intake and catalog foundation

- Build mobile PWA intake skeleton.
- Implement staging item flow, image uploads, OEM normalization, duplicate detection baseline.
- Build product catalog admin pages.
- Import/sync read-only Woo product/category/image data for development.

### Month 3 — Enrichment, pricing, mapping, readiness

- Add Ovoko read-only enrichment adapter placeholder/approved implementation.
- Add Allegro price research and price suggestions.
- Build category/shipping mapping screens.
- Implement readiness checks and Error Center basics.

### Month 4 — Woo bridge and marketplace control foundation

- Implement `CreateOrUpdateWooProduct` with approval/audit.
- Build channel listings model and reconciliation views.
- Implement eBay DE/FR readiness and non-live payload preview.
- Add duplicate guards and active listing validation.

### Month 5 — Laravel storefront staging

- Build storefront Blade layout, header/footer, homepage, category, search, product detail from DTO/read models.
- Build mini-cart/cart/checkout staging with payment provider placeholders.
- Start screenshot QA and SEO URL/canonical comparison.

### Month 6 — Cutover readiness

- Complete checkout/payment decisions.
- Complete redirects, sitemap, robots, canonical, structured data.
- Run full staging QA, performance, backups, monitoring, rollback drill.
- Decide whether to switch storefront or continue parallel hardening.

---

## 23. First tasks for new Laravel repository

The first implementation tasks should be safe skeleton work only:

1. Create Laravel project.
2. Install Filament.
3. Configure DB, Redis, queues, cache, mail, and object storage placeholders.
4. Create base modules/namespaces for Catalog, Staging, Images, Fitment, Pricing, Mapping, Readiness, Channels, Orders, Stock, Audit.
5. Create migrations for core Product/Staging/Image/Category/ChannelListing tables.
6. Seed roles and permissions.
7. Build admin skeleton and navigation.
8. Build mobile intake skeleton with local/dummy upload flow.
9. Build storefront skeleton from placeholder DTOs.
10. Add fixture data for header, category tree, product cards, product detail, cart, checkout.
11. Add queue scaffolding and failed-job monitoring.
12. Add audit log model/service.
13. Add environment documentation.
14. Keep all live marketplace/Woo writes disabled.
15. Create Phase 1 implementation plan and confirm with stakeholders before channel work.

---

## 24. Risks/open questions

Known open questions before production-grade implementation:

- Real payment gateways and checkout provider requirements.
- Woo shipping zones/classes and Laravel shipping model.
- Active WordPress menu assignments and any admin-managed links not visible in templates.
- Exact SEO/canonical/meta/schema rules and active SEO plugin behavior.
- Exact customer-visible category projection from current WordPress category cache/rules.
- Product image fallback rules, AWI listing image behavior, gallery order, placeholder/CDN strategy.
- Search engine choice and performance requirements.
- Current legal content ownership and update workflow.
- Credential migration and secret management.
- Stock truth during transition between Woo, Product Hub, and marketplaces.
- Whether Google Translate/EUR conversion remains or becomes native localization/currency.
- Order ownership during transition: Woo orders, Laravel orders, or synchronized hybrid.
- Analytics/tracking/cookie consent currently active in production.

---

## 25. Instructions for Codex in the new Laravel repo

When this document is copied into the new Laravel repository, the first Codex task should be:

> Read this document, create a phased implementation plan, then implement only Phase 1 skeleton. Do not connect live marketplaces or modify WordPress.

Additional Codex rules for the new repository:

- Treat this handoff as the source implementation specification until superseded.
- Start with migrations, module namespaces, Filament skeleton, roles, placeholder DTOs, fixture storefront pages, and non-live queues.
- Do not add real API credentials.
- Do not connect live eBay, Allegro, Ovoko, Woo, payment, or shipping writes.
- Do not scrape external services blindly.
- Do not change production WordPress/WooCommerce.
- Ask for explicit approval before enabling any write to an external marketplace or production store.
- Prefer small, reviewable phases with acceptance criteria and screenshots for storefront work.
