# Analiza i plan mapowania produktów sklepu z ofertami Allegro, Ovoko i eBay

## Summary

Celem pierwszego etapu nie powinno być uruchamianie pełnej integracji sprzedażowej, tylko zbudowanie warstwy diagnostyczno-mapującej między lokalnym katalogiem produktów a aktualnymi listingami marketplace. Obecny kod jest oparty o WordPress/WooCommerce, więc „produkty sklepu” są dziś produktami WooCommerce (`product`) z danymi przechowywanymi w `wp_posts`, `wp_postmeta`, taksonomiach WooCommerce i tabelach zamówień WooCommerce. W repozytorium są też ślady kilku wcześniejszych lub równoległych integracji: Allegro importer, Ovoko/RRR integration i eBay integration.

Najważniejszy wniosek: mamy użyteczne identyfikatory i metadane do mapowania, ale są rozproszone w meta polach WooCommerce, a nie w jednej kanonicznej tabeli `parts`. Dlatego przed właściwym mapowaniem należy wykonać audyt danych produkcyjnych: kolumn/tabel, meta keys, liczności, próbki payloadów i konflikty. Rekomendowanym pierwszym krokiem technicznym jest endpoint diagnostyczny `GET /tools/check-marketplace-mapping-readiness?token=gps_images_import_2026`, który zbierze fakty z bazy produkcyjnej bez zmieniania danych.

Aktualny sklep ma działający katalog `/czesci`, szybkie wyszukiwanie po numerze części, koszyk/checkout oraz zaczątki ekranów zamówień marketplace w adminie. Zamówienia marketplace powinny finalnie trafiać do lokalnych zamówień ze źródłem kanału `allegro`, `ovoko` lub `ebay`, analogicznie do obecnego `storefront` dla sklepu.

## Jakie dane mamy w obecnym katalogu produktów

### Model danych w repozytorium

W repozytorium nie widać klasycznego modelu/tabeli aplikacyjnej `parts` w stylu Laravel ani migracji tworzącej `parts`. Widoczne są natomiast produkty WooCommerce i meta pola:

- produkt lokalny = WooCommerce `product`,
- numer części = meta `_part_number`,
- SKU = standardowe WooCommerce `_sku`, ustawiane przez API produktu,
- cena/stan = standardowe pola i meta WooCommerce,
- kategorie = `product_cat`,
- obrazy = featured image + galeria WooCommerce,
- listing image = dodatkowe meta `_awi_listing_image_id` i powiązane meta generacji,
- stare identyfikatory Allegro/Ovoko/eBay = meta keys, część jawnie obsługiwana w pluginach.

Frontend pobiera numer części z meta `_part_number` i traktuje brak jako `Brak`, co potwierdza, że `_part_number` jest obecnym lokalnym polem mapującym. Kod wyszukiwania po numerze części również operuje na `_part_number` przez `wp_postmeta`.

### Pola/metadane bezpośrednio przydatne do mapowania

| Obszar | Obecne pole/meta | Przydatność |
|---|---|---|
| Numer części | `_part_number` | bardzo wysoka; wspólne pole do porównywania z `part_number`, OEM, SKU/signature i tytułem oferty |
| SKU | Woo `_sku` | bardzo wysoka; dla Allegro signature/SKU, eBay custom label/SKU, Ovoko part code |
| Allegro offer ID | `_allegro_offer_id` | match pewny, jeśli oferta nadal istnieje |
| Allegro URL | `_allegro_offer_url` | match pomocniczy i link diagnostyczny |
| Allegro status/category/currency/parameters | `_allegro_status`, `_allegro_category_id`, `_allegro_currency`, `_allegro_parameters` | match pomocniczy oraz analiza starych importów |
| Ovoko part ID | `_ovoko_part_id` | match pewny dla Ovoko/RRR |
| Ovoko raw/source/category | `_ovoko_raw_payload`, `_ovoko_source_id`, `_ovoko_category` | match pewny/pomocniczy, jeśli dane są zachowane |
| Manufacturer/OEM/MPN | `_manufacturer_code`, `_ovoko_manufacturer_code`, `_mpn`, `mpn`, `_gpswiss_part_number` | match bardzo prawdopodobny, dobry do scoringu |
| Zdjęcia | `_thumbnail_id`, galeria, `_awi_listing_image_id`, meta attachmentów | match pomocniczy; szczególnie jeśli nazwy plików/URL/metadane zawierają import source lub offer id |
| Kategorie | `product_cat`, meta kategorii Ovoko/Allegro/eBay | match pomocniczy i walidacja konfliktów |

