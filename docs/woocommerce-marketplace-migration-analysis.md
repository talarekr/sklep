# Dokumentacja techniczna WooCommerce/WordPress → Laravel marketplace migration

## 1. Executive summary

Analiza jest statyczna i bezpieczna: obejmuje kod repozytorium WordPress/WooCommerce, pluginy marketplace, testy i istniejące eksportery. Nie wykonano wywołań API do eBay/Ovoko/Allegro ani zapisów do Woo/Laravel. W repozytorium nie ma dumpa bazy produkcyjnej, więc liczby produktów per meta key, przykładowe wartości z produkcji i realne custom tables trzeba potwierdzić zapytaniami SQL/WP-CLI na kopii bazy.

Najważniejsze ustalenia:

- Produkty Woo są eksportowane do Laravel przez `gps-ebay-fitment-sync` w pakiecie `products.csv`, `product_images.csv`, `product_categories.csv`, `product_attributes.csv`, `product_meta.csv` oraz summary; głównym kluczem migracyjnym jest `woo_product_id` plus `_sku`.
- eBay DE i eBay FR są modelowane jako osobne kanały/listingi. Główne źródło mapowania to tabela `wp_marketplace_mappings` z kolumnami `marketplace`, `woo_product_id`, `sku`, `remote_inventory_id`, `remote_offer_id`, `remote_listing_id`, `marketplace_id`, `status`. Dodatkowo istnieją post meta `_wei_ebay_*` i `_wei_fr_ebay_*`.
- eBay używa Sell Inventory API do inventory item / offer / publish / stock-price oraz Trading API `EndFixedPriceItem` do kończenia listingów po `listing_id`.
- Fitment/KType obsługuje osobny plugin `gps-ebay-fitment-sync`; do aktualizacji kompatybilności używa endpointu Sell Inventory `PUT /sell/inventory/v1/inventory_item/{sku}/product_compatibility` z marketplace headers `Content-Language` i `X-EBAY-C-MARKETPLACE-ID`.
- Ovoko/RRR działa w pluginie `gpswiss-ovoko-integration`: donor cars są pobierane read-only z `/v2/get/cars` i opcjonalnie hydratowane przez `/get/car/{id}`; produkty Gmail mają SKU `GPS-GMAIL-*` i są aktualizowane tylko, gdy mają `_ovoko_part_id`/`ovoko_part_id`.
- Allegro nie jest tylko legacy: w repo są dwa pluginy: `allegro-woo-importer` oraz `gpswiss-allegro-gearboxes`. Główne meta Allegro to `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_category_id`, `_allegro_status`, `_allegro_parameters`, `_secondary_allegro_offer_id`, `_secondary_allegro_account`, `_imported_from_secondary_allegro`.
- Laravel powinien dostać osobny eksport `woo_marketplace_mapping_products.csv` oraz kanałowe eksporty `woo_ebay_listings.csv`, `woo_ovoko_mapping.csv`, `woo_allegro_legacy_mapping.csv`; obecny `product_meta.csv` jest koniecznym fallbackiem, ale nie wystarcza jako wygodny kontrakt integracyjny.

## 2. Obecny przepływ danych produktów w Woo

Źródłem bazowym produktu WooCommerce jest:

- `wp_posts`: `ID`, `post_title`, `post_name`, `post_status`, `post_type=product/product_variation`, `post_content`, `post_excerpt`, `post_date`, `post_modified`.
- `wp_postmeta`: `_sku`, `_regular_price`, `_sale_price`, `_price`, `_stock`, `_stock_status`, `_manage_stock`, `_thumbnail_id`, `_product_image_gallery`, wymiary, waga oraz meta marketplace/importowe.
- `wp_terms/wp_term_taxonomy/wp_term_relationships`: `product_cat`, tagi, atrybuty taksonomiczne.
- `wp_termmeta`: miniatury kategorii, display type i część mapowań.
- custom tables pluginów: głównie eBay marketplace mappings, eBay category mappings, sync queue i category tree cache.

Aktualny eksport Woo → Laravel iteruje po `post_type IN ('product','product_variation')`, zapisuje rekord główny produktu oraz osobne pliki obrazów, kategorii, atrybutów i meta. Kolumny produktu zawierają także pola pod marketplace: eBay FR/DE item/offer/inventory SKU, Allegro offer ID i Ovoko part ID.

## 3. Pluginy i odpowiedzialności

