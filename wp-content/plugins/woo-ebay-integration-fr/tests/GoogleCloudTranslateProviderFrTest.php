<?php

declare(strict_types=1);

if (!defined('ENT_HTML5')) {
    define('ENT_HTML5', 48);
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        $GLOBALS['wei_fr_google_request'] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 200], 'body' => json_encode(['data' => ['translations' => [['translatedText' => 'Bonjour']]]])];
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value) { return false; }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}
if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode($text, $quote_style = ENT_QUOTES) { return html_entity_decode((string) $text, $quote_style, 'UTF-8'); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) { return (string) $text; }
}

require_once __DIR__ . '/../src/Interfaces/TranslationProviderInterface.php';
require_once __DIR__ . '/../src/Services/Logger.php';
require_once __DIR__ . '/../src/Services/Translation/GoogleCloudTranslateProvider.php';

use WEI_FR\Services\Logger;
use WEI_FR\Services\Translation\GoogleCloudTranslateProvider;

$provider = new GoogleCloudTranslateProvider([
    'translation_api_key' => 'AIza-test-secret',
    'translation_source_language' => 'pl',
    'translation_target_language' => 'de',
], new Logger());

$result = $provider->translate_texts(['Cześć'], 'pl', 'de', 'text');
$request = $GLOBALS['wei_fr_google_request'] ?? [];
$payload = json_decode((string) (($request['args']['body'] ?? '') ?: ''), true);

$failures = [];
if ($result !== ['Bonjour']) {
    $failures[] = 'Expected mocked translated text.';
}
if (($payload['source'] ?? '') !== 'pl') {
    $failures[] = 'Expected source language pl.';
}
if (($payload['target'] ?? '') !== 'fr') {
    $failures[] = 'Expected Google payload target to be forced to fr even if caller/settings request de.';
}
if (str_contains((string) ($request['args']['body'] ?? ''), 'AIza-test-secret')) {
    $failures[] = 'Expected raw API key to stay out of JSON request body.';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Google Cloud Translate FR provider tests passed\n";
