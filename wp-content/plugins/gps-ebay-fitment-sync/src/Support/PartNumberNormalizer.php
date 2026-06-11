<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Support;

final class PartNumberNormalizer
{
    public function normalize(string $partNumber): string
    {
        $partNumber = strtoupper(trim($partNumber));
        $partNumber = preg_replace('/[\s\-.]+/', '', $partNumber) ?: '';
        $partNumber = preg_replace('/[^A-Z0-9]/', '', $partNumber) ?: '';

        return $partNumber;
    }
}