| Plugin/katalog | Odpowiedzialność | Znaczenie migracyjne |
|---|---|---|
| `wp-content/plugins/gps-ebay-fitment-sync/` | eksport Woo→Laravel, audyty fitment/KType, live/dry-run aktualizacji eBay product compatibility, category tree export | kluczowy dla eksportów technicznych, fitment i mapowania kategorii |
| `wp-content/plugins/woo-ebay-integration/` | eBay DE: publish, mappingi, order polling, stock sync, category mapping, content translation DE | główne źródło DE listing/offer/inventory mapping |
| `wp-content/plugins/woo-ebay-integration-fr/` | eBay FR analogicznie do DE | główne źródło FR listing/offer/inventory mapping |
| `wp-content/plugins/gpswiss-ovoko-integration/` | Ovoko/RRR, donor cars export, Gmail draft update, Woo→Ovoko CRM-only preview/import | główne źródło `ovoko_part_id`, donor car i readiness/block reasons |
| `wp-content/plugins/gps-gmail-product-importer/` | tworzenie draftów z Gmail, SKU `GPS-GMAIL-*`, price/category/Ovoko enrichment | źródło draftów i meta diagnostycznych |
| `wp-content/plugins/allegro-woo-importer/` | import ofert Allegro main do Woo | źródło legacy/main Allegro meta |
| `wp-content/plugins/gpswiss-allegro-gearboxes/` | drugie konto/kanał Allegro Gearboxes, import, order/offer event sync, blokady kanałów | źródło `_secondary_allegro_offer_id` i channel guards |

## 4. Model produktu Woo i najważniejsze meta keys

### Pola bazowe produktu

Eksporter `WooLaravelProductExport` buduje rekord `products.csv` z kolumnami: `woo_product_id`, `post_id`, `product_type`, `sku`, `name`, `slug`, `permalink`, `status`, `published`, `catalog_visibility`, `short_description`, `description`, ceny, stock, wymiary, kategorie, tagi, atrybuty JSON, obrazy, daty, part/OEM/MPN/EAN/condition/brand/manufacturer, donor/vehicle fields oraz marketplace IDs.

### Istotne meta keys i migracja

| Meta key / grupa | Znaczenie | Przykład/format | Źródło typu | Przenieść do Laravel? |
|---|---|---|---|---|
| `_sku` | podstawowy SKU Woo | `GPSW-2177`, `GPS-GMAIL-60886` | źródłowe | Tak, klucz migracyjny i fallback matching |
| `_regular_price`, `_sale_price`, `_price` | cena Woo | decimal string | źródłowe/wyliczone | Tak, normalizować jako price history/source |
| `_stock`, `_stock_status`, `_manage_stock` | stock Woo | `0`, `instock/outofstock` | źródłowe/operacyjne | Tak, dla stock baseline |
| `_thumbnail_id`, `_product_image_gallery` | obrazy produktu | attachment IDs CSV | źródłowe | Tak przez `product_images.csv` |
| `part_number`, `_part_number`, `oem_number`, `_oem_number`, `manufacturer_code`, `_manufacturer_code`, `_mpn`, `ean/_ean` | numery części/OEM/MPN/EAN | string | źródłowe/importowe | Tak, normalizować |
| `ovoko_part_id`, `_ovoko_part_id`, `rrr_part_id`, `_rrr_part_id` | ID części Ovoko/RRR | numeric string | marketplace | Tak, unikalny mapping Ovoko |
| `_ovoko_car_id`, `ovoko_car_id`, `_rrr_car_id`, `_ovoko_donor_car_id` | donor/vehicle ID | numeric string | marketplace | Tak |
| `_gps_*` Gmail/Ovoko | status importu Gmail, category/price suggestions, readiness/blocking | JSON/string | importowe/diagnostyczne | Tak do raw/legacy payload, wybrane pola normalizować |
| `_wei_ebay_listing_id`, `_wei_ebay_offer_id`, `_wei_ebay_inventory_item_id`, `_wei_ebay_sku`, `_wei_ebay_marketplace` | eBay DE post meta | listing/offer/SKU | marketplace | Tak jako fallback; tabela mappings ważniejsza |
| `_wei_fr_ebay_listing_id`, `_wei_fr_ebay_offer_id`, `_wei_fr_ebay_inventory_item_id`, `_wei_fr_ebay_sku`, `_wei_fr_ebay_marketplace` | eBay FR post meta | listing/offer/SKU | marketplace | Tak jako fallback |
| `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_category_id`, `_allegro_status`, `_allegro_parameters` | Allegro main importer | offer ID/URL/category/status/payload | marketplace/legacy | Tak |
| `_secondary_allegro_offer_id`, `_secondary_allegro_account`, `_imported_from_secondary_allegro` | Allegro Gearboxes | offer ID/account flag | marketplace | Tak |
| `_source_marketplace`, `_allegro_export_blocked`, `_ebay_export_allowed`, `_wei_ebay_export_blocked`, `_channel_*_enabled` | channel guard/source flags | `allegro`, `yes/no` | operacyjne | Tak, do channel eligibility/legacy_payload |

