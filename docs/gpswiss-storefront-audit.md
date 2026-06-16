# GPSwiss.pl storefront audit / specyfikacja migracji WooCommerce → Laravel

Data audytu: 2026-06-16. Źródła: publiczne indeksy strony `https://gpswiss.pl`, aktualne pliki motywu WooCommerce w repozytorium oraz statyczna analiza CSS/PHP. Ograniczenie: bez uruchomionej bazy WordPress/WooCommerce i bez pewnego dostępu do pełnego checkoutu nie da się potwierdzić wszystkich aktywnych metod płatności, dostawy, stanów magazynowych i pełnego drzewa kategorii; takie elementy oznaczono jako **do potwierdzenia**.

## 1. Executive summary

Obecny sklep jest marketplace'owym storefrontem WooCommerce dla używanych części samochodowych GP Swiss. Najważniejsze elementy do odtworzenia w Laravel MVP:

- katalog produktów z układem kart 3 kolumny na desktopie w archiwach i karuzele 4 kart na stronie głównej;
- wyszukiwanie po nazwie produktu i numerze części oraz osobny tryb wyszukiwania po modelu pojazdu w kategoriach;
- rozbudowane menu kategorii z mega-menu desktop i akordeonem mobile;
- listing z sidebar filtrowania: marka, kategoria, podkategorie, cena;
- karta produktu z galerią WooCommerce, numerem części, stanem, ceną brutto, dostawą, płatnościami, zwrotami, CTA koszyka i linkiem kontaktowym;
- mini-cart w headerze, koszyk WooCommerce, checkout WooCommerce i konto klienta;
- zachowanie SEO URL-i WooCommerce przez przekierowania 301 lub kompatybilne route'y Laravel.

## 2. Design system

### Styl wizualny

- Styl: jasny, prosty marketplace części samochodowych, dużo białych powierzchni, granat jako kolor zaufania/CTA, czerwony jako promocja/alert.
- Layout: maksymalna szerokość kontenera `1320px`, padding boczny `12px`, sekcje oparte o CSS Grid/Flex.
- Karty: białe, radius zwykle `10-12px`, lekkie cienie, subtelne obramowania `#e1e6ef` / `#e5e9f1`.
- Ikony: emoji w headerze i akcjach (`👤`, `🛒`, `📞`, lupa) oraz proste SVG w kaflach kategorii.
- Hover: bardzo subtelny; linki podkreślają się, kafle kategorii delikatnie unoszą się `translateY(-1px)`, produkty zachowują praktycznie ten sam cień.

### Kolory

| Rola | HEX / wartość | Uwagi |
|---|---:|---|
| Tło globalne | `#ffffff` | body |
| Alternatywne tło | `#f5f5f5`, `#f8faff`, `#f4f6fb` | sekcje, inputy, panele |
| Tekst główny | `#1f1f1f`, `rgb(0,0,0)` | linki, produkty |
| Tekst drugorzędny | `#6a6a6a`, `#6b7280`, `#51596b` | opisy, notatki |
| Główny / brand | `#122a66` | granat CTA, nagłówki paneli |
| Czerwony akcent | `#e10613`, `#d82a2a`, `#dc2626` | pasek promocji, sale |
| Przyciski główne | `#122a66` + biały tekst albo jasny `#e7f0ff` + `#0e2a63` | zależnie od kontekstu |
| Hover jasnych CTA | `#f2f5fb`, border `#bbc7dd` | link „Pokaż wszystkie” |
| Komunikat sukcesu | tło `#eaf8ec`, border `#b9e3c0`, tekst `#1f6c33`; także `#ecfdf3` / `#027a48` | formularze/statusy |
| Komunikat błędu | tło `#fff0f0`, border `#f1c2c2`, tekst `#9e2929`; także `#fef3f2` / `#b42318` | formularze/statusy |
| Cena | `rgb(0,0,0)`; sale `#dc2626` | ceny produktów |
| Dostawa | `rgb(38,134,29)` | „Darmowa dostawa” |
| Promocja | `#e10613`, `#c81f35`, `#ff2c2c` | top bar, badge, hero |

## 3. Typography

