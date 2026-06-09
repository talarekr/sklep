<?php

namespace GPSEbayFitmentSync\DTO;

final class TecDocLookupResult
{
    public $oem = '';
    public $source = '';
    public $ktype_list = [];
    public $confidence = 'low';
    public $status = 'pending';
    public $matched_article_numbers = [];
    public $manufacturer = '';
    public $raw_summary = [];
    public $error = '';

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