Brak dumpa DB oznacza, że pola „liczba produktów” i produkcyjne przykłady wartości trzeba wygenerować z SQL. Minimalne zapytanie:

```sql
SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS products_count,
       MIN(pm.meta_value) AS sample_value
FROM wp_postmeta pm
JOIN wp_posts p ON p.ID = pm.post_id
WHERE p.post_type IN ('product','product_variation')
  AND (pm.meta_key REGEXP 'sku|part|oem|manufacturer|vehicle|donor|ovoko|rrr|gmail|allegro|ebay|listing|offer|auction|item|inventory|external|source|marketplace|sync|price|stock')
GROUP BY pm.meta_key
ORDER BY products_count DESC, pm.meta_key;
```

## 5. SKU i identyfikatory

| Format | Przykład | Źródło | Znaczenie | Przydatność | Ryzyko |
|---|---|---|---|---|---|
| klasyczny SKU Woo/eBay | `GPSW-2177` | Woo/eBay generator | SKU części i często inventory SKU | dobre, jeśli unikalne | możliwe różnice DE/FR i historyczne zmiany |
| Gmail draft | `GPS-GMAIL-60886` | Gmail importer / Ovoko workflow | draft z Gmail powiązany później z Ovoko | dobre do identyfikacji draftów, nie jako marketplace public SKU | duplikaty testowo występują w testach; wymaga walidacji unikalności |
| eBay inventory SKU | `remote_inventory_id`, `_wei*_ebay_inventory_item_id` | eBay Sell Inventory | inventory item ID/SKU | bardzo dobre dla eBay | może być puste przy starym Trading listing |
| Allegro offer/signature | `_allegro_offer_id`, `_secondary_allegro_offer_id`; signature niepotwierdzona w kodzie jako stałe meta | Allegro import | ID oferty Allegro | offer ID dobre; signature/external ID wymagają API/CSV | stary importer może nadpisywać/usuwać część meta |

Walidacje do wykonania na DB:

```sql
SELECT meta_value AS sku, COUNT(*) c FROM wp_postmeta WHERE meta_key='_sku' AND meta_value<>'' GROUP BY meta_value HAVING c>1;
SELECT COUNT(DISTINCT post_id) FROM wp_postmeta WHERE meta_key='_sku' AND meta_value LIKE 'GPS-GMAIL-%';
```

## 6. Marketplace identifiers w Woo

### eBay

Źródła:

1. `wp_marketplace_mappings`: kanoniczne mapowanie listingów eBay.
2. Post meta `_wei_ebay_*` i `_wei_fr_ebay_*`: fallback/legacy/cache po publikacji.
3. Eksport `products.csv`: pola `ebay_fr_item_id`, `ebay_fr_offer_id`, `ebay_fr_inventory_sku`, `ebay_de_item_id`, `ebay_de_offer_id`, `ebay_de_inventory_sku`.
4. Fitment audit rows: KType, listing status, inventory SKU, offer ID, item ID per marketplace.

### Ovoko/RRR

Źródła:

- product meta `ovoko_part_id`, `_ovoko_part_id`, `rrr_part_id`, `_rrr_part_id`.
- donor car fields `ovoko_car_id`, `_ovoko_car_id`, `rrr_car_id`, `_rrr_car_id`, `donor_car_id`, `vehicle_id`.
- Gmail/Ovoko meta `_gps_ovoko_*`, `_gps_gmail_*`.
- donor cars export `ovoko_donor_cars.csv` i `ovoko_donor_cars_summary.json`.

### Allegro

Źródła:

- `allegro-woo-importer`: `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_category_id`, `_allegro_currency`, `_allegro_status`, `_allegro_parameters`, `_allegro_imported_at`, `_awi_source_url`.
- `gpswiss-allegro-gearboxes`: `_secondary_allegro_offer_id`, `_secondary_allegro_account`, `_imported_from_secondary_allegro`, `_source_marketplace=allegro`, channel flags.
- Aktualne sparowanie z Allegro trzeba potwierdzić eksportem ofert z Allegro API `/sale/offers` + `/sale/product-offers/{offer_id}` lub CSV, bo kod nie potwierdza trwałego `external_id/signature` jako głównego meta.

## 7. eBay — jak działa obecnie

### API i mapping

