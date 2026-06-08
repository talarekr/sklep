<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\RrrApiClient;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';

$GLOBALS['gpswiss_rrr_status_test_requests'] = [];
$GLOBALS['gpswiss_rrr_status_test_response_body'] = '';
$GLOBALS['gpswiss_rrr_status_test_response_code'] = 200;
$GLOBALS['gpswiss_rrr_status_test_writes'] = [];

class GPSwissRrrStatusTestWpError
{
    public function get_error_code(): string { return 'test_error'; }
    public function get_error_message(): string { return 'test error'; }
}

function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function is_wp_error($value): bool { return $value instanceof GPSwissRrrStatusTestWpError; }
function wp_remote_post(string $url, array $args): array
{
    $GLOBALS['gpswiss_rrr_status_test_requests'][] = ['method' => 'POST', 'url' => $url, 'args' => $args];
    return ['response' => ['code' => $GLOBALS['gpswiss_rrr_status_test_response_code']], 'body' => $GLOBALS['gpswiss_rrr_status_test_response_body']];
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_rrr_status_test_writes'][] = ['update_post_meta', $id, $key, $value]; return true; }
function update_option(string $key, mixed $value, ?bool $autoload = null): bool { $GLOBALS['gpswiss_rrr_status_test_writes'][] = ['update_option', $key, $value, $autoload]; return true; }

function gpswiss_status_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gpswiss_status_run(string $name, callable $test): void
{
    $GLOBALS['gpswiss_rrr_status_test_requests'] = [];
    $GLOBALS['gpswiss_rrr_status_test_writes'] = [];
    $GLOBALS['gpswiss_rrr_status_test_response_code'] = 200;
    $GLOBALS['gpswiss_rrr_status_test_response_body'] = wp_json_encode([
        'status_code' => 'R200',
        'data' => [
            ['id' => 0, 'name' => 'In stock / Na stanie'],
            ['id' => 1, 'name' => 'Reserved / Zarezerwowano'],
            ['id' => 2, 'name' => 'Sold out / Sprzedano'],
            ['id' => 3, 'name' => 'Returned / Zwrot'],
            ['id' => 4, 'name' => 'Written off / Wycofany'],
        ],
    ]);
    $test(new RrrApiClient([
        'rrr_api_base_url' => 'https://api.rrr.test',
        'rrr_api_username' => 'user',
        'rrr_api_password' => 'pass',
        'rrr_api_user_token' => 'token',
    ]));
    echo "PASS {$name}\n";
}

function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }

gpswiss_status_run('probe calls only read endpoint and performs no writes', function (RrrApiClient $client): void {
    $result = $client->read_part_statuses();
    gpswiss_status_assert($result['endpoint_used'] === '/get/part_status', 'Unexpected endpoint used.');
    gpswiss_status_assert($result['read_endpoint_family'] === '/get', 'Endpoint family must be read-only /get.');
    gpswiss_status_assert(count($GLOBALS['gpswiss_rrr_status_test_requests']) === 1, 'Exactly one HTTP request expected.');
    $url = (string) $GLOBALS['gpswiss_rrr_status_test_requests'][0]['url'];
    gpswiss_status_assert(str_contains($url, '/get/part_status'), 'Read endpoint not called.');
    gpswiss_status_assert(!str_contains($url, '/crm/importPart') && !str_contains($url, '/crm/updatePart') && !str_contains($url, '/crm/changePartStatus'), 'Write endpoint must not be called.');
    gpswiss_status_assert($GLOBALS['gpswiss_rrr_status_test_writes'] === [], 'Client probe must not perform Woo/option writes.');
    gpswiss_status_assert($result['no_ovoko_write'] === true && $result['no_woo_write'] === true, 'No-write flags missing.');
});

gpswiss_status_run('parsed status list is displayed in result shape', function (RrrApiClient $client): void {
    $result = $client->read_part_statuses();
    gpswiss_status_assert($result['ok'] === true, 'Probe should be ok for valid fixture.');
    gpswiss_status_assert($result['http_status'] === 200, 'HTTP status missing.');
    gpswiss_status_assert($result['status_count'] === 5, 'Status count mismatch.');
    gpswiss_status_assert($result['statuses'][0]['id'] === '0', 'Status ID missing.');
    gpswiss_status_assert($result['statuses'][0]['name'] === 'In stock / Na stanie', 'Status name missing.');
    gpswiss_status_assert(isset($result['parsed_response']['data']), 'Parsed response missing.');
    gpswiss_status_assert($result['raw_response'] !== '', 'Raw response missing.');
    gpswiss_status_assert($result['checked_at'] !== '', 'checked_at missing.');
});

gpswiss_status_run('operational stock and sales statuses do not confirm publication visibility', function (RrrApiClient $client): void {
    $result = $client->read_part_statuses();
    gpswiss_status_assert($result['candidate_draft_statuses'] === [], 'Operational statuses must not become draft candidates.');
    gpswiss_status_assert($result['candidate_hidden_statuses'] === [], 'Operational statuses must not become hidden candidates.');
    gpswiss_status_assert(count($result['operational_stock_sales_statuses']) === 5, 'Operational status classification missing.');
    gpswiss_status_assert($result['interpretation_summary']['status_catalog_scope'] === 'operational_stock_sales_lifecycle', 'Status catalog should be marked operational.');
    gpswiss_status_assert($result['interpretation_summary']['part_status_is_publication_visibility_catalog'] === false, 'Part status catalog must not be treated as publication visibility.');
    gpswiss_status_assert($result['interpretation_summary']['draft_unpublished_behavior_confirmed'] === false, 'Draft/unpublished behavior must remain unconfirmed.');
});

gpswiss_status_run('unknown status behavior blocks future live readiness', function (RrrApiClient $client): void {
    $GLOBALS['gpswiss_rrr_status_test_response_body'] = wp_json_encode(['status_code' => 'R200', 'data' => [['id' => 9, 'name' => 'Mystery']]]);
    $result = $client->read_part_statuses();
    gpswiss_status_assert($result['status_count'] === 1, 'Mystery status should parse.');
    gpswiss_status_assert($result['candidate_draft_statuses'] === [], 'Mystery status must not become draft candidate.');
    gpswiss_status_assert($result['candidate_hidden_statuses'] === [], 'Mystery status must not become hidden candidate.');
    gpswiss_status_assert($result['unknown_statuses'][0]['interpretation'] === 'unknown', 'Mystery status should be explicitly marked unknown.');
    gpswiss_status_assert($result['interpretation_summary']['confirmation_required'] === true, 'Unknown status behavior must remain confirmation-required.');
    gpswiss_status_assert($result['interpretation_summary']['safe_non_public_status_value'] === null, 'Unknown status must not select safe value.');
});
