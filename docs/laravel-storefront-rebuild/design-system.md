# Visual Design System

## Tokens found in CSS

```css
--gp-bg: #f5f5f5;
--gp-text: #1f1f1f;
--gp-muted: #6a6a6a;
--gp-border: #d8d8d8;
--gp-primary: #d82a2a;
--gp-navy: #122a66;
--gp-red: #e10613;
```

Additional frequent values:

- Body background: `#fff`.
- Search border: `#cfd4dc`; language select border: `#cfd6e2`.
- Product/card neutral backgrounds: `#fff`, `#fafafa`, `#f8f8f8`, `#ececec`.
- Sale/current emphasis: black for normal price, `#dc2626` sale, `#c81f35` discount badge.
- Dark favorite active: `#111827`.

## Typography

- Font family: **Poppins**, loaded from Google Fonts with weights `400,500,600,700`.
- Base body: 14px, line-height 1.45.
- Header search input: 22px; placeholder 17px.
- Section title: 22px.
- Product card current price: 16px, 700, line-height 24px.
- Product card title mobile: 14px; product meta/shipping 12–13px.
- Product detail title: desktop from Woo CSS, tablet documented at 26px in responsive rule.

## Layout and spacing

- Main container: `.gp-container`, max-width `1320px`, horizontal padding `12px`.
- Header row desktop grid: `220px minmax(360px, 1fr) auto`, gap `16px`.
- Header search min-height `52px`, border radius `14px`.
- Category mega desktop max height uses CSS variables around `620px` and viewport height.
- Product grids:
  - Home legacy `.gp-products`: 4 columns desktop, 2 below 1199px, 1 below 767px.
  - Woo archive columns controlled by Woo + `loop_shop_columns = 3`; verify CSS/screenshot for tablet/mobile final counts.
- Product archive per page: 60.

## Buttons and controls

- `.gp-btn`: inline-block/flex button style, generally bold, rounded in dropdown contexts, navy primary.
- `.gp-btn--primary`: `--gp-navy` background, white text.
- `.gp-btn--outline`: navy border and text.
- Search submit button: navy background, white, bold, min-width 84px.
- Product favorite: heart icon button; active state dark background and white text.
- Mini-cart qty buttons: small +/- controls inside item action row.

## Forms

- Header search is a three-column grid: icon, input, submit.
- Category hero search uses tab buttons (`Numer części`, `Model pojazdu`) and swaps active input names (`part_number` vs `s`).
- Sidebar filters include select controls for brand/category and numeric inputs for price range.
- Auth modal includes email/password inputs, password visibility toggle, remember checkbox, Google OAuth slot.

## Product labels and notices

- Product card displays `Numer części: <strong>...</strong>`.
- Delivery labels: `Darmowa dostawa: {date}` and `Jeśli zapłacisz do 13:30`.
- Stock/availability is not emphasized in product cards; product detail uses a fixed state label `Używany / sprawdzony`.
- Woo notices should use WooCommerce notices styling from `assets/css/woocommerce.css`; Laravel should create `Alert` components for success/error/info matching that visual language.

## Borders, radius, shadows

- Global borders use `--gp-border` or light gray variants.
- Search radius: 14px.
- Language select radius: 8px.
- Product cards use white background, subtle border/shadow/hover in CSS; preserve card dimensions and image containment based on screenshots.

## Icons

- Current implementation uses emoji/HTML entities for search (`🔍`), profile (`👤`), cart (`🛒`), phone (`☎`), menu (`☰`), chevrons (`›`), modal close (`×`).
- Laravel can keep exact glyphs for 1:1 MVP, then replace with SVG only after visual approval.

## Responsive breakpoints

- Important CSS breakpoints found: `1200px`, `1199px`, `1024px`, `900px`, `768px`, `767px`.
- Mega menu mobile behavior begins at max-width `1199px`.
- Many single product/cart refinements switch at `1024px` and `767px`.
- Screenshot review must cover 1440, 1280, 768, and 390 widths.

## Loading states

- Product images use lazy loading except first hero image.
- Mini-cart refresh is AJAX-driven but no strong spinner/loading state was identified; Laravel should add subtle disabled/loading state on qty and remove actions while preserving no-layout-shift behavior.