Integracja eBay DE/FR używa Sell Inventory API: inventory item, offer, publish offer, get/update offer, stock/price updates. Klient eBay dodaje marketplace context przez `marketplace_id`; fitment live wymusza `Content-Language` (`de-DE`/`fr-FR`) i `X-EBAY-C-MARKETPLACE-ID` (`EBAY_DE`/`EBAY_FR`).

Dla ending listingów stock sync używa Trading API `EndFixedPriceItem` po `listing_id`; gdy to zawiedzie, fallbackuje do Sell Inventory bulk price/quantity po `offer_id`, a dalej może próbować delete offer.

### Queue i logi

Tabela `wp_wei_ebay_sync_queue` przechowuje `product_id`, `reason`, `status`, `queued_at`, `attempts`, `last_error`, `source`. Log event `EBAY_SYNC_PRODUCT_QUEUED` oznacza dodanie produktu do kolejki. Log `WEI_STOCK_SYNC_EBAY_API_CALL` oznacza realną próbę API stock/order/ending: `get_orders`, `end_fixed_price_item_by_listing_id`, `bulk_update_price_quantity`.

### Rozpoznanie istniejącej aukcji

Kolejność pewności:

1. `wp_marketplace_mappings` row z `marketplace='ebay'`/DE lub odpowiednim FR plugin namespace, `marketplace_id=EBAY_DE/EBAY_FR`, aktywnym `status` i `remote_listing_id`/`remote_offer_id`.
2. Post meta `_wei_ebay_listing_id`, `_wei_ebay_offer_id`, `_wei_ebay_inventory_item_id` lub wariant `_wei_fr_*`.
3. eBay API read-only po offer ID/SKU w audycie remap, aby potwierdzić status `PUBLISHED/ACTIVE` i marketplace.

Żeby Laravel uniknął duplikatów, przed publikacją musi mieć unikalny indeks `(marketplace_account_id, marketplace, marketplace_listing_id)` oraz `(marketplace_account_id, marketplace, marketplace_offer_id)` i `(marketplace_account_id, marketplace, inventory_sku)`.

### Dane eksportowe eBay

`woo_ebay_listings.csv` powinien mieć: `woo_product_id`, `sku`, `marketplace` (`EBAY_DE`/`EBAY_FR`), `account/plugin_source`, `ebay_inventory_sku`, `ebay_offer_id`, `ebay_listing_id`, `ebay_item_id`, `ebay_category_id`, `listing_status`, `offer_status`, `price`, `quantity`, `currency`, `url`, `mapping_source`, `last_sync_at`, `raw_payload`.

### Fitment/KType

Fitment bazuje na OEM/part number, normalizacji part number, skanowaniu produktów i cache/lookup KType. Payload compatibility trafia do inventory item przez `/sell/inventory/v1/inventory_item/{sku}/product_compatibility`. Eksportować trzeba: `woo_product_id`, `marketplace`, `inventory_sku`, `part_number_normalized`, `oem_numbers`, `vehicle_ids/ktypes`, `ktype_count`, `sample_ktypes`, `payload_json`, `blocked_reason`. Rekomendacja: w Laravel odtworzyć fitment jako etap 2; etap 1 powinien jedynie przejąć istniejące listingi i stock/order sync.

## 8. Ovoko/RRR/Gmail — jak działa obecnie

### Donor cars

Admin panel wskazuje read-only eksport donor cars przez stronicowane `POST /v2/get/cars?limit=100&page=N`; diagnostyka może robić mały probe `POST /v2/get/cars?limit=5&page=1` i hydratację `POST /get/car/{id}`. Model dictionary cache jest osobnym, wznawialnym procesem dla `/get/car_models/{brand_id}` z limitem 1–5 endpointów na tick i stopem przy HTTP 500.

Słowniki obejmują brand, model, fuel, gearbox, wheel drive, wheel type/steering side, body type, color. Testy potwierdzają, że eksport zachowuje raw IDs i próbuje rozwiązywać labelki słownikowe bez masowego wywoływania modeli. Krytyczne pola donor cars: `ovoko_car_id`, raw IDs i labelki make/model/fuel/gearbox/body/color/wheel, VIN/body number, year, engine, mileage, source/warnings. Fallback `car_wheel_type=0 => Lewa strona` trzeba utrzymać jako regułę migracyjną, jeśli występuje w produkcyjnym eksporcie/override.

### Gmail product flow

Gmail updater jest bezpiecznie ograniczony do istniejących produktów Woo, których SKU zaczyna się od `GPS-GMAIL-` i które mają `_ovoko_part_id` lub `ovoko_part_id`. Nie tworzy produktów i nie importuje/reorderuje obrazów. Preview jest read-only; live update wymaga confirmation. Tytuł jest budowany przez istniejący builder Ovoko/Woo, nie przez surowy tytuł Ovoko. Obrazy są zachowywane (`preserve Woo images` locked ON). `publish_when_ready` publikuje tylko, gdy produkt przechodzi readiness.