### Co trzeba sprawdzić w bazie produkcyjnej

Na podstawie samego repozytorium nie da się wiarygodnie policzyć:

- kolumn i indeksów tabeli `parts`, jeśli taka tabela istnieje tylko w produkcji,
- liczby produktów z `source_system = allegro/ovoko/ebay`,
- liczby produktów z `external_id`,
- liczby produktów z `sku`,
- liczby produktów z `legacy_payload`,
- próbek `legacy_payload`,
- liczby produktów z `_allegro_offer_id`, `_ovoko_part_id`, eBay listing/offer meta,
- czy attachmenty/obrazy mają stare URL-e marketplace.

Wariant diagnostyczny powinien sprawdzić zarówno ewentualną tabelę `parts`, jak i WooCommerce `wp_posts/wp_postmeta`, ponieważ repozytorium wskazuje, że realny katalog działa dziś na WooCommerce.

## Czy są ślady starych importów marketplace

### Allegro

Tak. W repozytorium istnieje plugin `allegro-woo-importer`, a dokumentacja wdrożeniowa opisuje import Allegro, OAuth, tryb synchronizacji, harmonogram, mapowanie oferty na produkt, zdjęcia, cenę, SKU, `_allegro_offer_id` i `_allegro_offer_url`.

Plugin mapuje ofertę Allegro do produktu WooCommerce przez `upsert_product()`. W trakcie mapowania pobiera `offer['id']`, szuka istniejącego produktu po `_allegro_offer_id`, ustawia nazwę, opis, cenę, SKU, status, stan, kategorie, atrybuty i zdjęcia, a następnie zapisuje `_part_number`, `_allegro_offer_id`, URL, kategorię, status, walutę i parametry.

To oznacza, że jeżeli wcześniejszy import działał na produkcji, wiele produktów może nadal mieć trwałe Allegro ID w meta `_allegro_offer_id` oraz dodatkowe dane `_allegro_parameters`, `_allegro_offer_url`, `_allegro_category_id`.

### Ovoko

Tak. Istnieje plugin `gpswiss-ovoko-integration`, który pracuje z RRR/Ovoko i zapisuje metadane produktów. README mówi, że tworzenie szkicu Woo z części RRR zapisuje `manufacturer_code -> _part_number` oraz meta `_mpn`, `mpn`, `_manufacturer_code`, `_gpswiss_part_number`, `_ovoko_manufacturer_code`, a także importuje zdjęcia.

Kod synchronizacji Ovoko zakłada lookup produktu po `_ovoko_part_id`. W design summary jawnie wskazuje, że synchronizacja zamówień/stanów Ovoko ma używać `_ovoko_part_id only` jako lookupu lokalnego produktu. Kod zawiera też listę kandydatów ID z payloadów Ovoko/RRR: `_ovoko_part_id`, `part_id`, `item_id`, `product_id`, `external_id`, `rrr_id` itd.

To oznacza, że Ovoko może mieć najlepszą sytuację mapowania, jeśli `_ovoko_part_id` jest zachowane i aktualne.

### eBay

Tak. Istnieje plugin `woo-ebay-integration`, który ma własne migracje, repozytoria mapowań, synchronizację, import zamówień, synchronizację stanów i panel admina. Tabela `marketplace_mappings` w tym pluginie przechowuje `marketplace`, `woo_product_id`, `sku`, `remote_inventory_id`, `remote_offer_id`, `remote_listing_id`, `marketplace_id`, status i indeksy po SKU/offer/listing.