- Font globalny: `Poppins`, wymuszany dla `body`, WooCommerce, linków, formularzy i nagłówków. Źródło ładowania fontu: **do potwierdzenia** w `wp_enqueue_style` / konfiguracji WordPress; CSS zakłada dostępność `Poppins`.
- Body: `14px`, line-height `1.45`.
- Tytuły sekcji: najczęściej `22px`; treści SEO H2 `24px`; hero promocyjne `68px` desktop.
- Karta produktu listing:
  - numer części: `12px`, line-height `18px`, label normal, wartość bold `700`;
  - tytuł produktu: `14px`, weight `400`, line-height `21px`;
  - cena: `16px`, weight `700`, line-height `24px`;
  - dostawa: `14px`, weight `700`, line-height `21px`;
  - notatka dostawy: `12px`, line-height `18px`.
- Ceny: format WooCommerce PL, np. `3 699,00 zł`, brutto na PDP.
- SKU/numer części: frontend używa etykiety „Numer części” i meta `_part_number`, nie klasycznego widoku `SKU:`.

## 4. Header

### Desktop

Header składa się z:

1. Górny pasek promocyjny: czerwone tło, tekst „Wybrane części do -10%!”, przycisk zamknięcia `×`.
2. Top links: `Kontakt`, selektor języka (`Polski`, `Angielski`, `Francuski`, `Ukraiński`, `Niemiecki`), grafika „Rzetelna Firma”.
3. Główny rząd:
   - logo `assets/images/gp-logo-main.jpg`, link do `/`;
   - wyszukiwarka sklepu z placeholderem „Wyszukiwanie według nazwy części, numeru części, kategorii, modelu samochodu...”, `GET /?s=...&post_type=product`;
   - dropdown profilu „Mój profil” z linkami `Zaloguj się`, `Zarejestruj się`, `Ulubione`, `Historia zamówień` albo po zalogowaniu `Moje konto`, `Moje zamówienia`, `Wyloguj się`;
   - mini-cart „Koszyk” z licznikiem i panelem bocznym.
4. Rząd nawigacji:
   - przycisk `☰ Menu`, rozwijane mega-menu kategorii;
   - skróty: `Silniki`, `Skrzynia biegów`, `Filtry DPF`, `Felgi`, `Fotele`, `Zwrotnice`;
   - telefony: `+48 504 266 984` i `+48 579 152 665`.

Sticky header: brak jednoznacznego sticky w CSS/PHP; **do potwierdzenia** w JS/produkcji.

### Mobile

- Rzędy przechodzą do jednej kolumny poniżej `1199px`.
- Top links są przewijane poziomo poniżej `767px`.
- Wyszukiwarka ma wysokość ok. `50px`, ikona 40px + input + przycisk.
- Mega-menu ma mobilne przyciski `+` / `−` dla poziomów kategorii; `Felgi` i `Fotele` są ukrywane w skrótach mobile.

## 5. Footer

Footer jest minimalistyczny:

| Kolumna | Treść |
|---|---|
| GP GREGOR Swiss | `Kontakt`, `Regulamin`, `Polityka prywatności` |
| Kontakt | `tel. 504 266 984`, `biuro@gpswiss.pl` |

Brak widocznego newslettera, social media, ikon metod płatności/dostawy w footerze. Metody płatności są widoczne na PDP jako obraz `/wp-content/uploads/payments.jpg`.

## 6. Homepage

Aktualny `front-page.php` renderuje: top bar, store header i sekcje produktowe/popularne. W repo istnieją też starsze/dodatkowe template-parts (`banners`, `brand-selector`, `category-mega`, `seo-content`, `repeat-search`), ale nie są obecnie włączone w `front-page.php` — traktować jako możliwe pozostałości lub elementy do potwierdzenia.

### Sekcje aktywne

1. **Promo bar + header** — wejście do wyszukiwania, konta i koszyka.
2. **Hero / baner** — publiczny indeks pokazuje obraz z alt „GP SWISS - największy wybór części używanych w Polsce”, slajder ze strzałkami `‹ ›`; dokładna implementacja aktywnego hero: **do potwierdzenia**, bo w `front-page.php` nie jest bezpośrednio renderowana poza headerem/produktami.
3. **Karuzele produktów**:
   - `Silniki kompletne`;
   - `Skrzynie kompletne`;
   - `Zwrotnice`;
   - `Filtry DPF`.
   Każda sekcja ma link `Pokaż wszystkie`, do 12 produktów, strzałki/doty karuzeli i karty produktów.
