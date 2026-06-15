# Storefront Components

## Header component proposal

### Current structure

1. Optional top/promo bars on homepage.
2. `.gp-main-header__top-links`: contact link, language selector, Rzetelna Firma image.
3. `.gp-main-header__row`: logo left, large search center, profile and mini-cart actions right.
4. `.gp-main-header__nav-row`: all-category mega menu, category shortcuts, phone numbers.
5. Auth modal exists globally after the header.

### Laravel components

- `x-storefront.header`
  - Props: logo URL/alt, cart count, authenticated user, selected language, contact links, category shortcuts, customer-visible category tree.
- `x-storefront.search`
  - Props: query, placeholder, mode, action URL.
  - Preserve `s` and `post_type=product` compatibility during migration.
- `x-storefront.category-mega-menu`
  - Props: root categories with children and grandchildren, active root.
  - Desktop: hover/focus activates secondary panel.
  - Mobile/tablet: accordion nested list below 1199px.
- `x-storefront.mobile-navigation`
  - Same tree as mega menu; no separate source of truth.
- `x-storefront.mini-cart`
  - Livewire component for drawer, qty increment/decrement, remove, subtotal, checkout/cart links.
- `x-storefront.profile-menu`
  - Auth-aware dropdown with login/register links or account/orders/logout links.
- `x-storefront.auth-modal`
  - Login compact form, register CTA, continue-as-guest checkout link, optional Google button.

## Footer component

- Current footer columns:
  - `GP GREGOR Swiss`: Kontakt, Regulamin, Polityka prywatności.
  - `Kontakt`: `tel. 504 266 984`, `biuro@gpswiss.pl`.
- Laravel footer should include legal links, contact data, and be extensible for payment/shipping/social badges if screenshots reveal additional production widgets.

## Product card component

### Current fields

- Product ID and permalink.
- Listing image selected from AWI listing image if available, else featured image, else Woo placeholder.
- Wishlist heart button (client-only toggle currently, no persistence visible).
- Part number from `_part_number`, fallback `Brak`.
- Formatted display title using vehicle prefix / part-name logic.
- Current price via Woo `wc_price()` with language/currency conversion.
- Delivery text (`Darmowa dostawa: {date}`) and cutoff (`Jeśli zapłacisz do 13:30`).

### Laravel DTO fields

```php
ProductListItemDTO {
  int id;
  string slug;
  string url;
  string title;
  string displayTitle;
  ?string partNumber;
  MoneyDTO price;
  ?MoneyDTO regularPrice;
  ImageDTO listingImage;
  bool inStock;
  string deliveryLabel;
  string deliveryCutoffLabel;
  ?string source;
  ?string ovokoCarId;
}
```

## Category archive component fields

```php
CategoryArchiveDTO {
  CategoryDTO currentCategory;
  array<CategoryDTO> breadcrumbs;
  array<CategoryDTO> visibleCategoryTree;
  array<BrandDTO> brands;
  FilterStateDTO filters;
  LengthAwarePaginator<ProductListItemDTO> products;
  array sortOptions;
  ?string partNumberQuery;
  ?string vehicleModelQuery;
}
```

## Product detail components/data

```php
ProductDetailDTO {
  int id;
  string slug;
  string title;
  string displayTitle;
  string sku;
  ?string partNumber;
  ?string oemNumber;
  MoneyDTO price;
  StockDTO stock;
  array<ImageDTO> gallery;
  array<CategoryDTO> categories;
  string shortDescriptionHtml;
  string descriptionHtml;
  array<AttributeDTO> visibleAttributes;
  ?FitmentDTO fitment;
  ?string ovokoCarId;
  ?string sameVehicleUrl;
  array<ProductListItemDTO> relatedProducts;
  SeoMetaDTO seo;
}
```

### Product page components

- `ProductGallery`: large image, thumbnails, arrows, zoom/lightbox.
- `ProductInfoCard`: title, part number, state, short description or same-vehicle CTA.
- `TrustBlocks`: delivery, payment methods image/badges, returns.
- `PurchaseBox`: price, tax note, quantity, add to cart, contact CTA, helper copy.
- `ProductTabs`: description/details, additional information, warranty, seller.

## Search component

- Header search: global product search over title and `_part_number` at minimum.
- Category search hero: two modes:
  - `part_number`: exact/normalized partial match against `_part_number` and ignores category tax query in current code.
  - `vehicle_model`: title terms must all match, scoped to category context.
- MVP Laravel: database `LIKE`/fulltext on product title, slug, SKU, part/OEM fields; add category joins only after matching production behavior.
- Later: Meilisearch/Typesense/OpenSearch with synonyms for Polish automotive terms and normalized part-number fields.

## Cart/checkout components

- `CartDrawer` / `MiniCart`: item thumbnail, name, current/regular price, qty +/- buttons, remove, subtotal, cart/checkout links.
- `CartPage`: full list, coupon area if enabled, totals, free shipping row, checkout CTA.
- `CheckoutForm`: billing, shipping, customer email/phone, account choice, notes, terms checkbox.
- `ShippingMethods`: structure should support groups/rates even though current visible cost is `0 zł`.
- `PaymentMethods`: gateway placeholders; PayU/BLIK/Google Pay if configured in production.
- `OrderSummary`: thumbnail row, name, quantity, line total, subtotal, delivery, fees/taxes, total.
