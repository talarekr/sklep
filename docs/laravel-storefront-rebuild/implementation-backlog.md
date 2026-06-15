# GPSWISS Laravel Storefront Implementation Backlog

This backlog converts the current storefront documentation into implementation-ready planning tasks for rebuilding the public `gpswiss.pl` WordPress/WooCommerce shop frontend in Laravel. It remains planning-only: do not change WordPress, WooCommerce templates, plugins, integrations, database data, or production behavior while using this backlog.

## 1. Stack confirmation and guiding principles

### Confirmed primary frontend stack

- **Laravel Blade** for SEO-friendly server-rendered storefront pages.
- **Livewire** for interactive server-backed islands: mini-cart, cart, filters where useful, checkout state, auth modal handoff, and account flows.
- **Alpine.js** for lightweight client-only UI: dropdowns, mega menu, mobile menu, tabs, modals, gallery controls, and loading state toggles.
- **Vite** for `resources/css/storefront.css` and `resources/js/storefront.js` build entrypoints.
- **Custom CSS first**, preserving existing `.gp-*` class names where useful for visual parity. Tailwind may be used later for Product Hub/admin or isolated utilities, but should not drive the 1:1 storefront rebuild until parity is approved.
- **Server-render SEO first**: homepage, category archives, product pages, search results, static pages, and order/account routes must render meaningful HTML without requiring a SPA runtime.

### Global acceptance criteria

- The Laravel storefront reproduces the current public shop pages at desktop `1440px`, laptop `1280px`, tablet `768px`, and mobile `390px` before production cutover.
- Legacy Polish URLs remain valid or receive permanent redirects with canonical metadata preserved.
- WordPress remains the source of production truth until Laravel data sync, cart/checkout, payment, SEO, and screenshot QA are complete.
- Customer-visible behavior is copied; WordPress hook architecture, WooCommerce fragments, and DOM mutation workarounds are replaced with explicit Laravel services/components.

## 2. MVP phases

| Phase | Goal | Primary dependencies | Exit criteria |
|---|---|---|---|
| Phase A — Static visual skeleton | Build static Blade layout, design tokens, header/footer/home/archive/product skeletons using fixture data | Existing docs, screenshots, logo/assets access | Static pages match header/footer/global spacing at target breakpoints |
| Phase B — Product/category browsing | Render homepage, shop, category, search, filters, pagination from imported/read-model data | Product/category/image/price/category-tree DTOs; Woo/Product Hub sync | Category/product browsing returns comparable products and visible category tree |
| Phase C — Product detail | Render full product pages with gallery, purchase box, tabs, specifications, Ovoko details/same-vehicle links | ProductDetailDTO, ImageDTO, FitmentDTO, SeoMetaDTO | Non-Ovoko and Ovoko product pages pass screenshot and data parity |
| Phase D — Cart/checkout | Build Livewire cart, mini-cart, checkout form, order summary, payment/shipping placeholders | CartDTO, CheckoutDTO, payment/shipping decisions | Cart/checkout labels, totals, validation, and guest/auth flow match current behavior |
| Phase E — Account/order flow | Build login/register/account/orders/returns pages and optional Google OAuth | Auth decisions, order sync, return policy fields | Customer can authenticate, view orders, and start returns on staging |
| Phase F — SEO parity and URL preservation | Canonicals, meta, structured data, breadcrumbs, sitemap, redirects, robots | SEO source data, URL inventory, sitemap comparison | Crawl comparison shows no critical URL/meta regressions |
| Phase G — Production cutover readiness | Final cross-browser/device QA, performance, rollback, monitoring | Completed phases A-F, stakeholder approval | Cutover checklist approved with rollback path documented |

## 3. Component backlog

Each task below should be implemented as a Blade component unless marked Livewire. Alpine behavior should live in small modules/components rather than global jQuery-style scripts.