4. **Kafelki kategorii** — kod przewiduje 12 kafli: Silniki i osprzęt, Skrzynie biegów i napędy, Felgi i opony, Układ kierowniczy, Układ hamulcowy, Oświetlenie, Zawieszenie, Elektronika, Wnętrze / kokpit, Karoseria, Chłodzenie, Akcesoria.
5. **Nasze marki** — logo BMW, AUDI, Volkswagen, Skoda.
6. **Part-number search box** — publiczny indeks pokazuje pływający box „Numer części / Wyszukiwanie po numerze części”.

### Responsywność homepage

- Karuzele: desktop 4 karty; poniżej `1199px` 2 kolumny; mobile 1 kolumna w siatkach statycznych.
- Marki: desktop 4, tablet/mobile 2.
- Hero marki: desktop 6, tablet 3, mobile 2.

## 7. Categories

### Główne kategorie widoczne publicznie w menu

| Nazwa | Slug / URL | Nadrzędna | Opis | Zdjęcie | Uwagi |
|---|---|---|---|---|---|
| Części karoserii | `/kategoria-produktu/...` | Motoryzacja / do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Filtry | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Ogrzewanie postojowe i chłodnictwo samochodowe | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Opony i felgi | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Oświetlenie | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Pozostałe | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Silniki i osprzęt | `/kategoria-produktu/silnik-i-osprzet/...` | do potwierdzenia | generowany opis na archive hero | do potwierdzenia | skrót homepage/header |
| Układ chłodzenia silnika | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ elektryczny, zapłon | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ hamulcowy | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ kierowniczy | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ klimatyzacji | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ napędowy | `/kategoria-produktu/uklad-napedowy/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | skrót Skrzynia biegów |
| Układ paliwowy | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ wentylacji | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Układ wydechowy | `/kategoria-produktu/uklad-wydechowy-i-inne-elementy/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | skrót Filtry DPF |
| Układ zawieszenia | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Wycieraczki i spryskiwacze | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |
| Wyposażenie i akcesoria samochodowe | `/kategoria-produktu/motoryzacja/wyposazenie-i-akcesoria-samochodowe/...` | Motoryzacja | do potwierdzenia | do potwierdzenia | indeksowana podkategoria: literatura motoryzacyjna |
| Wyposażenie wnętrza | `/kategoria-produktu/...` | do potwierdzenia | do potwierdzenia | do potwierdzenia | widoczna w menu |

### Potwierdzone głębokie URL-e skrótów

- Silniki kompletne: `/kategoria-produktu/silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki/`.
- Automatyczna skrzynia biegów: `/kategoria-produktu/uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow/`.
- Zwrotnica koła przedniego: `/kategoria-produktu/os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego/`.
- Filtr cząstek stałych / katalizator FAP DPF: `/kategoria-produktu/uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf/`.
- Felgi aluminiowe: `/kategoria-produktu/opony-felgi-kolpaki-i-inne-elementy/felgi-aluminiowe/`.
- Fotele: `/kategoria-produktu/wyposazenie-wnetrza-samochodu/komplety-foteli-boczkow-podsufitki-dywanikow/`.

### Strona kategorii

- Na kategorii jest hero z H1 nazwą kategorii i stałym opisem o szerokim wyborze oryginalnych, używanych części.
- W hero jest panel wyszukiwania z tabami `Numer części` / `Model pojazdu`.
- Breadcrumb WooCommerce renderowany pod hero.
- Układ: sidebar + content.

## 8. Search

### Wyszukiwarka globalna

- Położenie: centralnie w headerze.
- Placeholder: „Wyszukiwanie według nazwy części, numeru części, kategorii, modelu samochodu...”.
- Formularz: `GET /`, parametry `s` i `post_type=product`.
- Autocomplete/live search: brak w kodzie; przeładowanie strony.
- Search redirect dla pojedynczego wyniku WooCommerce jest wyłączany, jeśli fraza pasuje do numeru części.

### Wyszukiwanie po numerze części

