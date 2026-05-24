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
        $baseUrl = $this->get_base_url();
        $credentialsSet = !empty($this->settings['ovoko_supply_connector_api_key']) || !empty($this->settings['ovoko_supply_connector_token']);
        return [
            'base_url_set' => $baseUrl !== '',
            'credentials_set' => $credentialsSet,
            'integration_id_set' => !empty($this->settings['ovoko_integration_id']),
            'status' => ($baseUrl !== '' && $credentialsSet) ? 'configured_for_next_step' : 'waiting_for_ovoko_credentials_details',
            'message' => ($baseUrl !== '' && $credentialsSet)
                ? 'Configuration looks ready for controlled connector tests once Ovoko confirms integration details.'
                : 'Waiting for Ovoko credentials/details. No outbound product import requests are performed.',
        ];
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