| ID | Component task | Source WordPress reference | Laravel component | Required props/data | Responsive behavior | JS behavior | Dependencies | Acceptance criteria |
|---|---|---|---|---|---|---|---|---|
| C01 | App storefront layout shell | `header.php`, `footer.php`, `front-page.php`, `page.php` | `layouts.storefront`, `x-storefront.alerts` | page title/meta, body classes, header/footer DTOs, flash notices | Container max width and global spacing match current `.gp-container` | None except Alpine boot | Phase A CSS tokens | Every public page renders consistent header/footer, notices, meta, and content slot |
| C02 | Top/promo bar | `template-parts/home/top-bar.php`, `style.css` promo rules | `x-storefront.top-bar` | text, theme variant, closable flag | Full-width bar; close button remains tappable on mobile | Alpine close/hide state | Layout shell | Bar content and close behavior match current homepage when enabled |
| C03 | Header logo/search/cart/account area | `template-parts/home/store-header.php` | `x-storefront.header` | logo, home URL, search query, cart count, user state, language, contact links | Desktop 3-column grid; compressed/mobile layout below current breakpoints | Coordinates profile dropdown, cart drawer, auth modal triggers | C01, C07, C08 | Header matches production screenshots at 1440/1280/768/390 |
| C04 | Main search component | `searchform.php`, header search in `store-header.php` | `x-storefront.search` | query, action, placeholder, hidden params, mode | Full-width in header; usable on 390px | Submit preserves legacy params; optional loading state | Search service | Search submits to Laravel search route and preserves `s`/`post_type=product` compatibility during migration |
| C05 | Mega category menu | `store-header.php`, category display helpers in `functions.php`, `profile-auth.js` | `x-storefront.category-mega-menu` | root categories, children, grandchildren, active category | Desktop two-panel hover menu; mobile accordion below 1199px | Alpine hover/focus/touch activate panels; Escape/outside click closes | CategoryTreeDTO | Shows only customer-visible categories; no raw technical/Ovoko categories exposed |
| C06 | Mobile menu | `store-header.php`, `profile-auth.js`, responsive CSS | `x-storefront.mobile-navigation` | same tree as C05, shortcut links, phones | Accordion nested tree at 390/768; touch targets accessible | Alpine expand/collapse and body scroll lock if needed | C05 | Mobile menu opens/closes, nested children expand, and category URLs work |
| C07 | Mini-cart drawer | `gp_render_mini_cart_content()`, mini-cart markup, `cart-checkout.js` | `livewire.mini-cart` | CartDTO, cart item rows, subtotal, checkout/cart URLs, auth state | Right drawer desktop; full/near-full width on mobile | Open/close, qty +/- remove, refresh count, loading/disabled state | Cart service, auth modal | Empty and populated drawer match screenshots; qty/remove update totals without page reload |
| C08 | Auth modal/profile menu | Profile dropdown/auth modal in `store-header.php`, auth handlers in `functions.php`, `profile-auth.js`, `cart-checkout.js` | `x-storefront.profile-menu`, `livewire.auth-modal` or Blade + controllers | auth state, login/register URLs, Google OAuth availability, checkout URL | Modal usable at 390px; profile dropdown aligned to header action | Open/close, Escape, outside click, password visibility, Google button init if retained | Auth decisions | Logged-out checkout CTA opens modal; logged-in menu shows account/orders/logout |
| C09 | Footer | `footer.php`, `assets/css/woocommerce.css` footer rules | `x-storefront.footer` | contact data, legal links, optional badges/social/newsletter | Columns collapse to one column on mobile | None | Static page routes | Footer links and contact data match current storefront |
| C10 | Product card | `template-parts/product/product-card.php`, `woocommerce/content-product.php` | `x-storefront.product-card` | ProductListItemDTO | Archive grid/card sizes match desktop/tablet/mobile | Wishlist heart toggle; optional loading skeleton | Product DTO, Price service, Image service | Card shows correct image fallback, part number, formatted title, price, and delivery labels |
| C11 | Product grid | Woo loop in `archive-product.php`, Woo CSS grid | `x-storefront.product-grid` | product paginator, layout mode, empty state | 3 columns desktop for archives; verified tablet/mobile counts | Optional lazy image/skeleton state | C10, pagination | Grid, pagination, empty state, and result count match Woo behavior |
| C12 | Category archive sidebar | `gp_render_product_category_sidebar()`, category helpers, `home.js` | `livewire.category-filters` or Blade form | visible category tree, active lineage, brands, price range, selected filters | Sidebar desktop; stacked/collapsible mobile if screenshot confirms | Select redirect, show more/less, expand child categories, submit price filter | CategoryTreeDTO, ProductQueryService | Brand/category/subcategory/price filters preserve query params and match current results |
| C13 | Search results summary | `search.php`, `posts_search` filters | `x-storefront.search-results-header` | query, mode, result count, empty copy | Fits archive content area | Optional loading on filter/search submit | Search service | Search page clearly shows query/mode and correct empty state |
| C14 | Product detail gallery | Woo gallery + `single-product.js` | `x-storefront.product-gallery` | images, active image, alt text, video flag if future | Large gallery desktop; stacked above info/purchase mobile | Alpine arrows, thumbnails, lightbox/zoom, lazy load | ImageDTO | Gallery, thumbnails, arrows, and lightbox behavior match current Woo gallery |
| C15 | Product purchase box | `content-single-product.php` purchase aside | `livewire.product-purchase-box` | product ID, price, stock, quantity, contact URL, cart state | Sticky/side column desktop if current CSS confirms; stacked mobile | Add-to-cart loading and optional fly animation | Cart service, ProductDetailDTO | Price note, quantity/add-to-cart, contact CTA, helper copy match current product page |
| C16 | Product info/trust blocks | `content-single-product.php` | `x-storefront.product-info-card`, `x-storefront.trust-blocks` | title, part number, condition, short description, sameVehicleUrl, delivery, payments image, returns copy | Middle column desktop; stacked mobile | None | ProductDetailDTO | Non-Ovoko short description and Ovoko same-vehicle CTA display correctly |
| C17 | Product tabs/specifications | `woocommerce_product_tabs` filters, `gp_render_ovoko_description_and_details_tab()` | `x-storefront.product-tabs` | description HTML, visible attributes, warranty copy, seller copy, fitment | Tabs or accordion on mobile depending parity screenshots | Alpine tab switching | ProductDetailDTO, FitmentDTO | Non-Ovoko tabs and Ovoko combined description/details tab match source behavior |
| C18 | Related products | Woo related/upsells if enabled | `x-storefront.related-products` | product list items, heading, carousel flag | Grid/carousel responsive | Optional Alpine carousel | ProductQueryService | Displays only when data exists and uses the standard product card |
| C19 | Cart page | Woo cart/block, `cart-totals.php`, `cart-checkout.js` | `livewire.cart-page` | CartDTO, coupons, totals, shipping labels | Table/card layout responsive | Qty/remove, coupon, checkout CTA, loading state | Cart service, C07 | Cart labels, free shipping row, totals, item title cleanup, and CTA match current behavior |
| C20 | Checkout page | Woo checkout/block, `review-order.php` | `livewire.checkout` plus `x-storefront.order-summary` | CheckoutDTO, CartDTO, customer fields, payment/shipping methods, terms URL | Two-column desktop; single-column mobile | Validation, payment selection, terms checkbox, address toggles, loading on submit | Payment/shipping decisions | Required fields, order summary, payment methods, shipping `0 zł` display, and validation match production decisions |
| C21 | Order received | Woo order-received endpoint | `x-storefront.order-received` | order ID, order number, status, totals, payment status, customer email | Simple readable layout at all breakpoints | None | Checkout/order service | Customer sees confirmation equivalent to Woo thank-you page |
| C22 | Account pages | Woo account endpoints, custom login/register pages, return endpoint | `account.*` views, Livewire returns form | user, orders, order items, returnable items, statuses | Mobile readable account navigation | Return form validation; optional Google OAuth login | Auth/order sync | Login/register/account/orders/returns work on staging with migrated/synced data |
| C23 | Static CMS pages | `page-kontakt.php`, `page-regulamin-platnosci.php`, `page-polityka-prywatnosci.php`, `page-zwroty.php` | `pages.static`, `pages.contact` | slug, title, body HTML/Markdown, contact form config | Content width and forms match theme | Contact form submit/loading | CMS/static content source | Contact, terms, privacy, returns pages preserve copy and links |
| C24 | 404 page | WordPress fallback / `index.php` | `errors.404` | search props, category shortcuts, home/shop URLs | Same header/footer; centered helpful content | Search submit | Layout/search | 404 includes search and return-to-shop CTA, with correct 404 status |

