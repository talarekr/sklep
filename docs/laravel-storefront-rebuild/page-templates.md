# Page Templates and Public Routes

## Route/template matrix

| Page type | Current URL pattern | Source templates | Layout/components | Laravel equivalent |
|---|---|---|---|---|
| Homepage | `/` | `front-page.php`, `template-parts/home/top-bar.php`, `store-header.php`, `popular-products.php` | Promo/top/header, hero banner, popular product carousel/grid, footer | `HomeController@index`, `pages.home`, components `Header`, `HeroSlider`, `ProductCarousel` |
| Shop archive | `/sklep/` or Woo shop permalink | `woocommerce/archive-product.php`, `content-product.php`, product card partial | Breadcrumb, sidebar filters, toolbar, product grid | `ProductArchiveController@index` |
| Category archive | `/kategoria-produktu/{path}/` | same archive template plus category hero | Category title/description/search panel, sidebar, grid | `CategoryController@show` with nested slug resolver |
| Search results | `/?s={query}&post_type=product` | `search.php` plus Woo query hooks / archive behavior | Header search, product loop, empty state | `SearchController@index` |
| Product detail | `/produkt/{slug}/` | `woocommerce/single-product.php`, `content-single-product.php` | Gallery, info card, trust blocks, purchase box, tabs | `ProductController@show` |
| Cart | Woo cart page | WooCommerce cart block/template plus `woocommerce/cart/cart-totals.php`, `cart-checkout.js` | Items, quantities, totals, checkout CTA | `CartController@index`, Livewire cart |
| Checkout | Woo checkout page | Woo checkout/block plus `woocommerce/checkout/review-order.php` | Billing/shipping/payment/order summary/terms | `CheckoutController@show/store` |
| Order received | Woo endpoint `/checkout/order-received/{id}/` | Woo endpoint templates | Order confirmation and payment status | `CheckoutController@thankYou` |
| My account | Woo account page/endpoints | Woo core + theme auth helpers | Login/account/orders/return endpoint | `AccountController` routes |
| Login | `/zaloguj` | `page-zaloguj.php`, auth hooks | Custom login form, Google OAuth if enabled | `Auth\LoginController`/Fortify custom views |
| Register | `/zarejestruj` | `page-zarejestruj.php`, auth hooks | Register form, Google OAuth if enabled | `Auth\RegisteredUserController` |
| Contact | `/kontakt` | `page-kontakt.php`, `gp_handle_contact_form_submit()` | Contact details/form | `PageController@contact` |
| Terms | `/regulamin-platnosci` | `page-regulamin-platnosci.php` | Static legal content | CMS/static Blade page |
| Privacy | `/polityka-prywatnosci` | `page-polityka-prywatnosci.php`, fallback HTML in functions | Static privacy content | CMS/static Blade page |
| Returns | `/zwroty` | `page-zwroty.php`; account return endpoint functions | Static returns info + account return flow | Static page + account return module |
| 404 | WordPress fallback | no explicit `404.php` in snapshot | Default theme/WP fallback likely via `index.php` | `errors/404.blade.php` |
| Same vehicle landing | `/pojazd/{vehicle_slug}/` and `?ovoko_car_id=` | query vars/hooks in `functions.php` | Product archive filtered by `_ovoko_car_id` | `VehiclePartsController@show` |

## Per-page detail

### Homepage

- **Data required:** hero slides, top bar text, logo assets, product cards for popular/new products, cart count, category tree, language state.
- **CSS classes:** `gp-main-header`, `gp-hero`, `gp-home-products`, `gp-products`, `gp-product`.
- **JS:** hero slider, wishlist heart toggles, category menu/profile/mini-cart scripts.
- **Responsive:** header collapses below 1199/767 breakpoints; product grids reduce from 4 columns to 2 and then 1 in legacy home CSS; final Laravel should verify screenshots because Woo archive grid differs.

### Shop/category archive

- **Data required:** current category, lineage/breadcrumbs, visible category tree, brand terms, selected filters, products, pagination, ordering.
- **Components:** category search hero, breadcrumb, sidebar filters, toolbar, product grid/card, pagination, empty state.
- **CSS classes:** `gp-woo-layout`, `gp-category-search-hero`, `gp-shop-grid`, `gp-shop-sidebar`, `gp-shop-content`, `gp-shop-toolbar`, `products`, `gp-product-item`.
- **JS:** category search mode switch, category select redirects, sidebar show-more/collapse.
- **Laravel recommendation:** nested category slugs must resolve to canonical category; keep query params `brand`, `price_min`, `price_max`, `part_number`, `search_mode`, `s`, `orderby`, `page` compatible.

### Product detail

- **Data required:** product title, formatted title, price, stock, `_part_number`, images/gallery, short/long description, visible attributes, categories, related/upsell if enabled, Ovoko IDs, same-vehicle URL.
- **Components:** gallery, info card, trust blocks, purchase box, tabs, add-to-cart, contact CTA.
- **CSS classes:** `gp-product-page`, `gp-product-page__hero`, `gp-product-page__gallery`, `gp-product-info-card`, `gp-product-trust`, `gp-purchase-box`, `woocommerce-tabs`.
- **JS:** Woo flexslider/lightbox; custom prev/next and add-to-cart fly animation.
- **Laravel recommendation:** implement gallery with Alpine/lightbox; tabs server-rendered; preserve same-vehicle CTA for Ovoko-sourced products.

### Cart/checkout/account

- **Cart:** item list, thumbnail, product title cleaned of imported prefix, qty +/- and remove, subtotal, delivery cost `0 zł`, checkout CTA. Mini-cart mirrors same item data and AJAX operations.
- **Checkout:** order summary with thumbnails, billing/shipping fields from Woo settings/gateway requirements, payment method placeholders, terms checkbox, order note, validation notices.
- **Account:** current theme has custom profile dropdown and custom login/register pages, plus Woo account endpoints and return request endpoint. Laravel should use first-party auth with Google OAuth adapter if retained.

### Static and special pages

- Contact, terms, privacy, returns should be copied as content, not as WordPress PHP. Store them as CMS records or Blade Markdown so legal copy can be reviewed independently.
- 404 should use the same header/footer, search bar, category shortcuts, and a clear return-to-shop CTA.
