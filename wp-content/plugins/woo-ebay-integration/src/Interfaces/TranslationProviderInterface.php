<?php

namespace WEI\Interfaces;

interface TranslationProviderInterface
{
    public function is_configured(): bool;

    /**
     * @param \WC_Product $product
     * @param array<string,mixed> $context
     * @return array{title_de:string,description_de:string}
     */
    public function translate_product_content(\WC_Product $product, array $context): array;

    public function provider_key(): string;
}