## 4. Route/page backlog

| ID | Page/route task | Controller/action | Blade view | Livewire components | DTO/data requirements | SEO requirements | Dependencies | Acceptance criteria |
|---|---|---|---|---|---|---|---|
| R01 | Homepage `/` | `HomeController@index` | `pages.home` | optional `mini-cart` globally | HeaderDTO, CategoryTreeDTO, hero slides, ProductListItemDTO collections, SeoMetaDTO | Homepage title/meta/canonical; preload hero image | C01-C11 | Homepage matches hero/header/product sections at all target viewports |
| R02 | Shop archive `/sklep` | `ProductArchiveController@index` | `products.archive` | filters, mini-cart | ProductArchiveDTO, paginator, sort options, filters, SeoMetaDTO | Canonical with pagination rules; noindex only if business requires filtered URLs | B data sync, C10-C12 | Result count, sorting, 60/page behavior, grid, filters, and pagination match |
| R03 | Category archive `/kategoria-produktu/{path}` | `CategoryController@show` | `products.category` | filters, category search | CategoryArchiveDTO, breadcrumbs, category hero data | Canonical nested category URL, category title/meta/description, breadcrumbs schema | Category tree projection | Category hero, sidebar lineage, products, and child categories match production |
| R04 | Search results `/?s=...&post_type=product` | `SearchController@index` | `products.search` | filters if retained | SearchResultDTO, paginator, query, mode | Canonical/noindex policy for search; preserve query display | Search service | Title and part-number searches return comparable products and empty state |
| R05 | Product detail `/produkt/{slug}` | `ProductController@show` | `products.show` | purchase box, mini-cart | ProductDetailDTO, related products, SeoMetaDTO | Product canonical, OpenGraph, Product schema, breadcrumbs excluding marketplace-only crumbs | C14-C18 | Non-Ovoko and Ovoko products pass data and screenshot parity |
| R06 | Cart `/koszyk` | `CartController@index` | `cart.index` | cart page, mini-cart | CartDTO, SeoMetaDTO | Usually noindex; canonical cart URL | Cart service | Cart quantities, totals, item cleanup, free delivery label, and CTA match |
| R07 | Checkout `/zamowienie` | `CheckoutController@show/store` | `checkout.show` | checkout, order summary | CheckoutDTO, CartDTO, user/customer data | noindex; terms/privacy links | Payment/shipping decisions | Checkout can validate and create staged orders with matching UX |
| R08 | Order received `/zamowienie/order-received/{id}` | `CheckoutController@thankYou` | `checkout.thank-you` | none | OrderSummaryDTO, SeoMetaDTO | noindex; no accidental leakage | Checkout/order service | Confirmation page displays order number/status/totals/payment status |
| R09 | Login/register `/zaloguj`, `/zarejestruj` | `Auth\LoginController@show`, `Auth\RegisterController@show` | `auth.login`, `auth.register` | optional auth form | AuthPageDTO, GoogleOAuthConfigDTO | noindex optional; canonical self | Auth decisions | Forms and Google OAuth option match current customer-facing flow |
| R10 | Account `/moje-konto/*` | `AccountController`, `AccountOrdersController`, `AccountReturnController` | `account.*` | returns form | UserDTO, OrderDTO, ReturnRequestDTO | noindex private pages | Auth/order sync | Account navigation, order list, and return flow work for migrated customers |
| R11 | Static CMS pages | `PageController@show/contact/terms/privacy/returns` | `pages.static`, `pages.contact` | contact form | CmsPageDTO, ContactFormDTO, SeoMetaDTO | Preserve page slugs, titles, canonical, legal links | CMS content migration | Contact/legal/returns content matches current site and form submits safely |
| R12 | 404 | framework error handler | `errors.404` | none | HeaderDTO, CategoryTreeDTO, search props | 404 status, no canonical to missing URL unless desired | Layout/search | Missing URLs return 404 with helpful storefront navigation |
| R13 | Same-vehicle route `/pojazd/{vehicleSlug}` | `VehiclePartsController@show` | `products.vehicle` or archive variant | filters | VehiclePartsDTO, ProductArchiveDTO | Canonical route; support redirect/canonical for `?ovoko_car_id=` | Ovoko vehicle mapping | Same-vehicle CTA routes show matching parts and preserve SEO policy |

