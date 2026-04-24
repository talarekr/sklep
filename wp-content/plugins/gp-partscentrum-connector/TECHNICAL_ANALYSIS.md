# Integracja partscentrum.jns.pl — analiza techniczna (Etap 1)

## 1) Logowanie

W tym środowisku nie dało się wykonać bezpośredniego połączenia HTTP z `https://partscentrum.jns.pl/login` (błąd tunelu CONNECT 403), więc nie dało się potwierdzić finalnych nazw pól i endpointów "na żywo".

Wdrożony klient jest przygotowany na typowe schematy logowania formularzem:

- pobiera stronę loginu `GET /login`,
- wykrywa formularz, który zawiera pole typu `password`,
- zbiera ukryte pola (`input[type=hidden]`, np. CSRF),
- wykrywa pole użytkownika (`text`/`email`) i hasła,
- wysyła `POST` na `action` formularza,
- utrzymuje ciasteczka sesji z nagłówków `Set-Cookie`.

Domyślne dane logowania są pobierane z `wp-config.php`:

```php
define('GP_PARTSCENTRUM_LOGIN', '...');
define('GP_PARTSCENTRUM_PASSWORD', '...');
```

## 2) Wyszukiwanie

Ponieważ panel nie był osiągalny z tego środowiska, moduł ma konfigurację filtrowalną i parser odporny na różne warianty:

- metoda wyszukiwania (`GET`/`POST`) — filtr `gp_partscentrum_search_method`,
- endpoint wyszukiwania — filtr `gp_partscentrum_search_path`,
- nazwa pola numeru części — filtr `gp_partscentrum_search_field`.

Klient wykonuje wyszukanie tylko po numerze części, bez odwołań do lokalnych produktów WooCommerce.

## 3) Format odpowiedzi (HTML/AJAX/JSON)

Obsługiwane ścieżki parsowania:

- `application/json`: mapowanie pól `name/title`, `part_number`, `price`, `availability`, `id`,
- `text/html`: heurystyczne parsowanie wiersza tabeli zawierającego numer części,
- fallback tekstowy, gdy odpowiedź zawiera numer części poza tabelą.

## 4) Jakie dane da się pobrać

Model danych wynikowych przygotowany w module:

- `supplier_title`,
- `supplier_part_number`,
- `supplier_price`,
- `availability`,
- `supplier_product_id`,
- `checked_at`.

Na stronie użytkownik widzi nazwę, numer części, dostępność i cenę końcową.

## 5) Dodanie do koszyka WooCommerce

Pozycja dodawana jest jako dynamiczny produkt pomocniczy (ukryty, prywatny) ze stałym SKU:

- `gp-partscentrum-dynamic`.

Do `cart item meta` trafiają wymagane dane:

- `source = partscentrum`,
- `type = new_skoda_part`,
- `supplier_part_number`,
- `supplier_title`,
- `supplier_price`,
- `margin_percent = 10`,
- `final_price`,
- `checked_at`.

Te same dane są kopiowane do `order item meta` podczas checkoutu.

## 6) Marża +10%

Cena liczona jest wyłącznie po stronie backendu:

```php
$final_price = $supplier_price * 1.10;
```

Implementacja używa stałej `MARGIN_PERCENT = 10.0` i zaokrągla do 2 miejsc (`wc_format_decimal`).

## 7) Ryzyka integracji

1. **Brak API dostawcy** — integracja opiera się o scraping HTML i jest podatna na zmiany UI.
2. **CSRF / dynamiczne tokeny** — zmiana mechanizmu formularza może chwilowo przerwać logowanie.
3. **WAF / bot protection / rate limiting po stronie dostawcy** — możliwe blokady po większym ruchu.
4. **Zmienny format cen i dostępności** — parser wymaga monitoringu i aktualizacji.
5. **Sesja/cookies** — wygasanie sesji może wymagać ponownego logowania dla każdego zapytania.
6. **Wydajność** — wolne odpowiedzi dostawcy wpływają na UX; dlatego dodano timeouty i cache.
7. **Bezpieczeństwo** — hasła wyłącznie poza repo, logi bez sekretów.

## Co zweryfikować po uzyskaniu dostępu sieciowego do panelu

- dokładny `action` formularza logowania,
- realne nazwy pól login/hasło,
- token CSRF i jego przekazanie,
- endpoint i metoda wyszukiwania numeru części,
- dokładne selektory HTML/klucze JSON dla ceny i dostępności.
