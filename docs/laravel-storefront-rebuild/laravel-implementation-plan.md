# Laravel Implementation Plan

## Recommended stack

Primary recommendation: **Laravel Blade + Livewire + Alpine.js**.

Why:

- Current storefront is mostly server-rendered with small interactive islands.
- SEO-critical pages (categories/products) should remain fast, crawlable, and URL-stable.
- Livewire maps well to cart, mini-cart, filters, auth modal, and checkout interactions without a separate SPA.
- Alpine can reproduce menu, tabs, gallery, modal, and hero interactions with less complexity than Inertia/Vue.

Alternatives:

- **Laravel + Inertia/Vue:** useful if Product Hub admin and storefront share a Vue component system, but higher risk for 1:1 Woo-like SSR and SEO unless SSR is carefully configured.
- **Laravel API + separate frontend:** best for multi-channel/headless future, but unnecessary for first 1:1 storefront rebuild and raises migration/SEO complexity.

## Route structure

```php
GET /                                HomeController@index
GET /sklep                           ProductArchiveController@index
GET /produkt/{slug}                  ProductController@show
GET /kategoria-produktu/{path}       CategoryController@show where path is nested
GET /                                SearchController@index when ?s=...&post_type=product
GET /koszyk                          CartController@index
POST /koszyk/items                   CartController@add
PATCH /koszyk/items/{key}            CartController@update
DELETE /koszyk/items/{key}           CartController@remove
GET /zamowienie                      CheckoutController@show
POST /zamowienie                     CheckoutController@store
GET /zamowienie/order-received/{id}  CheckoutController@thankYou
GET /moje-konto                      AccountController@index
GET /moje-konto/orders               AccountOrdersController@index
GET /moje-konto/zwrot/{order}        AccountReturnController@create
POST /moje-konto/zwrot/{order}       AccountReturnController@store
GET /zaloguj                         Auth\LoginController@show
GET /zarejestruj                     Auth\RegisterController@show
GET /kontakt                         PageController@contact
POST /kontakt                        ContactController@store
GET /regulamin-platnosci             PageController@terms
GET /polityka-prywatnosci            PageController@privacy
GET /zwroty                          PageController@returns
GET /pojazd/{vehicleSlug}            VehiclePartsController@show
```

Keep legacy Polish slugs unless SEO analysis decides otherwise.

## Controller/services

- `ProductQueryService`: archive filters, search, sort, price range, brand, part number, vehicle model.
- `CategoryTreeService`: materialized visible storefront tree, breadcrumbs, mega menu data.
- `PriceService`: PLN/EUR context conversion, formatting, tax display.
- `DeliveryEstimateService`: reproduces cutoff at 13:30 and date label.
- `ProductDisplayNameService`: reproduces product title formatting and imported-prefix cleanup.
- `CartService`: session/customer cart operations, stock checks, totals.
- `CheckoutService`: validation, shipping/payment selection, order creation.
- `SeoMetaService`: canonical, title, description, OpenGraph, structured data.
- `ImageService`: listing image fallback order and CDN/image sizes.

## View hierarchy

```text
resources/views/layouts/storefront.blade.php
resources/views/pages/home.blade.php
resources/views/products/archive.blade.php
resources/views/products/category.blade.php
resources/views/products/search.blade.php
resources/views/products/show.blade.php
resources/views/cart/index.blade.php
resources/views/checkout/show.blade.php
resources/views/checkout/thank-you.blade.php
resources/views/account/*.blade.php
resources/views/pages/*.blade.php
resources/views/components/storefront/header.blade.php
resources/views/components/storefront/category-mega-menu.blade.php
resources/views/components/storefront/search.blade.php
resources/views/components/storefront/product-card.blade.php
resources/views/components/storefront/footer.blade.php
resources/views/components/storefront/breadcrumbs.blade.php
resources/views/components/storefront/alerts.blade.php
```

## DTO/data structures

- `ProductListItemDTO`
- `ProductDetailDTO`
- `CategoryDTO`
- `MenuCategoryDTO`
- `CartItemDTO`
- `CheckoutDTO`
- `SearchResultDTO`
- `ImageDTO`
- `MoneyDTO`
- `StockDTO`
- `FitmentDTO`
- `SeoMetaDTO`

Each DTO should include already-formatted display strings and raw machine fields so Blade components do not duplicate business logic.

## Asset build approach

- Use Vite with `resources/css/storefront.css` and `resources/js/storefront.js`.
- Start with custom CSS preserving current `.gp-*` class names where helpful for 1:1 parity.
- Tailwind decision: use Tailwind only for new internal/admin surfaces or utility-assisted layout; for the storefront 1:1 rebuild, a custom CSS layer is safer because the current design is class/selector-driven and has exact responsive behavior.
- Split JS modules: `header-menu.js`, `mini-cart.js` (if not pure Livewire), `hero-slider.js`, `product-gallery.js`, `filters.js`, `auth-modal.js`.

## Implementation phases

1. Static Blade shell: header/footer/home/category/product skeleton with copied visual tokens.
2. Data integration from Product Hub/Woo sync into read models.
3. Category/archive/product rendering parity.
4. Cart/mini-cart Livewire and checkout provider placeholders.
5. Account/login/register/Google OAuth.
6. SEO/meta/canonical/sitemap and redirect parity.
7. Screenshot comparison and QA against production.

## Preserve design without copying WordPress architecture

- Copy visual semantics and data behavior, not hooks/actions/filters.
- Replace WooCommerce query globals with explicit services and DTOs.
- Replace WordPress category cache transient with a database-backed storefront category projection.
- Replace DOM mutation label fixes with server-rendered labels.
- Replace jQuery fragments with Livewire events.