## 5. Data contract backlog

Temporary Woo migration sources assume read-only extraction/sync from WordPress/WooCommerce tables/meta into Product Hub read models. Field names should be finalized in Product Hub schema design.

| DTO | Fields | Future Product Hub source | Temporary Woo migration source | Required vs optional |
|---|---|---|---|---|
| `ProductListItemDTO` | `id`, `slug`, `url`, `title`, `displayTitle`, `sku`, `partNumber`, `price`, `regularPrice`, `listingImage`, `inStock`, `deliveryLabel`, `deliveryCutoffLabel`, `source`, `ovokoCarId` | `products`, `product_identifiers`, `prices`, `inventory`, `product_images`, `channel_source_refs` | `wp_posts`, `_sku`, `_part_number`, `_price`, `_regular_price`, `_stock_status`, `_thumbnail_id`, `_awi_listing_image_id`, `_ovoko_car_id`, `source` post meta | Required: id/slug/url/title/displayTitle/price/listingImage/inStock; Optional: sku/partNumber/regularPrice/source/ovokoCarId |
| `ProductDetailDTO` | list item fields plus `oemNumber`, `stock`, `gallery`, `categories`, `shortDescriptionHtml`, `descriptionHtml`, `visibleAttributes`, `fitment`, `sameVehicleUrl`, `relatedProducts`, `seo` | product aggregate, images, categories, attributes, fitment, SEO tables | Woo product post/meta, `_product_attributes`, gallery meta, product_cat terms, Ovoko/Allegro meta, Yoast/SEO meta if present | Required: id/title/slug/price/stock/gallery/categories/seo; Optional: OEM/fitment/sameVehicle/related |
| `CategoryDTO` | `id`, `slug`, `path`, `name`, `description`, `parentId`, `level`, `url`, `isVisible`, `productCount`, `seo` | `categories`, `category_closure`, `storefront_category_projection` | `wp_terms`, `wp_term_taxonomy`, `wp_termmeta`, custom category display cache | Required: id/slug/path/name/url/isVisible; Optional: description/productCount/seo |
| `CategoryTreeDTO` | `roots`, `childrenByParent`, `activeCategoryId`, `lineageIds`, `debugVersion` | materialized category projection/read model | `product_cat` terms plus `gp_product_cat_display_data_v2` equivalent cache | Required: roots/children/lineage; Optional: debugVersion |
| `ImageDTO` | `id`, `url`, `srcset`, `alt`, `width`, `height`, `role`, `sortOrder`, `legacyUrl`, `placeholder` | `media_assets`, `product_images` | `wp_posts` attachments, `_thumbnail_id`, gallery meta, `_awi_listing_image_id`, `/wp-content/uploads` URLs | Required: url/alt/role; Optional: id/srcset/dimensions/legacyUrl |
| `PriceDTO` | `amount`, `currency`, `formatted`, `regularAmount`, `saleAmount`, `taxIncluded`, `convertedFromCurrency`, `exchangeRate` | `prices`, `currency_rates`, tax settings | `_price`, `_regular_price`, `_sale_price`, Woo tax/currency, NBP EUR option | Required: amount/currency/formatted; Optional: sale/regular/conversion |
| `StockDTO` | `status`, `quantity`, `isPurchasable`, `isInStock`, `availabilityLabel`, `backordersAllowed` | `inventory` | `_stock_status`, `_stock`, Woo purchasable state | Required: status/isPurchasable/isInStock; Optional: quantity/backorders |
| `CartDTO` | `id`, `items`, `subtotal`, `shippingTotal`, `discountTotal`, `fees`, `taxTotal`, `total`, `currency`, `itemCount`, `checkoutUrl`, `cartUrl`, `requiresAuthModal` | Laravel session/customer cart tables | Woo cart session during bridge only, if implemented | Required: items/subtotal/total/currency/itemCount; Optional: coupons/fees/taxes/authModal flag |
| `CartItemDTO` | `key`, `productId`, `name`, `displayName`, `url`, `thumbnail`, `quantity`, `unitPrice`, `lineTotal`, `regularPrice`, `meta` | cart item table/session + product read model | Woo cart item array and product object | Required: key/productId/displayName/quantity/unitPrice/lineTotal; Optional: regular/meta |
| `CheckoutDTO` | `cart`, `customer`, `billingFields`, `shippingFields`, `shippingMethods`, `paymentMethods`, `termsUrl`, `privacyUrl`, `orderNotesEnabled`, `validationErrors` | checkout config, customers, payment/shipping providers | Woo checkout fields/settings/gateway output | Required: cart/customer fields/payment methods/terms; Optional: notes/custom fields/errors |
| `SearchResultDTO` | `query`, `mode`, `normalizedQuery`, `results`, `pagination`, `filters`, `sort`, `emptyState`, `suggestions` | search index/read model | WP_Query with `posts_search`, `_part_number`, product title/category data | Required: query/results/pagination; Optional: suggestions/mode-specific metadata |
| `FitmentDTO` | `rows`, `source`, `vehicleModels`, `oemNumbers`, `ovokoCarId`, `sameVehicleUrl`, `confidence` | fitment tables, vehicle tables, channel compatibility refs | `_allegro_parameters`, `_ovoko_car_id`, `_ovoko_part_id`, visible attributes | Optional overall; required for products that display compatibility/same-vehicle UI |
| `SeoMetaDTO` | `title`, `description`, `canonicalUrl`, `robots`, `openGraph`, `structuredData`, `breadcrumbs`, `paginationLinks` | SEO table/generated service | WP title/meta options/plugins, Woo breadcrumbs, product/category data | Required for public indexable pages: title/canonical/robots/breadcrumbs; Optional: OG/schema overrides |

