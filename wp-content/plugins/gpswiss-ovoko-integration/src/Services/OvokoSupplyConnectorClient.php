<?php

namespace GPSwiss\Ovoko\Services;

use GPSwiss\Ovoko\Contracts\OvokoConnectorInterface;

class OvokoSupplyConnectorClient implements OvokoConnectorInterface
{
    public function __construct(private array $settings)
    {
    }

    public function is_configured(): bool
    {
        return !empty($this->settings['ovoko_supply_connector_base_url']) && !empty($this->settings['ovoko_supply_connector_api_key']);
    }

    public function get_base_url(): string
    {
        return (string) ($this->settings['ovoko_supply_connector_base_url'] ?? '');
    }

    public function check_configuration(): array
    {
        $baseUrl = $this->normalize_base_url($this->get_base_url());
        $credentialsSet = !empty($this->settings['ovoko_supply_connector_api_key'])
            || !empty($this->settings['ovoko_supply_connector_token'])
            || (!empty($this->settings['ovoko_supply_connector_username']) && !empty($this->settings['ovoko_supply_connector_password']));

        $probes = $baseUrl === '' ? [] : $this->run_public_readiness_probes($baseUrl);
        $baseReachable = $this->has_successful_probe($probes, 'base');
        $docsReachable = $this->has_successful_probe($probes, 'docs');
        $indexReachable = $this->has_successful_probe($probes, 'index_json');

        return [
            'base_url_set' => $baseUrl !== '',
            'credentials_set' => $credentialsSet,
            'credentials_saved' => $credentialsSet,
            'integration_id_set' => !empty($this->settings['ovoko_integration_id']),
            'base_url_reachable' => $baseReachable,
            'docs_or_index_reachable' => $docsReachable || $indexReachable,
            'authenticated_endpoint_confirmed' => false,
            'status' => ($baseUrl !== '' && $credentialsSet)
                ? 'credentials_saved_endpoint_confirmation_needed'
                : 'waiting_for_ovoko_credentials_details',
            'message' => ($baseUrl !== '' && $credentialsSet)
                ? 'Credentials saved, endpoint for authenticated product access still needs confirmation. No import/product update actions executed.'
                : 'Waiting for Ovoko credentials/details. No outbound product import requests are performed.',
            'public_endpoint_probes' => $probes,
            'auth_test_strategy' => 'presence_only_until_ovoko_confirms_authenticated_endpoint',
        ];
    }

    private function normalize_base_url(string $baseUrl): string
    {
        return rtrim(trim($baseUrl), '/');
    }

    private function run_public_readiness_probes(string $baseUrl): array
    {
        $targets = [
            'base' => $baseUrl . '/',
            'docs' => $baseUrl . '/docs',
            'index_json' => $baseUrl . '/index.json',
        ];

        $results = [];
        foreach ($targets as $name => $url) {
            $response = wp_remote_get($url, ['timeout' => 8, 'redirection' => 3]);
            if (is_wp_error($response)) {
                $results[] = [
                    'probe' => $name,
                    'url' => $url,
                    'ok' => false,
                    'http_code' => null,
                    'error' => $response->get_error_code(),
                ];
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $results[] = [
                'probe' => $name,
                'url' => $url,
                'ok' => $code > 0 && $code < 500,
                'http_code' => $code,
            ];
        }

        return $results;
    }

    private function has_successful_probe(array $probes, string $name): bool
    {
        foreach ($probes as $probe) {
            if (($probe['probe'] ?? '') === $name && !empty($probe['ok'])) {
                return true;
            }
        }

        return false;
    }

    public function list_supported_resources_from_static_analysis(): array
    {
        return [
            'observed_resources' => [
                'Category', 'IntegrationAction', 'IntegrationSettings', 'WooCommerceIntegration', 'IntegrationWebhook',
                'WebhookSignedUrl', 'User', 'BaselinkerIntegration', 'BosabIntegration', 'IntegrationRemoval',
                'SaasWebhook', 'Baselinker', 'Bosab', 'Credentials',
            ],
            'notes' => [
                'Source: public API Platform index.json listing resource_name_collection.',
                'No verified simple GET /parts endpoint in the public index listing.',
                'Current plugin intentionally keeps fetch/import methods as preview placeholders.',
            ],
        ];
    }

    public function preview_fetch_part(string $partId): array
    {
        return [
            'ok' => false,
            'part_id' => $partId,
            'status' => 'not_implemented_waiting_for_ovoko_details',
            'message' => 'No confirmed public part endpoint in documentation. Placeholder only, no outbound request executed.',
        ];
    }
}
