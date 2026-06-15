# Assets and Hooks Inventory

## CSS assets

| Asset | Purpose | Pages | Laravel action |
|---|---|---|---|
| `style.css` | Theme metadata, global tokens, typography, header, homepage, mega menu, product cards, auth/profile, responsive rules | All pages | Port selected tokens/components into `resources/css/storefront.css`; do not copy WordPress resets blindly |
| `assets/css/woocommerce.css` | Woo archive/product/cart/checkout/account/footer styling | Shop, category, product, cart, checkout, account | Rebuild as Laravel component CSS using same class names where helpful for 1:1 |
| Google Fonts Poppins URL | Typography | All pages | Load via Vite/CSS import or self-host for performance/GDPR review |
| WooCommerce core styles | Notices/forms/tables/galleries | Woo pages | Replace with Laravel CSS components; keep visual behavior only |
| Inline styles/scripts from Woo/gateways | Checkout/payment/gallery behavior | Cart/checkout/product | Re-implement through payment provider SDKs and Alpine/Livewire |

## JavaScript assets

| Asset | Dependencies | Purpose | Pages | Laravel action |
|---|---|---|---|---|
| `assets/js/home.js` | Vanilla JS | Category search tabs, closable promo bars, sidebar selects/show-more, part search panel, wishlist heart, hero slider | Home, archives | Rewrite as Alpine components/modules |
| `assets/js/profile-auth.js` | Vanilla JS | Profile dropdown, mega category menu desktop/mobile | All pages with header | Rewrite as Alpine `x-data` menu components |
| `assets/js/cart-checkout.js` | jQuery, localized `gpCartCheckout`, Woo fragments | Mini-cart drawer, AJAX qty/remove/refresh, auth modal gating, cart/checkout label cleanup | All, cart, checkout | Replace with Livewire cart drawer; avoid DOM text mutation by rendering correct labels server-side |
| `assets/js/single-product.js` | Vanilla JS + jQuery Flexslider | Gallery arrows, add-to-cart fly animation | Product detail | Rebuild as Alpine gallery; optional animation after MVP |
| `assets/js/language-switcher.js` | Google Translate widget/cookie | Language selector and translation integration | All pages | Decide whether to keep Google Translate or replace with true i18n; preserve selected language cookie during migration if needed |
| WooCommerce `wc-cart-fragments` | jQuery | Cart count fragment updates | All Woo pages | Livewire/browser events update cart count |
| Woo gallery scripts | jQuery/Flexslider/photoswipe | Product images lightbox/slider | Product detail | Alpine/PhotoSwipe/GLightbox or native component |
| Google Identity Services | External | OAuth login/register | Auth modal/login/register | Laravel Socialite/Google Identity integration |

## WordPress/Woo hooks and custom frontend logic

| Source | Hook/function | Effect | Laravel equivalent |
|---|---|---|---|
| `functions.php` | `after_setup_theme` | Theme support and nav menus | App config/navigation seed |
| `functions.php` | `wp_enqueue_scripts` | Loads Poppins, CSS, JS, Woo assets, localized mini-cart config | Vite entrypoints and config JSON |
| `functions.php` | `wp_footer` | Hidden Google Translate element; part-number search box injection | Layout component slots |
| `functions.php` | `woocommerce_currency` + product price filters | Switches to EUR when selected language not PL, excluding cart/checkout/AJAX | Currency service with explicit context rules |
| `functions.php` | `init` custom taxonomy `gp_car_brand` | Brand terms derived from product title | Product brand table/materialized field |
| `functions.php` | `save_post_product` | Sync first-word brand term/meta | Import pipeline derives brand |
| `functions.php` | `woocommerce_show_page_title` | Hides Woo page title | Blade archive controls title rendering |
| `functions.php` | Woo loop hook removals/additions | Moves result count/order into `.gp-shop-toolbar` | Archive toolbar component |
| `functions.php` | `loop_shop_columns`, `loop_shop_per_page` | 3 columns, 60 products per page | Paginator config and CSS grid |
| `functions.php` | `woocommerce_add_to_cart_fragments` | Updates `.gp-mini-cart-count` | Livewire/cart event |
| `functions.php` | `gp_render_mini_cart_content` and AJAX actions | Drawer HTML, qty +/-/remove/refresh | Livewire `MiniCart` actions |
| `functions.php` | `gettext`, shipping label filters | Free shipping text/amount normalization | Render correct labels directly |
| `functions.php` | `woocommerce_cart_item_name` | Removes imported `Witam oferta dotyczy:` prefix in cart | Normalize display title in DTO |
| `functions.php` | `woocommerce_order_button_text` | Button text `Przejdź do płatności` | Checkout button component |
| `functions.php` | `wc_add_to_cart_message_html` | Suppresses default add-to-cart message | Laravel flash policy/no toast unless desired |
| `functions.php` | `woocommerce_product_tabs` | Renames tabs, removes reviews/compatibility, adds warranty/seller, Ovoko description/details | `ProductTabs` service |
| `functions.php` | `template_redirect` | Disables cache on product/shop/category pages | Laravel cache rules per route |
| `functions.php` | `woocommerce_get_breadcrumb` | Removes `Allegro ...` crumbs on product pages | Breadcrumb filter excludes marketplace-only categories |
| `functions.php` | Product category display cache helpers | Builds customer-visible category tree and hides technical/internal/Ovoko categories | Materialized storefront category tree |
| `functions.php` | `gp_render_product_category_sidebar` | Brand/category/subcategory/price filter sidebar | `CategorySidebar` component |
| `functions.php` | `pre_get_posts`, `posts_where`, `posts_search` | Part-number, brand, price, vehicle-model, search customizations | Query service/Search service |
| `functions.php` | same-vehicle query vars/routes | `/pojazd/{slug}` and `ovoko_car_id` archive filtering | Vehicle parts route/controller |
| `functions.php` | account return endpoint/actions | Customer return request flow | Account returns module |

## Shortcodes/widgets/custom endpoints

- Required pages are auto-created by theme for contact/login/register/privacy/returns/terms.
- Checkout content can be forced to `[woocommerce_checkout]` depending page/block conditions.
- AJAX endpoints used by frontend: `gp_update_mini_cart_quantity`, `gp_remove_mini_cart_item`, `gp_get_mini_cart`.
- Admin-post endpoints used by frontend: profile login/register, Google identity, contact form, return request submission.

## Assets to reproduce/drop

- Reproduce: header/search/mega menu/profile/mini-cart/product card/product gallery/sidebar/cart-checkout visible UI.
- Rewrite: all JS as Alpine/Livewire modules; no jQuery requirement in Laravel.
- Drop: WordPress hook architecture, Woo fragments, DOM mutation text fixes, debug/admin-only category display diagnostics.
- Preserve as data behavior: category visibility rules, part-number search normalization, imported title cleanup, Ovoko same-vehicle links, EUR language currency rule if business still wants it.