To jest istotny ślad architektury, ale obecna tabela wygląda bardziej jak techniczna tabela aktywnej integracji eBay niż neutralna tabela „aktualnych listingów marketplace do zmapowania”. Można ją wykorzystać jako inspirację, ale dla wspólnej architektury Allegro/Ovoko/eBay lepiej wprowadzić bardziej neutralną tabelę listingów lub rozszerzyć istniejącą koncepcję świadomie.

## Które pola nadają się do mapowania

### Match pewny

1. `parts.external_id == external_offer_id`, jeśli tabela `parts` istnieje i pole jest wypełnione.
2. `source_system + external_id`, jeśli `source_system` ma wartości `allegro`, `ovoko`, `ebay`.
3. `_allegro_offer_id == Allegro offer id`.
4. `_ovoko_part_id == Ovoko/RRR part/listing/item id`.
5. `marketplace_mappings.remote_offer_id` lub `remote_listing_id == eBay offer/listing id`.
6. Woo `_sku == marketplace sku/signature/custom label`, pod warunkiem unikalności SKU.
7. `legacy_payload` albo `_ovoko_raw_payload` zawiera aktualne/stare ID oferty/listingu.

Rekomendacja: match pewny można automatycznie zapisać jako `auto_matched`, jeśli nie ma konfliktu jeden-do-wielu ani wiele-do-jednego. Ryzyko jest niskie, ale nadal warto pokazać w adminie powód i umożliwić odłączenie.

### Match bardzo prawdopodobny

1. `_part_number` / `_mpn` / `manufacturer_code` / `oem_number` występuje w SKU, signature, custom label lub tytule oferty.
2. Nazwa produktu i tytuł oferty są bardzo podobne po normalizacji.
3. Cena marketplace jest bliska cenie lokalnej.
4. Stan lokalny i stan marketplace są zgodne lub sensownie podobne.
5. Dane pojazdu z tytułu/meta/vehicle snapshot pasują do opisu oferty.
6. Główne zdjęcie lub listing image ma metadane wskazujące ten sam import albo tę samą galerię.

Rekomendacja: automatycznie proponować jako `unmatched` z kandydatem i `match_confidence` 70–89 albo oznaczać jako `auto_matched` dopiero przy bardzo wysokim progu, np. >= 90, unikalnym kandydacie i braku konfliktu ceny/stanu. W praktyce warto wymagać zatwierdzenia w adminie dla pierwszego batcha.

### Match niepewny

1. Tylko podobny tytuł.
2. Tylko podobna cena.
3. Brak SKU/numeru części.
4. Kilka produktów pasuje do jednej oferty.
5. Jedna oferta pasuje do kilku produktów.
6. Dane pojazdu są niepełne albo sprzeczne.

Rekomendacja: zawsze ręczna akceptacja. Status `conflict` przy wielu kandydatach o podobnym confidence albo naruszeniu unikalności.

## Czego brakuje

1. Jednej kanonicznej tabeli listingów Allegro/Ovoko/eBay z raw payloadem, statusem mapowania i confidence.
2. Jednego neutralnego modelu identyfikatorów marketplace: `marketplace`, `external_offer_id`, `sku`, `url`, `status`, `raw_payload`.
3. Raportu liczności realnych danych produkcyjnych: ile produktów ma `_allegro_offer_id`, `_ovoko_part_id`, `_sku`, `_part_number`, eBay mappingi, raw payloady.
4. Mechanizmu konfliktów i ręcznego zatwierdzania mapowań.
5. Strategii idempotentnego importu aktualnych listingów z API/CSV/XLS.
6. Zasady, czy istniejąca tabela `marketplace_mappings` eBay ma zostać migrowana, zostawiona jako eBay-only, czy zastąpiona neutralną tabelą.

## Różnice i specyfika Allegro

Allegro ma najbardziej bezpośredni ślad historyczny w postaci `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_category_id`, `_allegro_status`, `_allegro_parameters`. Aktualne listingi Allegro powinny być pobierane z API lub eksportu z polami:

