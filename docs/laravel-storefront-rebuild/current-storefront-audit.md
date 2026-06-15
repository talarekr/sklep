# Current Storefront Audit

## Theme and architecture

- Active theme in repository appears to be **Global Parts Clone** (`style.css` header), text domain `gp-clone`, version `1.0.0`.
- No child theme is visible in this repository snapshot; storefront files sit at repository/theme root.
- The storefront is a custom WordPress theme with WooCommerce support, custom WooCommerce templates, custom product category navigation, profile/auth UI, mini-cart, and custom cart/checkout text handling.
- Theme supports `title-tag`, post thumbnails, WooCommerce, WooCommerce gallery lightbox, and WooCommerce gallery slider.
- Registered menus: `top_bar`, `footer_1`, `footer_2`; however the current header/footer templates mostly hard-code visible customer links rather than rendering all registered menus.

## Main frontend plugins/systems affecting display or behavior

- **WooCommerce**: product archives, product details, cart, checkout, account, order received, gallery, fragments, notices, payment/shipping rendering.
- **AWI / Allegro Woo Importer inferred**: product card checks `\AWI\Plugin::get_listing_image_id_for_product()` and `_awi_listing_image_id` to choose listing images.
- **gpswiss-ovoko-integration**: Ovoko metadata affects product details, same-vehicle button, category logic, and backend source fields such as `_ovoko_part_id`, `_ovoko_car_id`, `_ovoko_listing_text`.
- **woo-ebay-integration / gps-ebay-fitment-sync / Allegro plugins**: primarily backend/channel integrations; do not copy backend UI, but preserve frontend-visible fields such as part number, compatibility/attributes, product image selections, stock, and imported descriptions.
- **Google Identity Services**: loaded when Google OAuth is configured for login/register/profile modal.
- **Google Translate widget**: hidden element plus language switcher; selected language cookie controls translation and EUR display outside cart/checkout.
- **PayU or payment gateways**: checkout payment methods are gateway-rendered; the docs treat them as payment provider placeholders.

## WooCommerce template overrides

- `woocommerce/archive-product.php`: custom shop/category/search archive wrapper with category hero, breadcrumb, sidebar filters, WooCommerce loop, ordering/result toolbar.
- `woocommerce/content-product.php`: list item wrapper that delegates product card markup to `template-parts/product/product-card.php`.
- `woocommerce/single-product.php`: single product shell around WooCommerce notices, breadcrumb, and custom content template.
- `woocommerce/content-single-product.php`: custom 3-column product hero: gallery, info/trust blocks, purchase box, tabs.
- `woocommerce/cart/cart-totals.php`: custom cart totals labels and free shipping display.
- `woocommerce/checkout/review-order.php`: custom checkout order review table with thumbnails and `0 zł` shipping row.
- `woocommerce/emails/email-header.php` and `woocommerce/emails/email-footer.php`: email-only; not core storefront except order confirmation/email visual continuity.

## Custom frontend hooks/actions/filters summary

- Enqueues Poppins, theme CSS, Woo CSS, home/profile/cart/language JS, single-product JS, `wc-cart-fragments`, and optional Google Identity Services.
- Currency switches to EUR when selected language is not Polish, except admin/AJAX/cart/checkout/order-pay.
- Product prices are converted from PLN to EUR with NBP exchange rate when language currency switching applies.
- `gp_car_brand` taxonomy is generated from first word of product title and used as a brand filter.
- Woo archive title/header/result count/order hooks are removed/re-added into a custom toolbar.
- Product archives use 3 columns and 60 products per page.
- Mini-cart fragments and three AJAX endpoints update quantity, remove item, and refresh mini-cart content.
- Cart/checkout labels are translated/rewritten (`Free shipping`/`FREE!`/`BEZPŁATNIE` -> `Koszt dostawy` / `0 zł`; order button -> `Przejdź do płatności`).
- Checkout block/classic safeguards and payment debugging exist; Laravel should not mirror debugging, only visible behavior.
- Product tabs are renamed/reordered; reviews/compatibility removed; warranty and seller tabs added; Ovoko products get a combined description/details tab.
- Product/category/search queries are customized for part-number search, brand filter, price range, vehicle-model mode, and same-vehicle (`ovoko_car_id`) routes.
- Product category display is cached into a custom visible tree to avoid exposing technical/Ovoko/internal categories.

## Main CSS/JS files

- `style.css`: global theme, header, homepage, mega menu, product card, auth/profile, static pages, broad responsive rules.
- `assets/css/woocommerce.css`: WooCommerce product archives, product details, cart/checkout, mini-cart, auth modal, footer, shop sidebar, responsive Woo-specific layout.
- `assets/js/home.js`: category search mode switch, closable bars, sidebar filters, part-number search panel, wishlist heart toggle, homepage hero slider.
- `assets/js/profile-auth.js`: profile dropdown, all-categories mega menu desktop hover/focus and mobile toggles.
- `assets/js/cart-checkout.js`: mini-cart drawer, auth modal interception for order CTAs, cart/checkout text cleanup, block mutation observers, checkout enhancements.
- `assets/js/single-product.js`: gallery prev/next buttons and add-to-cart fly animation.
- `assets/js/language-switcher.js`: selected-language cookie/Google Translate integration.

## Menus and category navigation

- Header top links: `Kontakt`, language selector, Rzetelna Firma badge.
- Header row: logo, full-width search, profile dropdown, mini-cart drawer.
- Navigation row: `Menu` mega category dropdown, fixed shortcut links (`Silniki`, `Skrzynia biegów`, `Filtry DPF`, `Felgi`, `Fotele`, `Zwrotnice`), phone numbers.
- Category tree source is WooCommerce `product_cat`, filtered through custom display-cache helpers. The mega menu uses root categories, level-2 panels, level-3 links, and mobile nested collapsible children.
- Current Ovoko/category issue: code explicitly filters technical/internal/Ovoko category structures into a customer-facing cached tree. Laravel must keep a separate `visible_in_storefront`/`menu_visible` category projection rather than showing raw marketplace category trees.

## Storefront page behavior overview

- Home page: header/hero/popular products; hero currently uses `https://gpswiss.pl/wp-content/uploads/baner.png`.
- Archive/category: category search hero for product categories, breadcrumbs, sidebar with brand/category/subcategory/price filters, product grid, sorting, result count, pagination.
- Product detail: Woo gallery, product info card, trust boxes, purchase box, tabs, Ovoko same-vehicle button when applicable.
- Search: main header search submits `s` and `post_type=product`; category hero can search by `part_number` or vehicle model.
- Cart/checkout: WooCommerce pages with custom labels, mini-cart drawer, guest users intercepted by auth modal for order CTA, free shipping displayed as `0 zł`.
- Account/login/register: custom static templates `/zaloguj` and `/zarejestruj` plus Woo account endpoints; Google OAuth optional.
