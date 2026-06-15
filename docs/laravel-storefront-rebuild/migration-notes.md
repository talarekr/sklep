# Migration Compatibility and Screenshot Checklist

## Coexistence plan

1. Keep WordPress/WooCommerce live on `https://gpswiss.pl` initially.
2. Run Laravel storefront on staging/subdomain, e.g. `https://laravel-staging.gpswiss.pl` or behind basic auth.
3. Sync product/category/image/order-adjacent read data from Woo/Product Hub into Laravel read models.
4. Compare representative homepage/category/product/cart/checkout pages at identical viewport sizes.
5. Keep Woo as order/payment source until Laravel checkout is fully validated with gateways.
6. Cut over route groups gradually only after SEO and payment validation.

## SEO URL preservation

- Preserve `/produkt/{slug}/` product URLs where possible.
- Preserve `/kategoria-produktu/{nested-category-path}/` URLs.
- Preserve `/sklep/`, `/koszyk/`, `/zamowienie/`, `/moje-konto/`, `/kontakt/`, `/regulamin-platnosci/`, `/polityka-prywatnosci/`, `/zwroty/`.
- Preserve same-vehicle landing pages if public/crawled: `/pojazd/{vehicle_slug}/` and support/canonicalize `?ovoko_car_id=` if still used.
- Generate 301 redirects for any changed product/category slugs.
- Preserve canonical URLs, titles, meta descriptions, OpenGraph, product structured data, and breadcrumbs.
- Migrate image URLs carefully; either proxy old `/wp-content/uploads/...` paths for a transition period or redirect to new CDN paths.
- Maintain sitemap and robots rules; compare old and new sitemap counts before launch.

## Data sync considerations

- Product identity: Woo post ID, SKU, slug, source channel IDs, Ovoko part/car IDs.
- Product fields: title, formatted display title, description, short description, `_part_number`, OEM/MPN, categories, visible attributes, stock, price, regular/sale price, tax class.
- Images: listing image priority, featured image, gallery images, alt text, legacy URLs.
- Categories: raw marketplace/Ovoko tree vs customer-visible storefront tree; do not expose internal technical categories.
- Cart/checkout: shipping is visibly `0 zł` now, but Laravel should model methods/rates for future flexibility.
- Currency/language: decide whether current Google Translate + EUR price conversion stays or is replaced by real localization.

## Major risks/gaps

- Production WordPress database values were not queried in this documentation pass; exact active menu assignments, plugin activation list, payment gateway names, shipping methods, and real Woo pages must be confirmed in staging/admin or via WP-CLI.
- Screenshots are required to validate exact visual spacing, product image aspect ratios, cart/checkout block vs classic output, and mobile behavior.
- Category display cache logic is extensive; Laravel must reproduce customer-visible category filtering from actual production taxonomy data, not from raw `product_cat` alone.
- Imported content cleanup (`Witam oferta dotyczy:`), Ovoko details filtering, and AWI listing image selection are easy to miss but visible to customers.
- Google Translate based localization may produce different DOM than Laravel true translations; choose deliberately before parity testing.

## Manual screenshot checklist

Capture each at these viewport widths unless noted: desktop `1440px`, laptop `1280px`, tablet `768px`, mobile `390px`.

### Desktop/laptop/tablet/mobile

- Homepage full page and above-the-fold header/hero.
- Category page with products, sidebar filters, category hero, sorting, pagination.
- Empty category/search result if possible.
- Search results for a generic term and for a part number.
- Product detail page with multiple gallery images.
- Product detail page for an Ovoko product with same-vehicle button/details tab.
- Cart page with at least one item and multiple items.
- Checkout page with visible payment methods and terms checkbox.
- My account/login/register pages.
- 404 page.

### Specific interaction screenshots

- Desktop mega category menu open with a root category active.
- Mobile category menu open and nested child expanded.
- Profile dropdown logged out.
- Auth modal opened by checkout CTA while logged out.
- Mini-cart drawer empty and with items.
- Product gallery lightbox/zoom if enabled.

## Acceptance checklist for Laravel parity

- Header layout matches at 1440/1280/768/390.
- Product cards show same image source, part number, formatted title, price, and delivery labels.
- Category sidebar contains same visible category tree and filters.
- Search modes return comparable results for title, vehicle model, and part number.
- Product tabs match non-Ovoko and Ovoko products.
- Cart/checkout labels and free shipping display match.
- Legacy URLs resolve or 301 correctly.