Blokady/readiness obejmują: `missing_price`, `missing_category`, `missing_title`, `missing_description`, `missing_existing_woo_images`, `ovoko_fetch_failed`, `category_mapping_failed`. `missing_price` pozostawia produkt draft/non-public; CRM-only import może celowo pomijać cenę i dostać R202 warning z Ovoko.

Admin tools: Preview one product, Update one product, Preview eligible Gmail products, Run batch update. Batch live wymaga dokładnego tekstu `RUN GMAIL BATCH UPDATE`, ma batch size/max batches/delay/stop-on-first-error i pokazuje raw JSON/technical details.

## 9. Allegro legacy — co znaleziono

Istnieje aktywny kod Allegro:

- `allegro-woo-importer`: OAuth, `/sale/offers`, `/sale/product-offers/{offer_id}`, importer, cron, product mapper.
- `gpswiss-allegro-gearboxes`: drugi kanał/konto, OAuth scopes sale offers read/write i orders read, offer events, order events, stock-to-zero, import guard i channel guard.

`allegro-woo-importer` zapisuje `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_category_id`, `_allegro_currency`, `_allegro_status`, `_allegro_parameters`, `_allegro_imported_at`. `gpswiss-allegro-gearboxes` aplikuje `_secondary_allegro_offer_id`, `_source_marketplace=allegro`, blokuje eBay export i ustawia kanały `_channel_allegro_gearboxes_enabled=yes`, `_channel_allegro_main_enabled=no`, `_channel_ebay_de_enabled=no`.

Nie potwierdzono w kodzie produkcyjnego meta `_allegro_signature` ani `_allegro_external_id` jako trwałego standardu. Dlatego dla pełnego sparowania Allegro z Laravel potrzebny jest aktualny eksport ofert Allegro API/CSV z polami: offer ID, external ID, signature, SKU, name, category, status, publication, stock, price, images i URL.

## 10. Kategorie i mapping marketplace categories

Woo kategorie to `product_cat` z `term_id`, `parent`, `slug`, ścieżką full path i count. `WooLaravelProductExport` eksportuje `woo_category_tree.csv/json/summary` z rozszerzeniem eBay: `ebay_category_id`, `ebay_category_name`, `ebay_category_path`, `ebay_marketplace`, `ebay_mapping_source`, osobne DE/FR ID/nazwy/ścieżki.

Mapowanie eBay jest w tabeli `wp_wei_ebay_category_mappings`: `marketplace_id`, `woo_term_id`, `woo_category_path`, `ebay_category_id`, `ebay_category_name`, `ebay_category_path`, `source`, `confidence`, `status`, `active`, validation fields. Dodatkowo istnieją teaching rules `wp_wei_ebay_category_teaching_rules` i cache taxonomy `wp_wei_ebay_category_tree_cache`. Mapping jest per marketplace (`EBAY_DE` i `EBAY_FR`).

Ovoko category mapping istnieje jako meta/suggestions `_gps_ovoko_category_*` i usługi category suggestions. Allegro category mapping jest głównie w `_allegro_category_id` per produkt; brak potwierdzonej globalnej tabeli mapowania Allegro kategorii Woo.

## 11. Eksporty Woo → Laravel

| Plik | Status | Zawartość | Użycie Laravel | Braki pod marketplace |
|---|---|---|---|---|
| `products.csv` | istnieje | główny rekord produktu i wybrane legacy marketplace fields | import/update `parts` | listing status/raw mappings per marketplace |
| `product_images.csv` | istnieje | image ID/URL/alt/position/is_primary | `part_images` | source marketplace image URL/history |
| `product_categories.csv` | istnieje | produkt→kategoria/path/slug | `part_categories` | marketplace category per listing |
| `product_attributes.csv` | istnieje | atrybuty produktu | `part_attributes` | normalizacja OEM/fitment |
| `product_meta.csv` | istnieje | pełny raw meta dump produktu | fallback/legacy payload | wymaga parsowania i agregacji |
| `export_summary.json` | istnieje | status/liczniki exportu | kontrola importu | brak mapping audit |
| `woo_category_tree.csv/json/summary` | istnieje | drzewo kategorii + eBay mapping | `categories` i eBay category readiness | Allegro/Ovoko mapping niepełny |
| `ovoko_donor_cars.csv/summary` | istnieje jako narzędzie | auta dawców z RRR/Ovoko | `donor_cars` | trzeba dołączyć manual overrides i source diagnostics |

