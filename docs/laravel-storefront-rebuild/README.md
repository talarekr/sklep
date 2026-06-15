# GPSWISS Laravel Storefront Rebuild — Current WordPress/WooCommerce Frontend Specification

This documentation package audits the current `gpswiss.pl` customer storefront theme and WooCommerce frontend customizations for a future Laravel rebuild. It is documentation only; no storefront source, plugin code, WooCommerce data, production configuration, or behavior was changed.

## Files

- `current-storefront-audit.md` — theme, plugin/frontend behavior overview, routes, templates, data sources, risks.
- `page-templates.md` — page-by-page URL/template/layout/component mapping and Laravel equivalents.
- `components.md` — header, navigation, footer, product cards, listing, product detail, search, cart/checkout component specs.
- `design-system.md` — colors, typography, spacing, responsive breakpoints, notices, buttons, forms, product visual rules.
- `assets-and-hooks.md` — CSS/JS inventory and WordPress/WooCommerce hook/filter/custom logic inventory.
- `laravel-implementation-plan.md` — recommended Laravel stack, routes, controllers, DTOs, component/view hierarchy, Vite/CSS strategy.
- `migration-notes.md` — coexistence plan, SEO URL preservation, screenshot checklist, migration risks.

## Primary recommendation

Use **Laravel Blade + Livewire + Alpine.js**, with Vite and a custom CSS layer that ports the storefront design tokens and component classes intentionally. The existing site is mostly server-rendered WooCommerce markup plus lightweight JavaScript for menus, search switching, modals, mini-cart AJAX, and gallery controls, so a full SPA is not required for a close 1:1 rebuild.

## Key source areas inspected

- Theme bootstrap and hooks: `functions.php`.
- Theme layout templates: `header.php`, `footer.php`, `front-page.php`, `page.php`, `search.php`, static page templates.
- Header/home partials: `template-parts/home/*.php`.
- Product card partial: `template-parts/product/product-card.php`.
- WooCommerce overrides: `woocommerce/archive-product.php`, `woocommerce/single-product.php`, `woocommerce/content-single-product.php`, `woocommerce/content-product.php`, `woocommerce/cart/cart-totals.php`, `woocommerce/checkout/review-order.php`, email header/footer overrides.
- Assets: `style.css`, `assets/css/woocommerce.css`, `assets/js/home.js`, `assets/js/profile-auth.js`, `assets/js/cart-checkout.js`, `assets/js/single-product.js`, `assets/js/language-switcher.js`.
- Relevant integration plugins were identified as data/backend influences, but not modified.
