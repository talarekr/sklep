<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\OvokoMarketplaceCategorySuggestionsClient;

require_once dirname(__DIR__) . '/src/Services/OvokoMarketplaceCategorySuggestionsClient.php';

function gpswiss_market_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = [
    '@context' => '/api/contexts/OvokoMarketplaceCategorySuggestion',
    '@id' => '/api/v1/ovoko-marketplace-category-suggestions',
    '@type' => 'hydra:Collection',
    'hydra:totalItems' => 2.0,
    'hydra:member' => [
        [
            'id' => 278,
            'parent' => [
                'id' => 274,
                'parent' => ['id' => 250, 'parent' => null, 'title' => 'Silnik i osprzęt', 'level' => 1, 'dimensions' => null],
                'title' => 'Turbosprężarki i inne części',
                'level' => 2,
                'dimensions' => null,
            ],
            'title' => 'Turbina',
            'level' => 3,
            'dimensions' => ['height' => 38.0, 'width' => 40.0, 'length' => 40.0, 'weight' => 12.5],
        ],
        [
            'id' => 280,
            'parent' => [
                'id' => 274,
                'parent' => ['id' => 250, 'parent' => null, 'title' => 'Silnik i osprzęt', 'level' => 1, 'dimensions' => null],
                'title' => 'Turbosprężarki i inne części',
                'level' => 2,
                'dimensions' => null,
            ],
            'title' => 'Część układu próżniowego turbosprężarki',
            'level' => 3,
            'dimensions' => ['height' => 0.0, 'width' => 0.0, 'length' => 0.0, 'weight' => 0.4],
        ],
        ['id' => 999, 'title' => 'Ignored level 2', 'level' => 2],
    ],
];

$client = new OvokoMarketplaceCategorySuggestionsClient();
$result = $client->parse_response($fixture);

$suggestions = $result['suggestions'];
$selected = $result['selected_suggestion'];

gpswiss_market_assert($result['status'] === 'completed', 'Parsed fixture should complete.');
gpswiss_market_assert(count($suggestions) === 2, 'Only two valid level-3 suggestions should be stored.');
gpswiss_market_assert((int) $selected['category_id'] === 278, '06K145654L should select first category 278.');
gpswiss_market_assert($selected['category_name'] === 'Turbina', 'Selected category should be Turbina.');
gpswiss_market_assert($selected['category_path'] === 'Silnik i osprzęt > Turbosprężarki i inne części > Turbina', 'Category path should include parents.');
gpswiss_market_assert((float) $selected['dimensions']['height'] === 38.0, 'Height should parse.');
gpswiss_market_assert((float) $selected['dimensions']['width'] === 40.0, 'Width should parse.');
gpswiss_market_assert((float) $selected['dimensions']['length'] === 40.0, 'Length should parse.');
gpswiss_market_assert((float) $selected['dimensions']['weight'] === 12.5, 'Weight should parse.');
gpswiss_market_assert($suggestions[1]['category_id'] === 280, 'Second suggestion should be stored for review.');

$unsafe = (new OvokoMarketplaceCategorySuggestionsClient([
    'ovoko_marketplace_category_suggestions_base_url' => 'https://gregorswiss.rrr.lt',
    'ovoko_marketplace_category_suggestions_auth_mode' => 'bearer_token_manual_diagnostic_only',
]))->predict_by_part_code('06K145654L');

gpswiss_market_assert($unsafe['status'] === 'endpoint_requires_panel_auth', 'Manual browser bearer mode must not complete as production-safe.');
gpswiss_market_assert($unsafe['safe_to_call_from_wordpress'] === false, 'Manual browser bearer mode is not safe for WordPress automation.');
gpswiss_market_assert($unsafe['production_automation_allowed'] === false, 'Manual browser bearer mode must not allow production automation.');

echo "OvokoMarketplaceCategorySuggestionsClientTest passed\n";