## 6. CSS/design backlog

| ID | Design task | Source | Acceptance criteria |
|---|---|---|---|
| D01 | Define color tokens | `design-system.md`, `style.css` variables | CSS variables for `--gp-bg`, `--gp-text`, `--gp-muted`, `--gp-border`, `--gp-primary`, `--gp-navy`, `--gp-red` exist and match source values |
| D02 | Typography foundation | Poppins enqueue in theme | Poppins weights 400/500/600/700 load; body, form controls, Woo-equivalent components use Poppins; base 14px/1.45 confirmed |
| D03 | Container and spacing scale | `.gp-container`, layout CSS | `1320px` max container and 12px side padding match screenshots; component gaps match header/grid/product pages |
| D04 | Buttons | `.gp-btn`, search submit, checkout CTA | Primary navy, outline, search, checkout, filter buttons visually match and include focus states |
| D05 | Forms | header search, category search, filters, auth, checkout | Inputs/selects/buttons match border radius, heights, labels, errors, placeholders, and mobile usability |
| D06 | Badges/product labels | product card part number, favorite, price, delivery | Part number, price, delivery labels, favorite active state, discount/sale badges if present match current cards |
| D07 | Product/archive grid | Woo loop CSS and `loop_shop_columns=3` | Archive grid 3 columns desktop and verified tablet/mobile counts; home product grid parity separately checked |
| D08 | Product image ratios | product card, gallery, checkout thumbnails | Listing/card/gallery/mini-cart/checkout images use matching object-fit, fallback, lazy loading, and dimensions |
| D09 | Woo notice equivalents | Woo notices CSS | Laravel success/error/info notices match Woo visual treatment and are accessible |
| D10 | Responsive breakpoints | `1200`, `1199`, `1024`, `900`, `768`, `767` rules | Header, mega menu, sidebar, product page, cart, checkout switch layouts at equivalent breakpoints |
| D11 | Loading states | mini-cart/cart/search/checkout | Add subtle non-invasive loading/disabled states absent in source but required for Livewire interactions, without changing layout dimensions |
| D12 | Print/accessibility polish | New Laravel components | Keyboard focus, aria labels, color contrast, and reduced motion considered without breaking visual parity |

