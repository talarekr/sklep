# Global Parts Clone – instrukcja wdrożenia end-to-end (WordPress + WooCommerce + Allegro)

## 1) Co jest dostarczone

### Motyw sklepu (frontend)
- komplet szablonów strony głównej
- header + footer
- widoki WooCommerce: archiwum i karta produktu
- style dla sklepu, koszyka, checkoutu, konta
- wyszukiwarka i mini-cart

### Wtyczka integracyjna Allegro
- OAuth 2.0 Authorization Code flow
- odświeżanie access tokena
- ręczny i automatyczny import (cron)
- mapowanie oferty Allegro do produktu WooCommerce
- logi i historia importów

## 2) Struktura katalogów

```text
/wp-content/themes/global-parts-clone/
  style.css
  functions.php
  header.php
  footer.php
  front-page.php
  woocommerce.php
  woocommerce/
  template-parts/home/
  assets/

/wp-content/plugins/allegro-woo-importer/
  allegro-woo-importer.php
  includes/
  templates/

/wp-content/plugins/gp-partscentrum-connector/
  gp-partscentrum-connector.php
  includes/
  TECHNICAL_ANALYSIS.md
```

## 3) Instalacja WordPress + WooCommerce
1. Zainstaluj WordPress 6.x na hostingu (PHP 8.0+).
2. Zainstaluj i aktywuj WooCommerce.
3. W WooCommerce uruchom kreator i utwórz strony: Sklep, Koszyk, Zamówienie, Moje konto.
4. Ustaw stronę główną statyczną na stronę wykorzystującą `front-page.php`.

## 4) Instalacja motywu
1. Spakuj motyw do ZIP (lub użyj gotowego ZIP z `dist/`).
2. W panelu WP: Wygląd → Motywy → Dodaj nowy → Wyślij motyw.
3. Aktywuj motyw **Global Parts Clone**.
4. W Wygląd → Dostosuj → Tożsamość witryny wgraj docelowe logo.

## 5) Instalacja wtyczki Allegro
1. Spakuj wtyczkę do ZIP (lub użyj gotowego ZIP z `dist/`).
2. W panelu WP: Wtyczki → Dodaj nową → Wyślij wtyczkę.
3. Aktywuj **Allegro Woo Importer**.

## 5a) Instalacja wtyczki GP Partscentrum Connector
1. Spakuj wtyczkę do ZIP (lub użyj gotowego ZIP z `dist/`).
2. W panelu WP: Wtyczki → Dodaj nową → Wyślij wtyczkę.
3. Aktywuj **GP Partscentrum Connector**.
4. W `wp-config.php` dodaj dane logowania dostawcy:

```php
define('GP_PARTSCENTRUM_LOGIN', 'TU_LOGIN');
define('GP_PARTSCENTRUM_PASSWORD', 'TU_HASLO');
```

## 6) Konfiguracja Allegro API (OAuth)
1. Utwórz aplikację w Allegro Developer.
2. Odbierz `client_id` i `client_secret`.
3. Ustaw Redirect URI dokładnie taki sam jak w panelu WP (ekran wtyczki Allegro Import).
4. W panelu WP → Allegro Import:
   - wpisz Client ID
   - wpisz Client Secret
   - wpisz Redirect URI
   - wybierz środowisko: sandbox/production
   - zapisz ustawienia
5. Kliknij **Połącz z Allegro** i dokończ autoryzację.

## 7) Konfiguracja importu
1. Ustaw tryb synchronizacji:
   - tylko tworzenie
   - tylko aktualizacja
   - tworzenie + aktualizacja
2. Ustaw filtr statusu oferty (np. `ACTIVE`).
3. Ustaw harmonogram (manual / 15 min / hourly / daily).
4. Kliknij **Importuj teraz** dla pierwszego importu.

## 8) Mapowanie danych (gotowe)
- tytuł oferty → nazwa produktu
- opis (sekcje/HTML) → opis produktu
- zdjęcia → obrazek główny + galeria
- cena → regular_price
- SKU/numer części (jeśli dostępny) → `_sku`
- Allegro offerId → `_allegro_offer_id`
- URL aukcji → `_allegro_offer_url`
- status publikacji → status produktu / meta
- parametry → atrybuty produktu + meta

