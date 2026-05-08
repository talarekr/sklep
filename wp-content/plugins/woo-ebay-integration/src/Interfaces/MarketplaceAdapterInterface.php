<?php

namespace WEI\Interfaces;

interface MarketplaceAdapterInterface
{
    public function export_product(int $product_id, ?int $variation_id = null, bool $forcePublish = false): array;

    public function sync_stock(int $product_id, ?int $variation_id = null): array;

    public function import_orders(array $query = []): array;

    public function readiness_check(): array;
}