- `offer id`,
- `external id`, jeśli wykorzystywany,
- `signature` / SKU,
- `title`,
- `price`,
- `stock quantity`,
- `status`,
- `category`,
- `product id`,
- `url`,
- `raw_payload`.

Najlepsze dopasowania: `_allegro_offer_id`, następnie SKU/signature, następnie `_part_number`/MPN w tytule.

## Różnice i specyfika Ovoko

Ovoko/RRR ma silną tożsamość części przez `_ovoko_part_id` i pola z payloadu części. Aktualne listingi powinny mieć:

- item/listing/part id,
- external id,
- SKU / part code,
- part number / OEM / manufacturer code,
- title,
- price,
- quantity/status,
- vehicle data,
- URL,
- raw payload.

Najlepsze dopasowania: `_ovoko_part_id`, `manufacturer_code -> _part_number`, `_ovoko_manufacturer_code`, `_mpn`, vehicle data i zdjęcia. Warto zachować szczególną ostrożność przy `external_id`, bo dokumentacja/kod sugerują, że idempotencja `external_id` w importPart nie musi być w pełni potwierdzona.

## Różnice i specyfika eBay

eBay ma istniejącą architekturę techniczną z `marketplace_mappings`, gdzie rozdzielone są `remote_inventory_id`, `remote_offer_id`, `remote_listing_id`, `sku` i `marketplace_id`. Aktualne listingi powinny mieć:

- item id / listing id,
- offer id, jeśli dostępny z Inventory API,
- SKU / custom label,
- title,
- price,
- quantity,
- status,
- category,
- URL,
- raw payload.

Najlepsze dopasowania: istniejące `marketplace_mappings.remote_listing_id`/`remote_offer_id`, eBay SKU/custom label, a następnie lokalny `_part_number`/MPN w tytule.

## Proponowana strategia mapowania

### Etap 1: import snapshotu aktualnych listingów

Nie zakładamy gotowego API. Dopuszczalne źródła:

1. API marketplace,
2. eksport CSV/XLS z panelu Allegro/Ovoko/eBay,
3. tymczasowy import pliku z listą ofert,
4. ręczny import diagnostyczny na start.

Każdy rekord normalizujemy do wspólnego formatu:

```json
{
  "marketplace": "allegro|ovoko|ebay",
  "external_offer_id": "...",
  "title": "...",
  "sku": "...",
  "price": "...",
  "quantity": 1,
  "external_id": "...",
  "product_id": "...",
  "category": "...",
  "status": "active|inactive|sold|ended|...",
  "url": "...",
  "raw_payload": {}
}
```

### Etap 2: scoring kandydatów

Dla każdego listingu liczymy kandydatów lokalnych produktów:

- +100: exact marketplace ID (`_allegro_offer_id`, `_ovoko_part_id`, `remote_listing_id`),
- +95: `source_system + external_id`, jeśli potwierdzone,
- +90: unikalny exact SKU,
- +80: exact `_part_number`/MPN w SKU/signature/custom label,
- +70: exact `_part_number`/MPN w tytule,
- +20: bardzo podobny tytuł,
- +10: cena zbliżona,
- +10: zgodność kategorii,
- +10: zgodność danych pojazdu,
- +10: zgodność zdjęcia/metadanych importu,
- -50: wielu kandydatów z takim samym ID/SKU,
- -30: konflikt ceny/stanu powyżej progu,
- -100: listing już przypisany do innego produktu.

### Etap 3: status mapowania

- `auto_matched`: score >= 90, jeden kandydat, brak konfliktów.
- `unmatched`: brak kandydata albo kandydat poniżej progu auto-match.
- `conflict`: kilka kandydatów, naruszona unikalność, sprzeczne trwałe ID.
- `manual_matched`: użytkownik zatwierdził/zmienił produkt w adminie.
- `ignored`: oferta celowo pominięta.

### Etap 4: zatwierdzenie i blokady

