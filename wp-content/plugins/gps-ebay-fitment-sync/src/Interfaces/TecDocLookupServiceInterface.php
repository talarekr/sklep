<?php

namespace GPSEbayFitmentSync\Interfaces;

use GPSEbayFitmentSync\DTO\TecDocLookupResult;

interface TecDocLookupServiceInterface
{
    public function lookup_by_oem(string $oem, array $context = []): TecDocLookupResult;
}