## 12. Stock/price/status/publish

Stock bazowy pochodzi z Woo `_stock`, `_stock_status`, `_manage_stock`. Price bazowy z `_regular_price`, `_sale_price`, `_price`. Status publikacji z `wp_posts.post_status` oraz dodatkowych readiness/block flags.

Procesy zmieniające:

- ręczna edycja Woo;
- Gmail/Ovoko live update: tytuł, cena, kategoria, status publish/draft, ale zachowuje obrazy;
- eBay stock sync/order polling: `get_orders` wykrywa sprzedaż i zmniejsza stock Woo; stock zero może kończyć eBay listing;
- Allegro import/sync: importer i gearboxes cron mogą tworzyć/aktualizować produkty oraz offer status;
- eBay content/price publish tools mogą aktualizować listing/inventory/offer.

Logi: `EBAY_ORDER_SALE_DETECTED` oznacza realną sprzedaż eBay; `WEI_STOCK_SYNC_EBAY_API_CALL` oznacza próbę API; `EBAY_SYNC_PRODUCT_QUEUED` oznacza lokalną kolejkę; `EndFixedPriceItem`/`end_fixed_price_item_by_listing_id` oznacza kończenie oferty; błędy API są logowane przez logger pluginów z kontekstem `offer_id`, `listing_id`, `product_id`.

## 13. Cron/jobs/batch tools/logi

- WP Cron pluginów eBay: auto sync scheduler, queue runner, stock sync/order polling, category readiness audits.
- `wp_wei_ebay_sync_queue`: custom queue z attempts/error/source.
- eBay order polling: `get_orders` i importer order line items, rozwiązuje po SKU, offer ID, item/listing ID.
- Ovoko admin AJAX: donor cars export autorun, dictionary cache builder, Gmail preview/update/batch, category rebuild/dry-run tools.
- Allegro cron: offer list import, offer events, order events; gearboxes cron jawnie nie robi full import automatycznie bez manual reconcile.
- Nonces/confirmation: admin actions używają nonce; destructive/live batch wymaga confirmation stringów.

## 14. Dane wymagane przez Laravel

### Produkty

`woo_product_id`, `sku`, `title`, `slug`, `description`, `short_description`, `price`, `currency`, `stock`, `stock_status`, `status`, `category_ids/path`, images, attributes, part/OEM/manufacturer/MPN/EAN, raw product meta.

### eBay

`marketplace=EBAY_DE/EBAY_FR`, account, `inventory_sku`, `offer_id`, `listing_id/item_id`, category ID, listing/offer status, URL, price, quantity, last sync, fitment KType payload, raw read payload/log mapping.

### Ovoko

`ovoko_part_id/rrr_part_id`, donor car ID, vehicle snapshot, Ovoko category, title/name, part number/OEM, price, status, quantity, images, raw payload, readiness/block reasons.

### Allegro

`allegro_offer_id`, `allegro_external_id` jeśli z API/CSV, `allegro_signature` jeśli z API/CSV, Allegro SKU, auction ID/offer ID, URL, category, status, stock/price, raw/legacy payload.

## 15. Proponowany pakiet eksportów technicznych

| Plik | Źródło | Klucz importu | Krytyczne kolumny | Status/kolejność |
|---|---|---|---|---|
| `woo_products.csv` | `wp_posts`, `wp_postmeta`, WC API | `woo_product_id` | SKU/title/slug/price/stock/status | istnieje jako `products.csv`; utrzymać |
| `woo_product_meta.csv` | `wp_postmeta` | `woo_product_id+meta_key` | pełny raw meta | istnieje; utrzymać |
| `woo_product_images.csv` | attachments/meta | `woo_product_id+position` | URL/attachment/source | istnieje |
| `woo_product_categories.csv` | term rels | `woo_product_id+term_id` | path/slug | istnieje |
| `woo_product_attributes.csv` | WC attributes | `woo_product_id+name` | values/taxonomy | istnieje |
| `woo_category_tree.csv` | `product_cat`, eBay mappings | `term_id` | hierarchy + eBay DE/FR mapping | istnieje |
| `woo_marketplace_mapping_products.csv` | union mappings/meta | `woo_product_id+marketplace` | all marketplace IDs/status/source | dopisać jako 1. priorytet |
| `woo_ebay_listings.csv` | `marketplace_mappings`, `_wei*`, optional read-only API export | `woo_product_id+marketplace` | listing/offer/inventory/status | dopisać jako 1. priorytet |
| `woo_ebay_fitment.csv` | fitment scanner/audit | `woo_product_id+marketplace` | KType/payload/block reason | dopisać jako etap 2 |
| `woo_ovoko_mapping.csv` | Ovoko/Gmail meta + donor export | `woo_product_id` / `ovoko_part_id` | part ID, donor, readiness | dopisać jako 1. priorytet |
| `woo_allegro_legacy_mapping.csv` | Allegro meta + API/CSV | `woo_product_id+offer_id` | offer/external/signature/status | dopisać po aktualnym eksporcie Allegro |
| `ovoko_donor_cars.csv` | RRR/Ovoko read-only export | `ovoko_car_id` | vehicle snapshot | narzędzie istnieje; wygenerować |
| `export_manifest.json` | exporter | export id/checksums/schema versions | pliki, row counts, warnings | dopisać |