- Jeden produkt może mieć wiele listingów marketplace.
- Jedna oferta marketplace nie może być przypisana do wielu produktów.
- Upsert listingów idempotentny po `marketplace + external_offer_id`.
- Przy zmianie `part_id` logować kto/kiedy/co zmienił.
- Przy konflikcie nie wolno automatycznie importować zamówień do `order_items.part_id` bez ręcznej akceptacji.

## Proponowana tabela mapowań/listingów

Rekomendowana nazwa: `marketplace_listings` albo `part_marketplace_listings`.

Minimalny schemat:

```sql
CREATE TABLE marketplace_listings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  marketplace VARCHAR(32) NOT NULL,
  part_id BIGINT UNSIGNED NULL,
  external_offer_id VARCHAR(191) NOT NULL,
  sku VARCHAR(191) NULL,
  title TEXT NOT NULL,
  price DECIMAL(12,2) NULL,
  quantity INT NULL,
  status VARCHAR(64) NULL,
  url TEXT NULL,
  raw_payload JSON NULL,
  match_status VARCHAR(32) NOT NULL DEFAULT 'unmatched',
  match_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
  match_reason TEXT NULL,
  last_synced_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_marketplace_offer (marketplace, external_offer_id),
  KEY idx_marketplace_status (marketplace, match_status),
  KEY idx_part (part_id),
  KEY idx_sku (marketplace, sku)
);
```

Jeżeli zostajemy w WooCommerce, `part_id` powinno wskazywać `wp_posts.ID` produktu i można nazwać je `woo_product_id`. Jeżeli docelowo istnieje osobna tabela `parts`, wtedy `part_id` powinno wskazywać `parts.id`, a relację z WooCommerce trzymać osobno.

Tabele opcjonalne na później:

- `marketplace_accounts` — konta/autoryzacje, gdy będzie więcej kont lub regionów,
- `marketplace_sync_logs` — audyt importów snapshotów, błędów i statystyk,
- `marketplace_orders` — bufor surowych zamówień marketplace przed utworzeniem lokalnego `orders`,
- `marketplace_listing_match_candidates` — jeżeli admin ma widzieć wiele kandydatów z detalami scoringu.

Na start nie tworzyć zbyt rozbudowanej architektury. Wystarczy `marketplace_listings` + log importu diagnostycznego.

## Proponowany admin UI

Ekran: **Marketplace → Mapowanie ofert**.

Kolumny:

- marketplace,
- external offer/item/listing id,
- status oferty,
- status mapowania,
- tytuł oferty,
- proponowany/lokalny produkt,
- confidence,
- powód dopasowania,
- cena marketplace,
- cena lokalna,
- stan marketplace,
- stan lokalny,
- link do lokalnego produktu,
- link do oferty marketplace,
- data ostatniej synchronizacji.

Akcje:

- zatwierdź mapowanie,
- zmień produkt,
- odłącz,
- ignoruj,
- oznacz konflikt,
- odśwież dane oferty,
- pokaż raw payload,
- pokaż kandydatów i powody scoringu.

Filtry:

- marketplace: Allegro/Ovoko/eBay,
- `unmatched`, `conflict`, `auto_matched`, `manual_matched`, `ignored`,
- różnica ceny,
- różnica stanu,
- brak SKU,
- brak lokalnego `_part_number`,
- tylko konflikty jeden-do-wielu.

Wyszukiwarka:

- offer id / item id / listing id,
- SKU/signature/custom label,
- tytuł oferty,
- numer części,
- nazwa produktu,
- lokalny product ID.

## Co umożliwi mapowanie

Po zmapowaniu listingów możliwe będzie:

- importowanie zamówień Allegro/Ovoko/eBay do lokalnych `orders`,
- tworzenie `order_items` z poprawnym `part_id`/`woo_product_id`,
- ustawianie `orders.meta.source = allegro|ovoko|ebay`,
- poprawna analityka kanałów `Sklep`, `Allegro`, `Ovoko`, `eBay`, `Sprzedaż lokalna`,
- dziennik obsługi nowych zamówień marketplace,
- zmniejszanie stanów magazynowych,
- synchronizacja cen i stanów,
- wykrywanie konfliktów stanów między sklepem i marketplace,
- późniejsze automatyczne wystawianie/aktualizacja ofert.