## 7. JavaScript/interactivity backlog

| ID | Interaction task | Implementation | Acceptance criteria |
|---|---|---|---|
| J01 | Header profile dropdown | Alpine component | Click toggles, outside click/Escape closes, auth-state links match current dropdown |
| J02 | Mega category menu | Alpine component | Desktop hover/focus changes active panel; mobile touch does not accidentally navigate when expanding; hidden panels not focusable |
| J03 | Mobile menu | Alpine component | Opens/closes at mobile/tablet widths, nested categories expand, body scroll behavior is acceptable |
| J04 | Mini-cart drawer | Livewire + Alpine shell | Drawer opens from header, closes via button/Escape/outside click, qty/remove update count/totals with loading state |
| J05 | Auth modal | Alpine/Livewire or Blade form | Checkout CTA opens modal for guests; close behaviors and password toggle work; Google OAuth slot initializes if enabled |
| J06 | Product gallery/lightbox | Alpine + selected lightbox library | Thumbnail/arrows/lightbox/zoom match Woo gallery behavior and are keyboard accessible |
| J07 | Product add-to-cart feedback | Livewire event + optional Alpine animation | Add-to-cart updates mini-cart count and optionally reproduces fly-to-cart animation after MVP parity |
| J08 | Search mode switch | Alpine | Category hero toggles part number/model inputs and submits correct params |
| J09 | Filters/sorting | Blade forms or Livewire | Category select redirects or updates, brand submits, price filters apply, sorting and pagination preserve query params |
| J10 | AJAX/Livewire search | Livewire optional after MVP | MVP supports server search; enhanced suggestions/AJAX only after parity and performance requirements are known |
| J11 | Checkout interactions | Livewire | Payment/shipping selection, terms validation, order notes, errors, and submit loading work reliably |
| J12 | Loading/error states | Shared JS helpers | All Livewire actions show disabled/loading/error feedback without duplicated submissions |

