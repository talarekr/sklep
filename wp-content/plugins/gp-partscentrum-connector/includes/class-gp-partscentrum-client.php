<?php

if (!defined('ABSPATH')) {
    exit;
}

class GP_Partscentrum_Client
{
    private const BASE_URL = 'https://partscentrum.jns.pl';

    /** @var array<string, string> */
    private array $cookies = [];

    /** @var string[] */
    private array $setCookieHeaders = [];

    private int $timeout = 10;

    /** @var array<string, mixed> */
    private array $runtime = [];

    /** @var array<string, mixed>|null */
    private ?array $lastSearch = null;

    public function __construct()
    {
        $this->runtime = [
            'login_path' => apply_filters('gp_partscentrum_login_path', '/login'),
            'search_path' => apply_filters('gp_partscentrum_search_path', '/user/search'),
            'search_http_method' => strtoupper((string) apply_filters('gp_partscentrum_search_method', 'POST')),
        ];
    }

    public function login(): bool
    {
        $login = defined('GP_PARTSCENTRUM_LOGIN') ? (string) GP_PARTSCENTRUM_LOGIN : '';
        $password = defined('GP_PARTSCENTRUM_PASSWORD') ? (string) GP_PARTSCENTRUM_PASSWORD : '';

        if ($login === '' || $password === '') {
            $this->log('error', 'Brak danych logowania GP_PARTSCENTRUM_LOGIN/GP_PARTSCENTRUM_PASSWORD.');
            return false;
        }

        $loginUrl = $this->absoluteUrl((string) $this->runtime['login_path']);
        $response = $this->request('GET', $loginUrl);
        if (is_wp_error($response)) {
            $this->log('error', 'Nie udało się pobrać strony logowania.', ['error' => $response->get_error_message()]);
            $this->log_debug([
                'login_http_code' => 0,
                'login_success' => false,
                'error_reason' => 'login_page_request_failed',
            ]);
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 400 || $body === '') {
            $this->log('error', 'Niepoprawna odpowiedź strony logowania.', ['status' => $code]);
            $this->log_debug([
                'login_http_code' => $code,
                'login_success' => false,
                'error_reason' => 'invalid_login_page_response',
            ]);
            return false;
        }

        $this->captureCookies($response);
        $form = $this->extractLoginForm($body, $loginUrl);
        if ($form === null) {
            $this->log('error', 'Nie znaleziono formularza logowania na stronie dostawcy.');
            $this->log_debug([
                'login_http_code' => $code,
                'login_success' => false,
                'error_reason' => 'login_form_not_found',
            ]);
            return false;
        }

        $payload = $form['hidden'];
        $payload[$form['username_field']] = $login;
        $payload[$form['password_field']] = $password;

        $submitResponse = $this->request('POST', $form['action'], [
            'body' => $payload,
            'headers' => [
                'Referer' => $loginUrl,
                'Origin' => self::BASE_URL,
            ],
        ]);

        if (is_wp_error($submitResponse)) {
            $this->log('error', 'Błąd podczas wysyłki formularza logowania.', ['error' => $submitResponse->get_error_message()]);
            $this->log_debug([
                'login_http_code' => 0,
                'login_success' => false,
                'error_reason' => 'login_submit_failed',
            ]);
            return false;
        }

        $this->captureCookies($submitResponse);
        $submitCode = (int) wp_remote_retrieve_response_code($submitResponse);
        $submitBody = (string) wp_remote_retrieve_body($submitResponse);

        if ($submitCode >= 400) {
            $this->log('error', 'Logowanie zwróciło błąd HTTP.', ['status' => $submitCode]);
            $this->log_debug([
                'login_http_code' => $submitCode,
                'login_success' => false,
                'error_reason' => 'login_http_error',
            ]);
            return false;
        }

        $loggedIn = $this->detectAuthenticatedState($submitBody);
        $this->log_debug([
            'login_http_code' => $submitCode,
            'login_success' => $loggedIn,
            'error_reason' => $loggedIn ? '' : 'auth_markers_missing',
        ]);

        return $loggedIn;
    }