## Ryzyka

1. **Brak jednej tabeli `parts` w repozytorium** — trzeba potwierdzić produkcyjną strukturę danych. Jeśli sklep działa jako WooCommerce, raport powinien używać `wp_posts/wp_postmeta` jako źródła prawdy.
2. **Stare ID mogą wskazywać zakończone oferty** — `_allegro_offer_id` lub eBay listing id może być historyczne, więc match pewny oznacza pewne pochodzenie, ale nie zawsze aktualną ofertę.
3. **SKU może nie być unikalne** — przed auto-matchem trzeba sprawdzić duplikaty.
4. **Numery części bywają w tytułach w wielu formatach** — potrzebna normalizacja: usuwanie spacji, myślników, wielkości liter, prefiksów OEM/OE/MPN.
5. **Jeden produkt może mieć wiele listingów** — poprawne, ale jedna oferta nie może być przypisana do wielu produktów.
6. **Zdjęcia jako sygnał są słabe bez metadanych** — porównanie zdjęć powinno być pomocnicze, nie główne.
7. **Równoległe stare pluginy** — Allegro, Ovoko i eBay mają własne mechanizmy; nowa warstwa musi unikać dublowania side effectów, publikacji i synchronizacji podczas diagnostyki.

## Rekomendowany pierwszy krok wdrożenia

Najpierw zaimplementować endpoint diagnostyczny tylko do odczytu:

`GET /tools/check-marketplace-mapping-readiness?token=gps_images_import_2026`

Endpoint powinien zwrócić JSON:

- wykryty tryb katalogu: `woocommerce`, `parts_table`, `hybrid`,
- kolumny `parts`, jeśli tabela istnieje,
- indeksy `parts`, jeśli tabela istnieje,
- liczba produktów po `source_system`, jeśli pole istnieje,
- liczba produktów z `external_id`,
- liczba produktów z SKU,
- liczba produktów z `_part_number`,
- liczba produktów z `legacy_payload`/`_ovoko_raw_payload`,
- próbki payloadów po anonimizacji dużych pól,
- liczba produktów z `_allegro_offer_id`, `_allegro_offer_url`, `_allegro_parameters`,
- liczba produktów z `_ovoko_part_id`, `_ovoko_raw_payload`, `_ovoko_manufacturer_code`,
- liczba rekordów w `marketplace_mappings` eBay i liczności `remote_offer_id`/`remote_listing_id`,
- wykryte stare pola Allegro/Ovoko/eBay,
- istniejące klasy/komendy/importery marketplace,
- potencjalne pola mapujące,
- duplikaty SKU, duplikaty `_part_number`, duplikaty marketplace ID,
- rekomendowany poziom gotowości: `low`, `medium`, `high`, z powodami.

Endpoint nie powinien niczego synchronizować, publikować ani zmieniać. Tylko SELECT/metadata inspection.

## Konkretne następne zadania dla implementacji

1. Dodać endpoint diagnostyczny read-only i zabezpieczyć tokenem.
2. Uruchomić endpoint na produkcji i zapisać wynik jako baseline mapowania.
3. Przygotować tymczasowy importer CSV/XLS listingów do tabeli `marketplace_listings` lub do pliku diagnostycznego, bez zmian produktów.
4. Zdefiniować normalizatory: marketplace, ID, SKU, numer części, cena, status, URL.
5. Zaimplementować scorer kandydatów bez zapisu automatycznego mapowania.
6. Wygenerować raport kandydatów: auto pewne, bardzo prawdopodobne, konflikty, brak dopasowania.
7. Dopiero po akceptacji raportu dodać tabelę `marketplace_listings` i admin UI.
8. Po mapowaniu dodać import zamówień marketplace do lokalnych zamówień i ustawiać `meta.source` według kanału.
9. Następnie dodać synchronizację stanów i cen z blokadami konfliktów.