## 8. Screenshot QA acceptance checklist

For every item, capture before/after screenshots against the current WordPress storefront and record pass/fail notes.

| Viewport | Homepage | Category | Product detail | Cart | Checkout | Mobile menu | Search | Mini-cart |
|---|---|---|---|---|---|---|---|---|
| Desktop `1440px` | Header, hero, products, footer match | Hero/sidebar/grid/sorting/pagination match | Gallery/info/purchase/tabs match | Items/totals/CTA match | Fields/payment/summary match | N/A or resized menu behavior documented | Header search and results match | Drawer empty and populated match |
| Laptop `1280px` | No wrapping regressions | Sidebar/grid still usable | Columns fit without overlap | Totals layout stable | Checkout columns stable | N/A or documented | Results grid stable | Drawer position/width match |
| Tablet `768px` | Header/menu/product sections adapt | Sidebar/category/filter layout adapts | Product sections stack correctly | Cart rows readable | Checkout single/stacked flow readable | Menu opens and nested children expand | Search form usable | Drawer usable |
| Mobile `390px` | Header/search/menu/cart tap targets usable | Product grid/filter/category hero usable | Gallery, price, add-to-cart, tabs usable | Qty/remove/totals usable | Required fields/payment/terms usable | Full mobile menu behavior passes | Search input/results readable | Drawer close/qty/remove usable |