    /**
     * @return array{success:bool,error?:string,data?:array<string,mixed>}
     */
    public function search_part(string $part_number): array
    {
        $partNumber = strtoupper(trim(preg_replace('/[^A-Za-z0-9\-_.\/]/', '', $part_number) ?? ''));
        if ($partNumber === '') {
            return [
                'success' => false,
                'error' => 'Numer części jest nieprawidłowy.',
            ];
        }

        if (!empty($this->lastSearch) && ($this->lastSearch['submitted_part_number'] ?? '') === $partNumber) {
            return [
                'success' => true,
                'data' => $this->lastSearch,
            ];
        }

        $searchUrl = $this->absoluteUrl((string) $this->runtime['search_path']);
        $method = (string) $this->runtime['search_http_method'];
        $fieldName = (string) apply_filters('gp_partscentrum_search_field', 'pn');

        $args = [
            'headers' => [
                'Referer' => $searchUrl,
            ],
        ];

        if ($method === 'POST') {
            $args['body'] = [
                $fieldName => $partNumber,
                'pn' => $partNumber,
                'part_number' => $partNumber,
                'partNumber' => $partNumber,
            ];
        } else {
            $searchUrl = add_query_arg([$fieldName => $partNumber], $searchUrl);
        }

        $response = $this->request($method, $searchUrl, $args);

        if (is_wp_error($response)) {
            $this->log_debug([
                'search_http_code' => 0,
                'search_url' => $searchUrl,
                'search_method' => $method,
                'submitted_part_number' => $partNumber,
                'results_table_found' => false,
                'parsed_results_count' => 0,
                'error_reason' => 'search_request_failed',
            ]);
            return [
                'success' => false,
                'error' => 'Nie udało się połączyć z panelem dostawcy.',
            ];
        }

        $this->captureCookies($response);
        $searchCode = (int) wp_remote_retrieve_response_code($response);
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        $parsed = $this->parseSearchResult($body, $contentType, $partNumber);
        if ($parsed === null) {
            $this->log_debug([
                'search_http_code' => $searchCode,
                'search_url' => $searchUrl,
                'search_method' => $method,
                'submitted_part_number' => $partNumber,
                'results_table_found' => false,
                'parsed_results_count' => 0,
                'error_reason' => 'results_not_found_or_unparsed',
            ]);
            return [
                'success' => false,
                'error' => 'Brak wyników lub nieobsługiwany format odpowiedzi dostawcy.',
            ];
        }

        $this->lastSearch = $parsed;
        $this->log_debug([
            'search_http_code' => $searchCode,
            'search_url' => $searchUrl,
            'search_method' => $method,
            'submitted_part_number' => $partNumber,
            'results_table_found' => true,
            'parsed_results_count' => count((array) ($parsed['items'] ?? [])),
            'error_reason' => '',
        ]);

        return [
            'success' => true,
            'data' => $parsed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_runtime_diagnostics(): array
    {
        return [
            'login_path' => $this->runtime['login_path'],
            'search_path' => $this->runtime['search_path'],
            'search_method' => $this->runtime['search_http_method'],
            'cookies_count' => count($this->cookies),
            'set_cookie_headers' => count($this->setCookieHeaders),
        ];
    }

    private function absoluteUrl(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return rtrim(self::BASE_URL, '/') . '/' . ltrim($pathOrUrl, '/');
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    private function request(string $method, string $url, array $args = [])
    {
        $defaults = [
            'timeout' => $this->timeout,
            'redirection' => 5,
            'sslverify' => true,
            'headers' => [],
        ];

        $args = wp_parse_args($args, $defaults);
        $args['headers']['Cookie'] = $this->buildCookieHeader();

        if (strtoupper($method) === 'POST') {
            return wp_remote_post($url, $args);
        }

        return wp_remote_get($url, $args);
    }

    private function buildCookieHeader(): string
    {
        if ($this->cookies === []) {
            return '';
        }

        $cookieParts = [];
        foreach ($this->cookies as $name => $value) {
            $cookieParts[] = $name . '=' . $value;
        }

        return implode('; ', $cookieParts);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function captureCookies(array $response): void
    {
        $setCookie = wp_remote_retrieve_header($response, 'set-cookie');
        if (empty($setCookie)) {
            return;
        }

        $headers = is_array($setCookie) ? $setCookie : [$setCookie];
        foreach ($headers as $headerLine) {
            $this->setCookieHeaders[] = (string) $headerLine;
            $pair = explode(';', (string) $headerLine)[0] ?? '';
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $this->cookies[$name] = trim((string) $value);
        }
    }

    /**
     * @return array{action:string,username_field:string,password_field:string,hidden:array<string,string>}|null
     */
    private function extractLoginForm(string $html, string $fallbackAction): ?array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $forms = $xpath->query('//form');
        if (!$forms instanceof DOMNodeList || $forms->length === 0) {
            return null;
        }

        foreach ($forms as $form) {
            $passwordInputs = $xpath->query('.//input[@type="password"]', $form);
            if (!$passwordInputs instanceof DOMNodeList || $passwordInputs->length === 0) {
                continue;
            }

            $action = (string) ($form->attributes?->getNamedItem('action')?->nodeValue ?? '');
            $action = $action !== '' ? $this->absoluteUrl($action) : $fallbackAction;

            $hidden = [];
            $inputs = $xpath->query('.//input', $form);
            $usernameField = '';
            $passwordField = '';

            if ($inputs instanceof DOMNodeList) {
                foreach ($inputs as $input) {
                    $name = (string) ($input->attributes?->getNamedItem('name')?->nodeValue ?? '');
                    $type = strtolower((string) ($input->attributes?->getNamedItem('type')?->nodeValue ?? 'text'));
                    $value = (string) ($input->attributes?->getNamedItem('value')?->nodeValue ?? '');

                    if ($name === '') {
                        continue;
                    }

                    if ($type === 'hidden') {
                        $hidden[$name] = $value;
                    }

                    if ($type === 'password' && $passwordField === '') {
                        $passwordField = $name;
                    }

                    if ($usernameField === '' && in_array($type, ['text', 'email'], true)) {
                        $usernameField = $name;
                    }
                }
            }

            if ($usernameField === '') {
                $usernameField = (string) apply_filters('gp_partscentrum_login_username_field', 'email');
            }
            if ($passwordField === '') {
                $passwordField = (string) apply_filters('gp_partscentrum_login_password_field', 'password');
            }

            return [
                'action' => $action,
                'username_field' => $usernameField,
                'password_field' => $passwordField,
                'hidden' => $hidden,
            ];
        }

        return null;
    }

    private function detectAuthenticatedState(string $html): bool
    {
        $needles = (array) apply_filters('gp_partscentrum_logged_in_markers', [
            'logout',
            'wylog',
            'koszyk',
            'zamów',
        ]);

        $htmlLower = strtolower($html);
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($htmlLower, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseSearchResult(string $body, string $contentType, string $partNumber): ?array
    {
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return null;
            }
            return $this->parseJsonResult($decoded, $partNumber);
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        return $this->parseHtmlResult($xpath, $partNumber);
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|null
     */
    private function parseJsonResult(array $decoded, string $partNumber): ?array
    {
        $items = [];
        $rows = $decoded['data'] ?? $decoded['items'] ?? $decoded;

        if (isset($rows['part_number']) || isset($rows['partNumber'])) {
            $rows = [$rows];
        }
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pn = (string) ($row['part_number'] ?? $row['partNumber'] ?? $partNumber);
            $grossDiscounted = (float) ($row['your_price_gross'] ?? $row['yourPriceGross'] ?? $row['price_gross_discounted'] ?? 0);
            if ($grossDiscounted <= 0) {
                continue;
            }
            $items[] = [
                'supplier_part_number' => $pn,
                'supplier_title' => (string) ($row['name'] ?? $row['title'] ?? ('Część ' . $pn)),
                'supplier_price_gross_discounted' => $grossDiscounted,
                'availability' => (string) ($row['availability'] ?? $row['stock'] ?? '- / -'),
                'supplier_product_id' => (string) ($row['id'] ?? ''),
                'checked_at' => gmdate('c'),
            ];
        }

        if ($items === []) {
            return null;
        }

        return [
            'submitted_part_number' => $partNumber,
            'raw_type' => 'json',
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseHtmlResult(DOMXPath $xpath, string $partNumber): ?array
    {
        $table = $this->findSearchTable($xpath);
        if (!$table instanceof DOMElement) {
            return null;
        }

        $rows = $xpath->query('.//tr[td]', $table);
        if (!$rows instanceof DOMNodeList || $rows->length === 0) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }
            $cells = $xpath->query('./td', $row);
            if (!$cells instanceof DOMNodeList || $cells->length < 7) {
                continue;
            }

            $item = $this->mapHtmlRowToItem($cells, $partNumber);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
        }

        if ($items === []) {
            return null;
        }

        return [
            'submitted_part_number' => $partNumber,
            'raw_type' => 'html',
            'items' => $items,
        ];
    }

    private function findSearchTable(DOMXPath $xpath): ?DOMElement
    {
        $tables = $xpath->query('//table');
        if (!$tables instanceof DOMNodeList || $tables->length === 0) {
            return null;
        }

        foreach ($tables as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }
            $header = strtolower($this->normalizeText((string) ($xpath->query('.//thead', $table)->item(0)?->textContent ?? $table->textContent)));
            if (
                str_contains($header, 'twoja cena brutto') &&
                str_contains($header, 'pn') &&
                str_contains($header, 'nazwa')
            ) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function mapHtmlRowToItem(DOMNodeList $cells, string $partNumber): ?array
    {
        $supplierPartNumber = $this->normalizeText((string) ($cells->item(1)?->textContent ?? ''));
        if ($supplierPartNumber === '') {
            return null;
        }

        if (!str_contains(strtoupper($supplierPartNumber), strtoupper($partNumber))) {
            return null;
        }

        $grossDiscounted = $this->parseMoney($this->normalizeText((string) ($cells->item(5)?->textContent ?? '')));
        if ($grossDiscounted <= 0) {
            return null;
        }

        return [
            'supplier_title' => $this->normalizeText((string) ($cells->item(0)?->textContent ?? '')),
            'supplier_part_number' => $supplierPartNumber,
            'supplier_price_gross_discounted' => $grossDiscounted,
            'availability' => $this->normalizeText((string) ($cells->item(6)?->textContent ?? '- / -')),
            'supplier_product_id' => '',
            'checked_at' => gmdate('c'),
        ];
    }

    private function parseMoney(string $value): float
    {
        if (preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)/', str_replace(' ', '', $value), $matches) !== 1) {
            return 0.0;
        }

        return (float) str_replace(',', '.', (string) $matches[1]);
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log_debug(array $context): void
    {
        $allowed = [
            'login_http_code' => (int) ($context['login_http_code'] ?? 0),
            'login_success' => (bool) ($context['login_success'] ?? false),
            'search_http_code' => (int) ($context['search_http_code'] ?? 0),
            'search_url' => (string) ($context['search_url'] ?? ''),
            'search_method' => (string) ($context['search_method'] ?? ''),
            'submitted_part_number' => (string) ($context['submitted_part_number'] ?? ''),
            'results_table_found' => (bool) ($context['results_table_found'] ?? false),
            'parsed_results_count' => (int) ($context['parsed_results_count'] ?? 0),
            'error_reason' => (string) ($context['error_reason'] ?? ''),
        ];

        $this->log('debug', 'Partscentrum flow diagnostics.', $allowed);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $redacted = $context;
        unset($redacted['password'], $redacted['login']);

        wc_get_logger()->log($level, $message . ' ' . wp_json_encode($redacted), ['source' => 'gp-partscentrum-connector']);
    }
}