- Dodatkowy box: „Numer części”, placeholder `np. 8E0 953 521D`, parametr `part_number`.
- Normalizacja usuwa spacje i myślniki oraz używa uppercase; pozwala wyszukiwać częściowe frazy numeru.
- Zapytanie szuka w meta `_part_number` po raw LIKE i normalized LIKE.

### Wyszukiwanie po modelu pojazdu

- Dostępne w hero kategorii jako tryb `search_mode=vehicle_model`.
- Szuka po tytule produktu; tokeny frazy muszą wystąpić w tytule (`AND` po słowach).

### Pola przeszukiwane obecnie

| Pole | Status |
|---|---|
| Nazwa produktu | tak |
| Numer części / SKU biznesowe | tak, `_part_number` |
| SKU WooCommerce | do potwierdzenia; frontend nie eksponuje osobno |
| OEM | pośrednio, jeśli w tytule/opisie/numerze części; dedykowane pole do potwierdzenia |
| Marka/model auta | po tytule i ewentualnie taksonomii marki w filtrze |
| Opis | standardowe Woo search mogło obejmować opis, ale custom `posts_search` skupia się na tytule i `_part_number`; do potwierdzenia |
| Kategoria/tagi/atrybuty | placeholder sugeruje kategorię, ale kod nie potwierdza pełnotekstowego wyszukiwania po taxonomiach |

### Laravel recommendation

- Użyć indeksu `parts_search` / Laravel Scout + Meilisearch lub DB FULLTEXT.
- Minimalny indeks: `name`, `sku`, `part_number`, `oem_number`, `manufacturer_code`, `description`, `short_description`, `category_names`, `car.brand`, `car.model`, `car.generation`, `car.year`, `engine_code`.
- Dla numerów części przechowywać `normalized_part_number` i `normalized_oem_number` bez spacji/myślników, uppercase.
- Tryby: `q` globalne, `part_number`, `vehicle_model`, plus filtry strukturalne.

## 9. Product listing

- Archive template: `main.gp-woo-layout` → breadcrumb → `.gp-shop-grid` z `.gp-shop-sidebar` i `.gp-shop-content`.
- Loop: WooCommerce products, `loop_shop_columns = 3`, `loop_shop_per_page = 60`.
- Toolbar: licznik wyników + standardowe sortowanie WooCommerce.
- Pagination: standard WooCommerce po 60 produktach.
- Układ listing card: biała karta, obraz, numer części, nazwa, cena, dostawa, notatka cutoff; brak przycisku dodania do koszyka na listing card.
- Desktop archiwum: 3 produkty w rzędzie (Woo columns), homepage carousel: 4 widoczne.
- Mobile: siatki przechodzą do 1 kolumny; sidebar/filtry wymagają dopracowania UX jako offcanvas w Laravel.

## 10. Product filters

| Filtr | Typ | AJAX | Licznik | Mobile | Uwagi |
|---|---|---|---|---|---|
| Marka | `select`, taksonomia `gp_car_brand` | nie, submit GET | nie | w sidebarze | tylko jeśli są termy |
| Kategoria | `select` / lista linków | nie | nie | w sidebarze/akordeon | zachowuje aktywne filtry |
| Podkategorie | drzewo linków z „Wyświetl więcej” | nie | nie | akordeon/lista | zależne od aktywnej kategorii |
| Cena | dwa inputy number `price_min`, `price_max` | nie | nie | w sidebarze | submit „Filtruj”, „Wyczyść filtry” |
| Numer części | osobny formularz `part_number` | nie | nie | box / hero | nie jest częścią sidebar filtrów |
| Model auta | search tab w kategorii | nie | nie | taby | szuka po tytule |
| Dostępność/stan/producent/atrybuty | brak widocznego dedykowanego filtra | — | — | — | do potwierdzenia |

## 11. Product card on listing

