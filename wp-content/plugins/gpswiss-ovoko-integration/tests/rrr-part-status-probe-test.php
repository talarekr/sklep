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
            ['id' => 1, 'name' => 'Available', 'visible' => true, 'active' => true],
            ['id' => 2, 'name' => 'Sold', 'sold' => true],
            ['id' => 3, 'name' => 'Draft review'],
            ['id' => 4, 'name' => 'Warehouse hidden', 'visible' => false],
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
    gpswiss_status_assert($result['status_count'] === 4, 'Status count mismatch.');
    gpswiss_status_assert($result['statuses'][0]['id'] === '1', 'Status ID missing.');
    gpswiss_status_assert($result['statuses'][0]['name'] === 'Available', 'Status name missing.');
    gpswiss_status_assert(isset($result['parsed_response']['data']), 'Parsed response missing.');
    gpswiss_status_assert($result['raw_response'] !== '', 'Raw response missing.');
    gpswiss_status_assert($result['checked_at'] !== '', 'checked_at missing.');
});

gpswiss_status_run('label-only draft candidate is not confirmed', function (RrrApiClient $client): void {
    $result = $client->read_part_statuses();
    gpswiss_status_assert($result['candidate_draft_statuses'][0]['id'] === '3', 'Draft candidate missing.');
    gpswiss_status_assert($result['candidate_draft_statuses'][0]['interpretation'] === 'inferred_from_label', 'Label-only draft must be inferred only.');
    gpswiss_status_assert($result['candidate_draft_statuses'][0]['confidence'] !== 'high', 'Label-only draft must not be high-confidence confirmed.');
});

gpswiss_status_run('explicit hidden flag is confirmed but live readiness still requires confirmation', function (RrrApiClient $client): void {
    $result = $client->read_part_statuses();
    gpswiss_status_assert($result['candidate_hidden_statuses'][0]['id'] === '4', 'Hidden candidate missing.');
    gpswiss_status_assert($result['candidate_hidden_statuses'][0]['interpretation'] === 'confirmed_by_response', 'Explicit hidden/visible=false flag should be response-confirmed.');
    gpswiss_status_assert($result['interpretation_summary']['draft_unpublished_behavior_confirmed'] === true, 'Explicit non-public response signal should be noted.');
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