## 16. Rekomendowana architektura Laravel

Minimalne tabele:

- `parts`: `id`, `woo_product_id`, `sku`, title/description/price/stock/status, raw Woo payload.
- `categories`, `category_part`.
- `part_images`, `part_attributes`.
- `donor_cars`: `ovoko_car_id`, raw and normalized vehicle snapshot.
- `marketplace_accounts`: eBay DE, eBay FR, Ovoko/RRR, Allegro main, Allegro gearboxes.
- `marketplace_listings`: `part_id`, account, marketplace, inventory SKU, offer ID, listing/item ID, external ID/signature, category ID, status, price, quantity, URL, raw_payload, legacy_payload, source.
- `marketplace_sync_logs`: event/API/audit logs.
- później `marketplace_orders`, `marketplace_order_items`.

Mapowanie:

- Woo product ID → Laravel part: najpewniejszy klucz, jeśli import parts zachował `woo_product_id`.
- SKU → part/listing: dobry fallback, ale wymaga unikalności i historii zmian.
- Ovoko part ID → part: bardzo silny identyfikator kanału Ovoko.
- eBay offer/listing/inventory SKU → `marketplace_listings`, nie bezpośrednio do `parts` bez `woo_product_id`.
- Allegro offer ID/external/signature → `marketplace_listings`; jeśli brak `external_id/signature`, używać aktualnego API/CSV.

Normalizować: IDs marketplace, status, price, quantity, category IDs, inventory SKU, donor car ID. W raw/legacy payload zostawić pełne meta `_gps_*`, `_allegro_parameters`, API payloady, readiness diagnostics i stare importer payloads.

## 17. Ryzyka

- Brak cen w Gmail/Ovoko (`missing_price`) blokuje publiczną sprzedaż.
- Brak category mapping blokuje eBay/Ovoko publication.
- Allegro signature/external ID niepotwierdzone w lokalnym kodzie; bez aktualnego eksportu Allegro matching jest niepewny.
- SKU mogą być różne między Woo, eBay DE, eBay FR i Allegro; nie matchować po tytule automatycznie.
- DE/FR to osobne listingi tego samego produktu; uniknąć scalenia do jednego rekordu.
- Duplikaty listingów możliwe, jeśli Laravel opublikuje bez importu istniejących `offer_id/listing_id/inventory_sku`.
- Niepełny fitment/KType nie powinien blokować przejęcia stock/order sync, ale powinien blokować masowe odświeżanie compatibility.
- Stock drift przy wielu kanałach naraz; Laravel musi mieć centralny stock ledger lub transakcyjne rezerwacje.
- Różne waluty/VAT/shipping policies między DE/FR/Allegro/Ovoko.
- Legacy payloady mogą być nieaktualne; status aktywny potwierdzać read-only API/CSV.

## 18. Konkretne następne taski

1. Na kopii produkcyjnej DB wykonać audyt meta keys z count/sample i zapisać jako `woo_product_meta_audit.csv`.
2. Wyeksportować `wp_marketplace_mappings`, `wp_wei_ebay_category_mappings`, `wp_wei_ebay_sync_queue` i odpowiedniki FR, jeśli tabele mają inny prefix/namespace.
3. Dopisać lub wykonać read-only `woo_ebay_listings.csv` z DE/FR union z tabeli mappings i post meta.
4. Wygenerować donor cars export Ovoko oraz dołączyć manual override CSV, jeśli istnieje na produkcji.
5. Wygenerować `woo_ovoko_mapping.csv` z `_ovoko_part_id`, donor car, readiness/block reasons.
6. Pobrać aktualny eksport Allegro ofert z API/CSV i połączyć z Woo po `_allegro_offer_id`/`_secondary_allegro_offer_id`, a dopiero potem ocenić external ID/signature.
7. W Laravel zaimportować marketplace listings w trybie read-only i ustawić blokadę publikacji, dopóki listing mapping nie jest kompletny.
8. Dopiero po walidacji mappingów wdrażać stock sync/order import; content publish i fitment update jako późniejszy etap.