- Zdjęcie: `wp_get_attachment_image(..., 'large')`, lazy, decoding async; preferuje obraz `_awi_listing_image_id` z integracji AWI, potem thumbnail, potem placeholder WooCommerce.
- Proporcje kontenera: `261x168`, czyli ok. `1.55:1`; `object-fit: contain`, tło karty białe, skala `1.1`.
- Wishlist: okrągły przycisk serca 36x36 w prawym górnym rogu; funkcja do potwierdzenia.
- Numer części: label + bold value.
- Nazwa: link, 14px, regular; długie nazwy łamią się naturalnie w kilku liniach.
- Cena: czarna, bold, `zł`.
- Dostawa: zielony komunikat „Darmowa dostawa: [data]” i „Jeśli zapłacisz do 13:30”.
- Brak CTA „Dodaj do koszyka” na karcie; przejście przez zdjęcie/nazwę.
- Promocje: CSS przewiduje badge i cenę sale, publiczny indeks nie pokazał aktywnego badge na kartach; do potwierdzenia.

## 12. Product detail page

Przykładowy URL: `/produkt/skrzynia-biegow-a506-c514-fiat-bravo-1-4-16v-6-biegow/`.

### Układ

- Wrapper `#product-{id}.gp-product-page`.
- Hero w 3 obszarach: galeria, info, purchase box.
- Galeria: standard `woocommerce_show_product_images()` z miniaturami/zoom/lightbox zależnie od WooCommerce i włączonych skryptów; szczegóły zachowania: do potwierdzenia na produkcji.
- Info card:
  - H1 tytuł produktu;
  - meta: „Numer części” i „Stan: Używany / sprawdzony”;
  - dla produktów Ovoko: CTA „Pokaż więcej części z tego pojazdu” po `ovoko_car_id`;
  - dla nie-Ovoko: short description, jeśli istnieje.
- Trust row:
  - Czas dostawy;
  - Metody płatności, obraz `/wp-content/uploads/payments.jpg`;
  - Zwroty: „Zwrot do 14 dni zgodnie z regulaminem.”
- Purchase box:
  - label „Cena produktu”;
  - cena WooCommerce;
  - notatka: „Cena brutto. Najniższa cena z 30 dni dostępna przy finalizacji zamówienia.”;
  - standard WooCommerce add-to-cart z ilością;
  - link „Masz pytanie? Skontaktuj się”;
  - helper „Pomagamy w doborze części po numerze VIN / OEM.”
- Detale: `woocommerce_output_product_data_tabs()`.

### Zakładki/dane

- Standardowe WooCommerce tabs: opis, informacje dodatkowe, opinie — zależne od konfiguracji.
- Custom compatibility tab: czyta JSON `_allegro_parameters` i pokazuje parametry zawierające `model`, `pojazd` lub `marka`; jeśli brak, komunikat o kontakcie z VIN/OEM.
- Custom warranty tab: gwarancja rozruchowa.
- Custom seller tab: opis GP Gregor Swiss.

### WooCommerce → Laravel mapping

| WooCommerce | Laravel GPS Product Hub |
|---|---|
| `post_title` | `parts.name` |
| `_sku` | `parts.sku` albo `parts.external_sku` |
| `_part_number` | `parts.part_number` |
| `_regular_price` / `_price` | `parts.price` |
| `post_excerpt` | `parts.short_description` |
| `post_content` | `parts.description` |
| `product_cat` | `part_categories` + pivot/category path |
| featured/gallery attachment IDs | `part_images` |
| `_awi_listing_image_id` | `part_images.is_listing` lub `listing_image_id` |
| `_allegro_parameters` | `parts.attributes` JSON / osobne tabele atrybutów |
| OEM | `parts.oem_number` / `parts.oem_numbers` JSON — do potwierdzenia źródła |
| manufacturer code | `parts.manufacturer_code` |
| `gp_car_brand` | `cars.brand` lub `parts.car_brand` / taxonomy mirror |
| `_ovoko_car_id` | `cars.ovoko_car_id`, `parts.car_id` |
| storage/meta magazynowe | `storage_locations` + `parts.storage_location_id` |
| stock quantity | `parts.stock_quantity` / inventory table |

## 13. Product images

- Listing: obraz `large`, lazy, `object-fit: contain`, kontener 261x168, białe tło.
- PDP: standardowa galeria WooCommerce; miniatury, lightbox/zoom zależne od aktywnych funkcji Woo.
- Placeholder: WooCommerce placeholder image, gdy brak attachmentu.
- Integracja obrazów: AWI plugin wybiera listing image, jeśli dostępny.
- Kadrowanie: nie cropować agresywnie; używać contain, bo części samochodowe mają nieregularne proporcje.
- Maksymalna liczba zdjęć: do potwierdzenia; Laravel powinien obsłużyć min. 12-20 zdjęć na produkt.