## 9) Co musisz uzupełnić ręcznie
1. Prawdziwe dane aplikacji Allegro (client_id/secret).
2. Poprawny redirect URI zgodny z konfiguracją Allegro.
3. Docelowe logo, treści regulaminowe i dane firmy.
4. Metody płatności i wysyłki WooCommerce.
5. SEO i analityka (np. GA4, Search Console).

## 10) Weryfikacja po wdrożeniu
1. Sprawdź log wtyczki (`uploads/allegro-import.log`).
2. Uruchom ręczny import i sprawdź statystyki.
3. Sprawdź czy produkty mają:
   - nazwę
   - cenę
   - zdjęcia
   - SKU
   - meta Allegro
4. Zweryfikuj strony: sklep, produkt, koszyk, checkout, konto.

## 11) Gotowe paczki ZIP
Jeśli środowisko pozwala, paczki są generowane do:
- `dist/global-parts-clone-theme.zip`
- `dist/allegro-woo-importer.zip`
- `dist/gp-partscentrum-connector.zip`


## 12) Budowanie ZIP lokalnie (bez commitowania artefaktów)
Uruchom w katalogu repo:

```bash
./scripts/build-packages.sh
```

Skrypt wygeneruje lokalnie:
- `dist/global-parts-clone-theme.zip`
- `dist/allegro-woo-importer.zip`
- `dist/gp-partscentrum-connector.zip`

Katalog `dist/` i pliki archiwów są ignorowane przez `.gitignore`.

## 13) Konfiguracja SMTP + maile WooCommerce (GPSWISS)

### Sekrety (bez hardcodowania)
W `wp-config.php` dodaj zmienne (albo ustaw odpowiednie zmienne środowiskowe `GP_*`):

```php
define('GP_SMTP_HOST', 'thecamels.org');
define('GP_SMTP_PORT', '587');
define('GP_SMTP_LOGIN', 'biuro@gpswiss.pl');
define('GP_SMTP_PASSWORD', 'TU_WPROWADZ_HASLO_POZA_REPO');
define('GP_MAIL_FROM', 'biuro@gpswiss.pl');
define('GP_MAIL_FROM_NAME', 'GPSWISS');
define('GP_MAIL_REPLY_TO', 'biuro@gpswiss.pl');
```

> `GP_SMTP_PASSWORD` musi być ustawione wyłącznie w bezpiecznej konfiguracji serwera (env / `wp-config.php`), nigdy w motywie ani repozytorium.

### Co zostało podpięte
- SMTP dla WordPress (`phpmailer_init`) z STARTTLS na porcie 587.
- Nadawca i nazwa nadawcy (`wp_mail_from`, `wp_mail_from_name`) oraz `Reply-To`.
- Logowanie błędów wysyłki do WooCommerce loggera (`source: gp-mailer`) albo `error_log`.
- Branding szablonów WooCommerce przez override:
  - `woocommerce/emails/email-header.php`
  - `woocommerce/emails/email-footer.php`

### Zdarzenia mailowe
- Rejestracja konta — e-mail WooCommerce „Nowe konto klienta” (spersonalizowany temat + treść dodatkowa).
- Złożenie zamówienia — „On-hold order” (temat: „Przyjęliśmy Twoje zamówienie…”).
- Potwierdzenie płatności — „Processing order” (temat: „Płatność … została potwierdzona”).
- Nieudana płatność — dedykowany e-mail klienta na statusie `failed`.
- Wysyłka zamówienia — „Completed order” (temat: „… zostało wysłane”).
- Reset hasła — temat + treść resetu dostosowane do GPSWISS.
- Anulowanie zamówienia — dedykowany e-mail klienta na statusie `cancelled`.

### Test po wdrożeniu (produkcyjnie)
1. Ustaw hasło SMTP w bezpiecznej konfiguracji.
2. Wykonaj reset hasła testowego klienta i potwierdź dostarczenie maila.
3. Złóż testowe zamówienie oraz przełączaj statusy (`on-hold`, `processing`, `failed`, `completed`, `cancelled`) i sprawdź skrzynkę odbiorczą.
4. Zweryfikuj logi WooCommerce (`WooCommerce → Status → Logi`, źródło `gp-mailer`).
