<?php

namespace GPSwiss\Ovoko\Contracts;

interface OvokoConnectorInterface
{
    public function is_configured(): bool;

    public function get_base_url(): string;

    public function check_configuration(): array;

    public function list_supported_resources_from_static_analysis(): array;

    public function preview_fetch_part(string $partId): array;
}