### Rekomendowane rozmiary Laravel

| Wariant | Rozmiar | Proporcja | Tryb |
|---|---:|---:|---|
| thumbnail | 160x120 | 4:3 | contain na białym tle |
| listing card | 522x336 retina | 1.55:1 | contain |
| product main | 900x675 | 4:3 | contain, zoom źródłowy |
| gallery/lightbox | max 1600x1200 | oryginalna/4:3 | bez stratnego cropu |
| placeholder | 522x336 | 1.55:1 | neutralny szary/biały, logo subtelne |

## 14. Cart

- Ikona koszyka w headerze, label „Koszyk”, licznik ilości.
- Mini-cart panel jako aside/dialog: close `×`, tytuł „Koszyk”, dynamiczna zawartość, CTA `Zamówienie` i `Przejdź do koszyka`.
- AJAX fragments aktualizują licznik mini-cart.
- Publiczny pusty stan: „Twój koszyk jest pusty.”
- Strona koszyka: standard WooCommerce — kolumny produkt/cena/ilość/suma, usuwanie, aktualizacja, kupony, totals; szczegóły aktywnych metod dostawy: do potwierdzenia.
- Błędy: standard Woo notices.

## 15. Checkout

Checkout jest standardowym WooCommerce checkout z endpointem `wc_get_checkout_url()` / `/zamowienie`.

Do potwierdzenia w produkcji:
- aktywne pola faktury i wysyłki;
- czy NIP/firma są wymagane dla B2B;
- metody dostawy i ceny;
- bramki płatności;
- zgody checkbox/regulamin;
- komunikaty walidacji;
- thank-you page i e-maile.

Laravel MVP powinien odtworzyć:
1. koszyk sesyjny/persisted;
2. checkout jednoekranowy: dane kontaktowe, adres, opcjonalna firma/NIP, dostawa, płatność, zgody;
3. rezerwację stocku po złożeniu zamówienia;
4. płatność online/przelew/pobranie zgodnie z decyzją biznesową;
5. thank-you page, e-mail potwierdzenia, status zamówienia.

## 16. Customer account

- Header pokazuje profil i dropdown.
- Niezalogowany: logowanie, rejestracja, ulubione, historia zamówień.
- Modal logowania: e-mail, hasło, remember me, reset hasła, social login Google jeśli skonfigurowany, „Kontynuuj jako gość”.
- Konto WooCommerce: Moje konto, Moje zamówienia, wylogowanie.
- Dodatkowo w kodzie istnieje workflow zwrotów w koncie (`add-return`) i historia/ułatwione zwroty.
- Laravel: konto klienta można zrobić w MVP, jeśli historia zamówień/zwroty mają być zachowane; w przeciwnym razie etap 2, ale checkout gościa musi działać od razu.

## 17. Static pages

| Strona | URL | Cel | Elementy do odtworzenia | Formularz |
|---|---|---|---|---|
| Kontakt | `/kontakt` | kontakt i mapa | dane firmy, telefon, e-mail, mapa iframe, formularz | tak |
| Logowanie | `/zaloguj` | logowanie klienta | e-mail/hasło, reset, Google opcjonalnie | tak |
| Rejestracja | `/zarejestruj` | konto klienta | formularz rejestracji | tak |
| Regulamin / płatności | `/regulamin-platnosci` | prawne i płatności | treść regulaminowa | nie / do potwierdzenia |
| Polityka prywatności | `/polityka-prywatnosci` lub WordPress privacy URL | RODO/cookies | treść prawna | nie |
| Zwroty | `/zwroty` | zwroty i reklamacje | instrukcja zwrotu, workflow | możliwe w koncie |
| Ulubione | `/ulubione` | wishlist | lista ulubionych | nie |
| Historia zamówień | endpoint konta Woo | historia | lista zamówień | nie |

## 18. SEO and redirects

### Obecna struktura

