<?php

namespace GPSEbayFitmentSync\Services;

use GPSEbayFitmentSync\DTO\TecDocLookupResult;
use GPSEbayFitmentSync\Interfaces\TecDocLookupServiceInterface;

final class NullTecDocLookupService implements TecDocLookupServiceInterface
{
    public function lookup_by_oem(string $oem, array $context = []): TecDocLookupResult
    {
        return new TecDocLookupResult([
            'oem' => $oem,
            'source' => 'stub_no_live_tecdoc_api',
            'ktype_list' => [],
            'confidence' => 'low',
            'status' => 'pending',
            'raw_summary' => ['message' => 'TecDoc lookup is intentionally stubbed in the preparation/audit phase.'],
        ]);
    }
}