Additional required captures:

- Product detail for one standard product and one Ovoko product with same-vehicle CTA/details.
- Empty search/category state.
- Logged-out profile dropdown and auth modal from checkout CTA.
- Product gallery lightbox/zoom if retained.
- 404 page at desktop and mobile.

## 9. Risks and open questions before coding

1. **Exact active menu assignments:** confirm WordPress menu locations and whether production uses any admin-managed links not visible in the repo templates.
2. **Payment gateways:** confirm active providers, PayU/BLIK/Google Pay behavior, test credentials, webhook requirements, and whether Laravel checkout will initially place Woo orders or native Product Hub orders.
3. **Shipping method logic:** current visible delivery is `0 zł`, but real Woo shipping zones/classes/rules need export and business confirmation.
4. **Checkout classic/block behavior:** confirm whether production currently renders Woo Blocks, classic shortcode, or mixed gateway output for all customer scenarios.
5. **Real category projection:** export/rebuild the current `gp_product_cat_display_data_v2`-equivalent tree and rules; raw `product_cat` is not sufficient.
6. **Product image fallback rules:** confirm AWI listing image priority, featured image fallback, gallery ordering, placeholder asset, and CDN/image-size strategy.
7. **SEO meta/canonical rules:** identify whether Yoast/Rank Math/native Woo metadata is active; export product/category/page meta and sitemap rules.
8. **Performance/search requirements:** define acceptable search latency and whether MVP DB search is enough before adding Meilisearch/Typesense/OpenSearch.
9. **Currency/language behavior:** decide whether to keep Google Translate + EUR conversion or replace it with native localization/currency services.
10. **Account/order migration:** decide whether customer accounts/orders/returns remain in Woo during MVP or are replicated into Product Hub.
11. **Legal/static content ownership:** confirm source of truth and review cadence for privacy, terms, returns, and contact content.
12. **Analytics/tracking/cookies:** document current production analytics pixels/cookie consent if any before launch parity.

## 10. Initial ticket breakdown

Use these as first implementation tickets after this planning-only backlog is approved.

1. Build storefront Blade layout and global Vite/CSS skeleton.
2. Port design tokens and base typography/container/buttons/forms.
3. Implement header/search/profile/footer with fixture data.
4. Implement visible category tree DTO and mega/mobile menu with fixture data.
5. Implement product card/grid with fixture ProductListItemDTO data.
6. Implement homepage static parity with hero and product section fixtures.
7. Implement category archive layout, sidebar, toolbar, sorting, and pagination using imported data.
8. Implement search service MVP for title and part-number search.
9. Implement product detail page with gallery, info, purchase box, and tabs.
10. Implement mini-cart and cart page Livewire components.
11. Implement checkout form/order summary with gateway placeholders.
12. Implement login/register/account/order/returns pages.
13. Implement static CMS pages and 404.
14. Implement SEO metadata, breadcrumbs, schema, sitemap, robots, and redirects.
15. Run screenshot QA and fix parity issues before cutover readiness review.