- Produkty: `/produkt/{product-slug}/`.
- Kategorie: `/kategoria-produktu/{hierarchical-category-slugs}/`.
- Search: `/?s={query}&post_type=product`, `?part_number={number}`, `?search_mode=vehicle_model`.
- Breadcrumb: WooCommerce breadcrumb na listingach i produktach.
- Title/meta/canonical/OpenGraph/schema/sitemap/robots: do potwierdzenia z produkcyjnego WordPress/SEO pluginu. WooCommerce zwykle emituje Product schema, ale nie potwierdzono w repo.

### Rekomendacja URL Laravel

- Zachować kompatybilne URL-e przynajmniej dla SEO:
  - `GET /produkt/{slug}` → `ProductController@show`;
  - `GET /kategoria-produktu/{path}` → `CategoryController@show`;
  - `GET /sklep` lub `/motoryzacja` → katalog.
- Jeśli nowe URL-e będą inne, przygotować tabelę `legacy_redirects`:
  - `old_path`, `new_path`, `entity_type`, `entity_id`, `status=301`.
- Generować canonical do finalnego Laravel URL.
- Dla produktów generować `schema.org/Product`: name, image, description, sku/part number, offers price/currency/availability, brand jeśli znana.
- Zachować meta z importu Woo/Yoast/RankMath, jeśli istnieją.

## 19. Mobile UX

- Header: układ jednokolumnowy, top links przewijane, wyszukiwarka pełna szerokość.
- Menu: hamburger/mega-menu z akordeonami poziomów kategorii.
- Listing: karty 1 kolumna; rekomendacja Laravel: filtry jako offcanvas z przyciskiem „Filtry”.
- Product card: zdjęcie na górze, serce absolute, teksty poniżej.
- PDP: rekomendacja układu mobile: galeria → tytuł/meta → cena/CTA sticky bottom lub box po galerii → detale.
- Koszyk/checkout: Woo standard responsive; w Laravel zaprojektować pola w jednej kolumnie i podsumowanie sticky/collapsible.

## 20. WooCommerce/plugin functionality

Widoczne lub prawdopodobne funkcje:

- WooCommerce: katalog, produkty, koszyk, checkout, konto, breadcrumbs, gallery, tabs, notices, sorting.
- AWI Plugin: wybór listing image (`_awi_listing_image_id`, `AWI\Plugin::get_listing_image_id_for_product`).
- Ovoko/import marketplace: `_ovoko_car_id`, przycisk „Pokaż więcej części z tego pojazdu”.
- Allegro/import marketplace: `_allegro_parameters` używane do kompatybilności.
- Google OAuth: opcjonalny login/rejestracja.
- Wishlist/ulubione: UI widoczny, backend do potwierdzenia.
- Zwroty w Moim koncie: custom endpoint `add-return`.
- SEO/cache/płatności/dostawy: do potwierdzenia po pluginach produkcyjnego WP.

## 21. Product data model

### Widoczne dla klienta

- nazwa;
- numer części;
- cena;
- waluta PLN;
- zdjęcia;
- stan `Używany / sprawdzony`;
- dostępność/stock, jeśli Woo pokazuje w add-to-cart lub snippets;
- czas dostawy / darmowa dostawa;
- opis krótki/długi;
- kategoria;
- kompatybilność z parametrów;
- zwroty/gwarancja;
- części z tego samego pojazdu dla Ovoko.

### Techniczne/admin

- Woo ID, slug, status;
- `_part_number`, `_sku`, `_price`, `_stock`;
- `_awi_listing_image_id`;
- `_ovoko_car_id`;
- `_allegro_parameters` JSON;
- attachment IDs/order;
- storage location — do potwierdzenia.

### Potrzebne do wyszukiwarki

- normalized name;
- normalized part number;
- SKU;
- OEM/manufacturer codes;
- brand/model/generation/year;
- category path;
- description tokens;
- Allegro/Ovoko parameters.

### Potrzebne do SEO

- slug, title, meta title, meta description;
- canonical;
- category breadcrumbs;
- primary image alt;
- Product schema fields;
- legacy Woo URL.

### Potrzebne do marketplace

- external IDs: Ovoko/Allegro;
- condition;
- OEM/part numbers;
- compatibility parameters;
- stock/location;
- images order;
- shipping class/weight/dimensions — do potwierdzenia.

## 22. Laravel implementation recommendation

### Routes

