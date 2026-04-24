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
            'search_path' => apply_filters('gp_partscentrum_search_path', '/'),
            'search_http_method' => strtoupper((string) apply_filters('gp_partscentrum_search_method', 'GET')),
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
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 400 || $body === '') {
            $this->log('error', 'Niepoprawna odpowiedź strony logowania.', ['status' => $code]);
            return false;
        }

        $this->captureCookies($response);
        $form = $this->extractLoginForm($body, $loginUrl);
        if ($form === null) {
            $this->log('error', 'Nie znaleziono formularza logowania na stronie dostawcy.');
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
            return false;
        }

        $this->captureCookies($submitResponse);
        $submitCode = (int) wp_remote_retrieve_response_code($submitResponse);
        $submitBody = (string) wp_remote_retrieve_body($submitResponse);

        if ($submitCode >= 400) {
            $this->log('error', 'Logowanie zwróciło błąd HTTP.', ['status' => $submitCode]);
            return false;
        }

        $loggedIn = $this->detectAuthenticatedState($submitBody);
        if (!$loggedIn) {
            $this->log('warning', 'Logowanie mogło się nie udać — brak jednoznacznych markerów sesji.');
        }

        return true;
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

        if (!empty($this->lastSearch) && ($this->lastSearch['part_number'] ?? '') === $partNumber) {
            return [
                'success' => true,
                'data' => $this->lastSearch,
            ];
        }

        $searchUrl = $this->absoluteUrl((string) $this->runtime['search_path']);
        $method = (string) $this->runtime['search_http_method'];
        $fieldName = (string) apply_filters('gp_partscentrum_search_field', 'part_number');

        $args = [
            'headers' => [
                'Referer' => $searchUrl,
            ],
        ];

        if ($method === 'POST') {
            $args['body'] = [$fieldName => $partNumber];
        } else {
            $searchUrl = add_query_arg([$fieldName => rawurlencode($partNumber)], $searchUrl);
        }

        $response = $this->request($method, $searchUrl, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => 'Nie udało się połączyć z panelem dostawcy.',
            ];
        }

        $this->captureCookies($response);
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        $parsed = $this->parseSearchResult($body, $contentType, $partNumber);
        if ($parsed === null) {
            return [
                'success' => false,
                'error' => 'Brak wyników lub nieobsługiwany format odpowiedzi dostawcy.',
            ];
        }

        $this->lastSearch = $parsed;

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
        $supplierData = [
            'supplier_part_number' => $partNumber,
            'supplier_title' => '',
            'supplier_price' => 0.0,
            'availability' => 'unknown',
            'supplier_product_id' => '',
            'checked_at' => gmdate('c'),
            'raw_type' => '',
        ];

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return null;
            }
            $supplierData['raw_type'] = 'json';
            $supplierData['supplier_title'] = (string) ($decoded['name'] ?? $decoded['title'] ?? '');
            $supplierData['supplier_part_number'] = (string) ($decoded['part_number'] ?? $decoded['partNumber'] ?? $partNumber);
            $supplierData['supplier_product_id'] = (string) ($decoded['id'] ?? '');
            $supplierData['availability'] = strtolower((string) ($decoded['availability'] ?? $decoded['stock'] ?? 'unknown'));
            $supplierData['supplier_price'] = (float) ($decoded['price'] ?? 0);

            return $supplierData;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        $supplierData['raw_type'] = 'html';

        $row = $xpath->query("//tr[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '" . strtolower($partNumber) . "')]")->item(0);
        if ($row instanceof DOMNode) {
            $rowText = trim((string) $row->textContent);
            $supplierData['supplier_title'] = $this->extractTitleFromRowText($rowText, $partNumber);
            $supplierData['supplier_price'] = $this->extractPriceFromText($rowText);
            $supplierData['availability'] = $this->extractAvailabilityFromText($rowText);

            return $supplierData;
        }

        if (str_contains(strtolower($body), strtolower($partNumber))) {
            $supplierData['supplier_title'] = 'Część ' . $partNumber;
            $supplierData['supplier_price'] = $this->extractPriceFromText($body);
            $supplierData['availability'] = $this->extractAvailabilityFromText($body);

            return $supplierData;
        }

        return null;
    }

    private function extractPriceFromText(string $text): float
    {
        if (preg_match('/([0-9]{1,6}(?:[\s\.]?[0-9]{3})*(?:[\,\.][0-9]{2})?)\s*(?:zł|pln)?/iu', $text, $matches) === 1) {
            $raw = str_replace([' ', '.'], ['', ''], (string) $matches[1]);
            $raw = str_replace(',', '.', $raw);
            return (float) $raw;
        }

        return 0.0;
    }

    private function extractAvailabilityFromText(string $text): string
    {
        $lower = strtolower($text);
        if (str_contains($lower, 'dostęp') || str_contains($lower, 'na stanie') || str_contains($lower, 'available')) {
            return 'available';
        }
        if (str_contains($lower, 'brak') || str_contains($lower, 'niedostęp')) {
            return 'unavailable';
        }

        return 'unknown';
    }

    private function extractTitleFromRowText(string $text, string $partNumber): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', str_ireplace($partNumber, '', $text)) ?? '');
        if ($clean !== '') {
            return $clean;
        }

        return 'Nowa część Skoda ' . $partNumber;
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
