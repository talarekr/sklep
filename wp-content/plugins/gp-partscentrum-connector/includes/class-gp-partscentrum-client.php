<?php

if (!defined('ABSPATH')) {
    exit;
}

class GP_Partscentrum_Client
{
    private const BASE_URL = 'https://partscentrum.jns.pl';
    private const ADMIN_LOG_OPTION = 'gp_partscentrum_admin_logs';
    private const ADMIN_LOG_LIMIT = 100;

    /** @var array<string, WP_Http_Cookie> */
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
        $diagnostics = $this->run_login_diagnostic();

        return (bool) ($diagnostics['login_success'] ?? false);
    }

    /**
     * @return array<string,mixed>
     */
    public function run_login_diagnostic(): array
    {
        $login = defined('GP_PARTSCENTRUM_LOGIN') ? (string) GP_PARTSCENTRUM_LOGIN : '';
        $password = defined('GP_PARTSCENTRUM_PASSWORD') ? (string) GP_PARTSCENTRUM_PASSWORD : '';
        $loginUrl = $this->absoluteUrl((string) $this->runtime['login_path']);
        $diagnostics = [
            'login_url' => $loginUrl,
            'login_http_code' => 0,
            'form_found' => false,
            'redirect_detected' => false,
            'user_logged_in' => false,
            'login_success' => false,
            'cookies_after_login_count' => 0,
            'error_reason' => '',
        ];

        if ($login === '' || $password === '') {
            $this->log('error', 'Brak danych logowania GP_PARTSCENTRUM_LOGIN/GP_PARTSCENTRUM_PASSWORD.');
            $diagnostics['error_reason'] = 'missing_credentials';
            return $diagnostics;
        }

        $response = $this->request('GET', $loginUrl);
        if (is_wp_error($response)) {
            $this->log('error', 'Nie udało się pobrać strony logowania.', ['error' => $response->get_error_message()]);
            $diagnostics['error_reason'] = 'login_page_request_failed';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $diagnostics['login_http_code'] = $code;
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 400 || $body === '') {
            $this->log('error', 'Niepoprawna odpowiedź strony logowania.', ['status' => $code]);
            $diagnostics['error_reason'] = 'invalid_login_page_response';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        $this->captureCookies($response);
        $form = $this->extractLoginForm($body, $loginUrl);
        if ($form === null) {
            $this->log('error', 'Nie znaleziono formularza logowania na stronie dostawcy.');
            $diagnostics['error_reason'] = 'login_form_not_found';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }
        $diagnostics['form_found'] = true;

        $payload = $form['hidden'];
        $payload[$form['username_field']] = $login;
        $payload[$form['password_field']] = $password;

        $submitResponse = $this->request('POST', $form['action'], [
            'redirection' => 0,
            'body' => $payload,
            'headers' => [
                'Referer' => $loginUrl,
                'Origin' => self::BASE_URL,
            ],
        ]);

        if (is_wp_error($submitResponse)) {
            $this->log('error', 'Błąd podczas wysyłki formularza logowania.', ['error' => $submitResponse->get_error_message()]);
            $diagnostics['error_reason'] = 'login_submit_failed';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        $this->captureCookies($submitResponse);
        $diagnostics['cookies_after_login_count'] = count($this->cookies);
        $submitCode = (int) wp_remote_retrieve_response_code($submitResponse);
        $diagnostics['login_http_code'] = $submitCode;
        $submitBody = (string) wp_remote_retrieve_body($submitResponse);
        $redirectLocation = (string) wp_remote_retrieve_header($submitResponse, 'location');
        $diagnostics['redirect_detected'] = $submitCode >= 300 && $submitCode < 400 && $redirectLocation !== '';

        if ($submitCode >= 400) {
            $this->log('error', 'Logowanie zwróciło błąd HTTP.', ['status' => $submitCode]);
            $diagnostics['error_reason'] = 'login_http_error';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        if ($diagnostics['redirect_detected']) {
            $redirectResponse = $this->request('GET', $this->absoluteUrl($redirectLocation), [
                'headers' => ['Referer' => $loginUrl],
            ]);
            if (!is_wp_error($redirectResponse)) {
                $this->captureCookies($redirectResponse);
                $submitBody = (string) wp_remote_retrieve_body($redirectResponse);
            }
        }

        $loggedIn = $this->detectAuthenticatedState($submitBody);
        $diagnostics['user_logged_in'] = $loggedIn;
        $diagnostics['login_success'] = $loggedIn;
        $diagnostics['error_reason'] = $loggedIn ? '' : 'auth_markers_missing';
        $this->log_debug($diagnostics);

        return $diagnostics;
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

        $searchRequest = $this->prepare_search_request($partNumber);
        if (($searchRequest['success'] ?? false) !== true) {
            $this->log_debug([
                'search_http_code' => 0,
                'search_url' => (string) ($searchRequest['request_url'] ?? ''),
                'search_method' => (string) ($searchRequest['method'] ?? ''),
                'cookies_used_in_search_count' => count($this->cookies),
                'submitted_part_number' => $partNumber,
                'results_table_found' => false,
                'parsed_results_count' => 0,
                'error_reason' => (string) ($searchRequest['error_reason'] ?? 'search_form_prepare_failed'),
            ]);
            return [
                'success' => false,
                'error' => 'Nie udało się połączyć z panelem dostawcy.',
            ];
        }

        $responseMeta = $this->request_with_redirects(
            (string) ($searchRequest['method'] ?? 'POST'),
            (string) ($searchRequest['request_url'] ?? ''),
            (array) ($searchRequest['args'] ?? [])
        );
        if (is_wp_error($responseMeta['response'] ?? null)) {
            $this->log_debug([
                'search_http_code' => 0,
                'search_url' => (string) ($searchRequest['request_url'] ?? ''),
                'search_method' => (string) ($searchRequest['method'] ?? ''),
                'cookies_used_in_search_count' => count($this->cookies),
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

        $response = (array) ($responseMeta['response'] ?? []);

        $this->captureCookies($response);
        $searchCode = (int) wp_remote_retrieve_response_code($response);
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        $parsed = $this->parseSearchResult($body, $contentType, $partNumber);
        if ($parsed === null) {
            $this->log_debug([
                'search_http_code' => $searchCode,
                'search_url' => (string) ($searchRequest['request_url'] ?? ''),
                'search_method' => (string) ($searchRequest['method'] ?? ''),
                'cookies_used_in_search_count' => count($this->cookies),
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
            'search_url' => (string) ($searchRequest['request_url'] ?? ''),
            'search_method' => (string) ($searchRequest['method'] ?? ''),
            'cookies_used_in_search_count' => count($this->cookies),
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
     * @return array<string,mixed>
     */
    public function run_search_diagnostic(string $part_number, float $marginPercent = 10.0): array
    {
        $partNumber = strtoupper(trim(preg_replace('/[^A-Za-z0-9\-_.\/]/', '', $part_number) ?? ''));
        $searchUrl = $this->absoluteUrl((string) $this->runtime['search_path']);
        $diagnostics = [
            'login_url' => $this->absoluteUrl((string) $this->runtime['login_path']),
            'search_url' => $searchUrl,
            'search_method' => (string) $this->runtime['search_http_method'],
            'search_field_name_used' => '',
            'search_form_fields_detected' => [],
            'search_hidden_fields_detected' => [],
            'login_success' => false,
            'search_http_code' => 0,
            'search_response_length' => 0,
            'search_contains_lista_produktow' => false,
            'search_contains_do_koszyka' => false,
            'search_contains_pn' => false,
            'search_contains_table' => false,
            'final_search_url' => $searchUrl,
            'redirect_count' => 0,
            'cookies_used_in_search_count' => 0,
            'cookies_after_login_count' => 0,
            'results_table_found' => false,
            'parsed_results_count' => 0,
            'error_reason' => '',
            'sample_results' => [],
            'debug_html_file' => '',
        ];

        if ($partNumber === '') {
            $diagnostics['error_reason'] = 'invalid_part_number';
            return $diagnostics;
        }

        $loginDiagnostics = $this->run_login_diagnostic();
        $diagnostics['login_success'] = (bool) ($loginDiagnostics['login_success'] ?? false);
        $diagnostics['cookies_after_login_count'] = (int) ($loginDiagnostics['cookies_after_login_count'] ?? 0);
        if (!$diagnostics['login_success']) {
            $diagnostics['error_reason'] = (string) ($loginDiagnostics['error_reason'] ?? 'login_failed');
            return $diagnostics;
        }

        $searchRequest = $this->prepare_search_request($partNumber);
        if (($searchRequest['success'] ?? false) !== true) {
            $diagnostics['error_reason'] = (string) ($searchRequest['error_reason'] ?? 'search_form_prepare_failed');
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        $diagnostics['search_method'] = (string) ($searchRequest['method'] ?? $diagnostics['search_method']);
        $diagnostics['search_url'] = (string) ($searchRequest['request_url'] ?? $searchUrl);
        $diagnostics['search_field_name_used'] = (string) ($searchRequest['field_name'] ?? '');
        $diagnostics['search_form_fields_detected'] = (array) ($searchRequest['field_names'] ?? []);
        $diagnostics['search_hidden_fields_detected'] = (array) ($searchRequest['hidden_field_names'] ?? []);
        $diagnostics['cookies_used_in_search_count'] = count($this->cookies);

        $responseMeta = $this->request_with_redirects(
            (string) ($searchRequest['method'] ?? 'POST'),
            (string) ($searchRequest['request_url'] ?? $searchUrl),
            (array) ($searchRequest['args'] ?? [])
        );

        if (is_wp_error($responseMeta['response'] ?? null)) {
            $diagnostics['error_reason'] = 'search_request_failed';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        $response = (array) ($responseMeta['response'] ?? []);
        $this->captureCookies($response);
        $diagnostics['search_http_code'] = (int) wp_remote_retrieve_response_code($response);
        $diagnostics['redirect_count'] = (int) ($responseMeta['redirect_count'] ?? 0) + (int) ($searchRequest['prefetch_redirect_count'] ?? 0);
        $diagnostics['final_search_url'] = (string) ($responseMeta['final_url'] ?? $diagnostics['search_url']);
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);
        $diagnostics['search_response_length'] = strlen($body);
        $bodyLower = strtolower($body);
        $diagnostics['search_contains_lista_produktow'] = str_contains($bodyLower, 'lista produktów') || str_contains($bodyLower, 'lista produktow');
        $diagnostics['search_contains_do_koszyka'] = str_contains($bodyLower, 'do koszyka');
        $diagnostics['search_contains_pn'] = str_contains(strtoupper($body), strtoupper($partNumber));
        $diagnostics['search_contains_table'] = str_contains($bodyLower, '<table');
        $diagnostics['debug_html_file'] = $this->save_search_debug_html($body);
        $parsed = $this->parseSearchResult($body, $contentType, $partNumber);

        if ($parsed === null) {
            $diagnostics['error_reason'] = $diagnostics['search_contains_table'] ? 'table_not_matching_expected_structure' : 'results_not_found_or_unparsed';
            $this->log_debug($diagnostics);
            return $diagnostics;
        }

        $items = (array) ($parsed['items'] ?? []);
        $diagnostics['results_table_found'] = true;
        $diagnostics['parsed_results_count'] = count($items);
        $diagnostics['sample_results'] = $this->build_sample_results($items, $marginPercent);
        $this->log_debug($diagnostics);

        return $diagnostics;
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
            'cookies' => [],
        ];

        $args = wp_parse_args($args, $defaults);
        $args['cookies'] = array_values($this->cookies);

        if (strtoupper($method) === 'POST') {
            return wp_remote_post($url, $args);
        }

        return wp_remote_get($url, $args);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function captureCookies(array $response): void
    {
        $setCookie = wp_remote_retrieve_header($response, 'set-cookie');
        if (!empty($setCookie)) {
            $headers = is_array($setCookie) ? $setCookie : [$setCookie];
            foreach ($headers as $headerLine) {
                $this->setCookieHeaders[] = (string) $headerLine;
            }
        }

        $cookies = wp_remote_retrieve_cookies($response);
        foreach ($cookies as $cookie) {
            if (!$cookie instanceof WP_Http_Cookie || $cookie->name === '') {
                continue;
            }
            $this->cookies[$cookie->name] = $cookie;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function prepare_search_request(string $partNumber): array
    {
        $searchUrl = $this->absoluteUrl((string) $this->runtime['search_path']);
        $prefetch = $this->request_with_redirects('GET', $searchUrl, [
            'headers' => ['Referer' => $searchUrl],
        ]);
        if (is_wp_error($prefetch['response'] ?? null)) {
            return [
                'success' => false,
                'error_reason' => 'search_form_page_request_failed',
            ];
        }

        $response = (array) ($prefetch['response'] ?? []);
        $this->captureCookies($response);
        $body = (string) wp_remote_retrieve_body($response);
        $form = $this->extractSearchForm($body, (string) ($prefetch['final_url'] ?? $searchUrl));
        $method = strtoupper((string) ($form['method'] ?? $this->runtime['search_http_method']));
        $fieldName = (string) ($form['part_field'] ?? apply_filters('gp_partscentrum_search_field', 'pn'));
        $requestUrl = (string) ($form['action'] ?? $searchUrl);

        $payload = (array) ($form['hidden'] ?? []);
        $payload[$fieldName] = $partNumber;
        $payload['pn'] = $partNumber;
        $payload['part_number'] = $partNumber;
        $payload['partNumber'] = $partNumber;

        $args = [
            'headers' => [
                'Referer' => (string) ($prefetch['final_url'] ?? $searchUrl),
            ],
            'cookies' => array_values($this->cookies),
        ];

        if ($method === 'POST') {
            $args['body'] = $payload;
        } else {
            $requestUrl = add_query_arg($payload, $requestUrl);
        }

        return [
            'success' => true,
            'method' => $method,
            'request_url' => $requestUrl,
            'field_name' => $fieldName,
            'field_names' => (array) ($form['field_names'] ?? []),
            'hidden_field_names' => array_keys((array) ($form['hidden'] ?? [])),
            'args' => $args,
            'prefetch_redirect_count' => (int) ($prefetch['redirect_count'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array{response:array<string,mixed>|WP_Error,redirect_count:int,final_url:string}
     */
    private function request_with_redirects(string $method, string $url, array $args = []): array
    {
        $currentUrl = $url;
        $redirectCount = 0;
        $currentMethod = strtoupper($method);
        $currentArgs = $args;

        for ($i = 0; $i < 6; $i++) {
            $currentArgs['redirection'] = 0;
            $response = $this->request($currentMethod, $currentUrl, $currentArgs);
            if (is_wp_error($response)) {
                return [
                    'response' => $response,
                    'redirect_count' => $redirectCount,
                    'final_url' => $currentUrl,
                ];
            }

            $this->captureCookies($response);
            $code = (int) wp_remote_retrieve_response_code($response);
            $location = (string) wp_remote_retrieve_header($response, 'location');
            if ($code >= 300 && $code < 400 && $location !== '') {
                $redirectCount++;
                $currentUrl = $this->absoluteUrl($location);
                $currentMethod = 'GET';
                $currentArgs = [
                    'headers' => [
                        'Referer' => $url,
                    ],
                ];
                continue;
            }

            return [
                'response' => $response,
                'redirect_count' => $redirectCount,
                'final_url' => $currentUrl,
            ];
        }

        return [
            'response' => new WP_Error('too_many_redirects', 'Zbyt wiele przekierowań.'),
            'redirect_count' => $redirectCount,
            'final_url' => $currentUrl,
        ];
    }

    /**
     * @return array{action:string,method:string,part_field:string,hidden:array<string,string>,field_names:array<int,string>}|null
     */
    private function extractSearchForm(string $html, string $fallbackAction): ?array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $forms = $xpath->query('//form');
        if (!$forms instanceof DOMNodeList || $forms->length === 0) {
            return null;
        }

        foreach ($forms as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }

            $inputs = $xpath->query('.//input[@name]', $form);
            if (!$inputs instanceof DOMNodeList || $inputs->length === 0) {
                continue;
            }

            $fieldNames = [];
            $hidden = [];
            $partField = '';
            foreach ($inputs as $input) {
                if (!$input instanceof DOMElement) {
                    continue;
                }
                $name = (string) ($input->getAttribute('name') ?? '');
                if ($name === '') {
                    continue;
                }
                $fieldNames[] = $name;
                $type = strtolower((string) ($input->getAttribute('type') ?? 'text'));
                if ($type === 'hidden') {
                    $hidden[$name] = (string) ($input->getAttribute('value') ?? '');
                }
                if ($partField === '' && in_array($type, ['text', 'search'], true)) {
                    $partField = $name;
                }
                if ($partField === '' && (str_contains(strtolower($name), 'pn') || str_contains(strtolower($name), 'part'))) {
                    $partField = $name;
                }
            }

            if ($partField === '') {
                continue;
            }

            $action = (string) ($form->getAttribute('action') ?? '');
            $method = strtoupper((string) ($form->getAttribute('method') ?: 'POST'));

            return [
                'action' => $action !== '' ? $this->absoluteUrl($action) : $fallbackAction,
                'method' => $method,
                'part_field' => $partField,
                'hidden' => $hidden,
                'field_names' => array_values(array_unique($fieldNames)),
            ];
        }

        return null;
    }

    private function save_search_debug_html(string $html): string
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return '';
        }

        $baseDir = (string) ($uploads['basedir'] ?? '');
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        if ($baseDir === '' || $baseUrl === '') {
            return '';
        }

        wp_mkdir_p($baseDir);
        $filePath = trailingslashit($baseDir) . 'partscentrum-search-debug.html';
        file_put_contents($filePath, $html);

        return trailingslashit($baseUrl) . 'partscentrum-search-debug.html';
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
            'login_url' => (string) ($context['login_url'] ?? ''),
            'login_http_code' => (int) ($context['login_http_code'] ?? 0),
            'form_found' => (bool) ($context['form_found'] ?? false),
            'redirect_detected' => (bool) ($context['redirect_detected'] ?? false),
            'user_logged_in' => (bool) ($context['user_logged_in'] ?? false),
            'login_success' => (bool) ($context['login_success'] ?? false),
            'cookies_after_login_count' => (int) ($context['cookies_after_login_count'] ?? 0),
            'search_http_code' => (int) ($context['search_http_code'] ?? 0),
            'search_response_length' => (int) ($context['search_response_length'] ?? 0),
            'search_contains_lista_produktow' => (bool) ($context['search_contains_lista_produktow'] ?? false),
            'search_contains_do_koszyka' => (bool) ($context['search_contains_do_koszyka'] ?? false),
            'search_contains_pn' => (bool) ($context['search_contains_pn'] ?? false),
            'search_contains_table' => (bool) ($context['search_contains_table'] ?? false),
            'search_url' => (string) ($context['search_url'] ?? ''),
            'final_search_url' => (string) ($context['final_search_url'] ?? ''),
            'redirect_count' => (int) ($context['redirect_count'] ?? 0),
            'search_method' => (string) ($context['search_method'] ?? ''),
            'search_field_name_used' => (string) ($context['search_field_name_used'] ?? ''),
            'cookies_used_in_search_count' => (int) ($context['cookies_used_in_search_count'] ?? 0),
            'search_form_fields_detected' => array_values(array_map('sanitize_key', (array) ($context['search_form_fields_detected'] ?? []))),
            'search_hidden_fields_detected' => array_values(array_map('sanitize_key', (array) ($context['search_hidden_fields_detected'] ?? []))),
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
        $this->store_admin_log($level, $message, $redacted);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_recent_logs(int $limit = 30): array
    {
        $logs = get_option(self::ADMIN_LOG_OPTION, []);
        if (!is_array($logs)) {
            return [];
        }

        return array_slice($logs, 0, max(1, $limit));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function build_sample_results(array $items, float $marginPercent): array
    {
        $sample = [];
        foreach (array_slice($items, 0, 3) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $gross = (float) ($row['supplier_price_gross_discounted'] ?? 0);
            $sample[] = [
                'name' => (string) ($row['supplier_title'] ?? ''),
                'pn' => (string) ($row['supplier_part_number'] ?? ''),
                'gross_discounted' => (float) wc_format_decimal($gross, 2),
                'price_with_margin' => (float) wc_format_decimal($gross * (1 + ($marginPercent / 100)), 2),
                'availability' => (string) ($row['availability'] ?? ''),
            ];
        }

        return $sample;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function store_admin_log(string $level, string $message, array $context): void
    {
        $logs = get_option(self::ADMIN_LOG_OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $logs = array_values($logs);
        array_unshift($logs, [
            'timestamp' => current_time('mysql'),
            'action' => str_contains(strtolower($message), 'diagnostics') ? 'diagnostics' : 'runtime',
            'status' => $level,
            'message' => $message,
            'error' => (string) ($context['error_reason'] ?? $context['error'] ?? ''),
            'http_code' => (int) ($context['search_http_code'] ?? $context['login_http_code'] ?? 0),
            'results_count' => (int) ($context['parsed_results_count'] ?? 0),
        ]);

        if (count($logs) > self::ADMIN_LOG_LIMIT) {
            $logs = array_slice($logs, 0, self::ADMIN_LOG_LIMIT);
        }

        update_option(self::ADMIN_LOG_OPTION, $logs, false);
    }
}