```php
GET  /                       Storefront\HomeController@index
GET  /sklep                  Storefront\CatalogController@index
GET  /kategoria-produktu/{path} Storefront\CategoryController@show where path=.*
GET  /produkt/{slug}         Storefront\PartController@show
GET  /szukaj                 Storefront\SearchController@index
GET  /koszyk                 Storefront\CartController@show
POST /koszyk/items           Storefront\CartController@store
PATCH /koszyk/items/{id}     Storefront\CartController@update
DELETE /koszyk/items/{id}    Storefront\CartController@destroy
GET  /zamowienie             Storefront\CheckoutController@show
POST /zamowienie             Storefront\CheckoutController@store
GET  /zamowienie/dziekujemy/{order}
GET  /moje-konto/*           Storefront\AccountController / etap 2
```

### Controllers/views/components

- `HomeController`: sekcje popularne wg kategorii, marki, SEO content.
- `CatalogController` / `CategoryController`: query builder z filtrami, sortowaniem, pagination 60.
- `PartController`: PDP, related/same vehicle.
- Blade components:
  - `x-store.header`, `x-store.footer`, `x-store.mega-menu`, `x-store.search-bar`;
  - `x-product.card`, `x-product.gallery`, `x-product.price`, `x-product.delivery`;
  - `x-catalog.filters`, `x-catalog.sort`, `x-catalog.breadcrumbs`;
  - `x-cart.mini-cart`, `x-cart.line-item`.

### Models/scopes

- `Part`: `published()`, `inStock()`, `search($q)`, `partNumber($value)`, `priceBetween()`, `brand()`, `categoryPath()`, `sameCar()`.
- `PartImage`: `listing()`, `ordered()`, conversions.
- `PartCategory`: adjacency tree, path slugs, `visibleInMenu()`.
- `Car`: brand/model/year/engine/ovoko ID relations.
- `StorageLocation`: only admin/internal unless business wants warehouse info public.
- `Order`, `OrderItem`: later production checkout.

### Filters/search

- Use GET params: `q`, `part_number`, `vehicle_model`, `brand`, `category`, `price_min`, `price_max`, `sort`, `page`.
- Normalize part/OEM numbers at write time.
- Cache category tree and homepage sections.
- For search ranking: exact part number > normalized part number prefix > title phrase > title tokens > OEM/manufacturer > category/description.

### Cart/checkout/orders

- Session cart for guests + optional persisted cart for users.
- Validate one-off used parts stock before payment.
- Order states: pending, awaiting_payment, paid, processing, shipped, completed, cancelled, refunded/return_requested.
- Store frozen product snapshot in `order_items`.

### SEO/images/cache

- Image conversions via queues; preserve originals.
- Cache: category tree, menu, homepage product sections, product detail fragments; invalidate on product/category update.
- Redirect middleware reads `legacy_redirects` before 404.

## 23. Open questions / things requiring business decision

1. Czy Laravel ma zachować dokładnie URL-e WooCommerce, czy użyć nowych z redirectami?
2. Jakie bramki płatności i metody dostawy są aktywne produkcyjnie?
3. Czy konto klienta i zwroty mają wejść do MVP, czy etap 2?
4. Czy wishlist/ulubione jest realną funkcją czy tylko UI?
5. Jak mapować OEM: pojedyncze pole, wiele numerów, tabela relacyjna?
6. Czy `Stan: Używany / sprawdzony` jest zawsze stały, czy importowany per produkt?
7. Czy pokazywać lokalizację magazynową klientowi, czy tylko adminowi?
8. Jaki jest docelowy provider wyszukiwania: SQL FULLTEXT, Meilisearch, Elasticsearch?
9. Czy zachować języki w headerze i wdrożyć i18n od MVP?
10. Czy odtworzyć Google OAuth?

## 24. Ważne wymagania

- Nie zgadywać bez oznaczenia: elementy niepotwierdzone oznaczono „do potwierdzenia”.
- Checkout i aktywne płatności/dostawy wymagają testu na produkcji/stagingu z realnym produktem.
- Różnice desktop/mobile opisano na podstawie CSS i markup.
- Jeśli na produkcji działa banner cookies/cache/SEO plugin, należy wykonać dodatkowy audyt przeglądarkowy.
- Dokumentacja jest specyfikacją dla Laravel; nie zawiera implementacji nowego frontendu.