## Załącznik A — przeanalizowane pliki/katalogi

- `wp-content/plugins/gps-ebay-fitment-sync/`
- `wp-content/plugins/woo-ebay-integration/`
- `wp-content/plugins/woo-ebay-integration-fr/`
- `wp-content/plugins/gpswiss-ovoko-integration/`
- `wp-content/plugins/gps-gmail-product-importer/`
- `wp-content/plugins/allegro-woo-importer/`
- `wp-content/plugins/gpswiss-allegro-gearboxes/`
- `wp-content/plugins/gp-partscentrum-connector/`
- `DEPLOYMENT.md`
- motyw Woo templates używające listing image meta.

## Załącznik B — przeanalizowane tabele / custom tables

Potwierdzone w migracjach kodu:

- `wp_marketplace_mappings`
- `wp_wei_ebay_category_mappings`
- `wp_wei_ebay_category_teaching_rules`
- `wp_wei_ebay_category_tree_cache`
- `wp_wei_ebay_sync_queue`

Standardowe WordPress/Woo:

- `wp_posts`
- `wp_postmeta`
- `wp_terms`
- `wp_term_taxonomy`
- `wp_term_relationships`
- `wp_termmeta`
- Action Scheduler tables, jeśli Woo Action Scheduler jest aktywny na produkcji.

## Załącznik C — wykryte meta keys / grupy

- Woo core: `_sku`, `_price`, `_regular_price`, `_sale_price`, `_stock`, `_stock_status`, `_manage_stock`, `_thumbnail_id`, `_product_image_gallery`.
- Parts/OEM: `part_number`, `_part_number`, `oem_number`, `_oem_number`, `manufacturer_code`, `_manufacturer_code`, `_mpn`, `_ean`.
- eBay DE/FR: `_wei_ebay_listing_id`, `_wei_ebay_offer_id`, `_wei_ebay_inventory_item_id`, `_wei_ebay_sku`, `_wei_ebay_marketplace`, `_wei_fr_ebay_listing_id`, `_wei_fr_ebay_offer_id`, `_wei_fr_ebay_inventory_item_id`, `_wei_fr_ebay_sku`, `_wei_fr_ebay_marketplace`.
- Ovoko/Gmail: `_ovoko_part_id`, `ovoko_part_id`, `_gps_gmail_*`, `_gps_ovoko_*`, `_gps_marketplace_readiness_status`, `_gps_marketplace_blocking_reasons`.
- Allegro: `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_category_id`, `_allegro_currency`, `_allegro_status`, `_allegro_parameters`, `_allegro_imported_at`, `_secondary_allegro_offer_id`, `_secondary_allegro_account`, `_imported_from_secondary_allegro`.
- Channel/source: `_source_marketplace`, `_allegro_export_blocked`, `_ebay_export_allowed`, `_wei_ebay_export_blocked`, `_wei_ebay_export_status`, `_channel_allegro_main_enabled`, `_channel_allegro_gearboxes_enabled`, `_channel_ebay_de_enabled`.

## Załącznik D — czego nie udało się potwierdzić bez DB/API

- Liczby produktów per meta key i realne przykładowe wartości z produkcji.
- Rzeczywista unikalność SKU i skala `GPS-GMAIL-*`.
- Czy istnieją produkcyjne meta `allegro_external_id`/`allegro_signature` poza lokalnym kodem.
- Aktualny status listingów eBay/Allegro/Ovoko względem legacy meta.
- Pełna lista tabel Action Scheduler i logów na produkcji.
- Lokalizacja ręcznych override donor cars na produkcji, jeśli nie jest w repo.

## Załącznik E — wystarczalność danych

- eBay: dane są zasadniczo wystarczające do powiązania, jeśli `wp_marketplace_mappings` zostanie wyeksportowane i potwierdzone read-only API/audytem.
- Ovoko: dane są wystarczające, jeśli produkty mają `_ovoko_part_id`/`ovoko_part_id` i donor cars export zostanie dołączony.
- Allegro: dane są częściowo wystarczające po `offer_id`; do pewnego przejęcia aktywnych ofert wymagany jest aktualny eksport Allegro API/CSV z external ID/signature/status.

## Załącznik F — eksporty do dodania

1. `woo_marketplace_mapping_products.csv`
2. `woo_ebay_listings.csv`
3. `woo_ovoko_mapping.csv`
4. `woo_allegro_legacy_mapping.csv`
5. `woo_ebay_fitment.csv`
6. `export_manifest.json`
