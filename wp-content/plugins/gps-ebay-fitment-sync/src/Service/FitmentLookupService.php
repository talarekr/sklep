<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class FitmentLookupService
{
    private Database $database;
    private ApifyClient $client;
    private PartNumberNormalizer $normalizer;
    private Settings $settings;

    public function __construct(Database $database, ApifyClient $client, PartNumberNormalizer $normalizer, Settings $settings)
    {
        $this->database = $database;
        $this->client = $client;
        $this->normalizer = $normalizer;
        $this->settings = $settings;
    }

    public function lookup(string $partNumber, bool $save = false, bool $forceLive = false): array
    {
        $normalized = $this->normalizer->normalize($partNumber);
        if ($normalized === '') {
            return $this->result($partNumber, $normalized, 'error', [], [], ['Part number is empty after normalization.'], false, null, $forceLive);
        }

        $existingPart = $this->database->get_part_cache($normalized);
        if (!$forceLive && $existingPart) {
            $cached = $this->database->get_cached_result($normalized);
            if ($cached) {
                return $this->cached_result($partNumber, $normalized, $cached, $forceLive);
            }
        }

        $articleResponse = $this->client->search_articles($normalized);
        if (!$articleResponse['success']) {
            $live = $this->result($partNumber, $normalized, 'error', [], [], [(string) $articleResponse['error']], false, $existingPart ? (int) $existingPart['id'] : null, $forceLive);
            if ($save) {
                $partId = $this->database->save_lookup($partNumber, $live);
                $live['saved'] = $partId > 0;
                $live['cache_part_cache_id'] = $partId > 0 ? $partId : null;
            }
            return $live;
        }

        $articles = $articleResponse['articles'];
        if (!$articles) {
            $live = $this->result($partNumber, $normalized, 'not_found', [], [], ['No TecDoc articles found for this OEM/part number.'], false, $existingPart ? (int) $existingPart['id'] : null, $forceLive);
            if ($save) {
                $partId = $this->database->save_lookup($partNumber, $live);
                $live['saved'] = $partId > 0;
                $live['cache_part_cache_id'] = $partId > 0 ? $partId : null;
            }
            return $live;
        }

        $vehicles = [];
        $errors = [];
        foreach ($articles as $article) {
            $vehicleResponse = $this->client->compatible_vehicles((string) $article['articleNo'], (int) $article['supplierId']);
            if (!$vehicleResponse['success']) {
                $errors[] = sprintf('Step 2 failed for article %s / supplier %d: %s', (string) $article['articleNo'], (int) $article['supplierId'], (string) $vehicleResponse['error']);
                continue;
            }
            foreach ($vehicleResponse['vehicles'] as $vehicle) {
                $vehicle['_articleNo'] = (string) $article['articleNo'];
                $vehicle['_supplierId'] = (int) $article['supplierId'];
                $vehicles[] = $vehicle;
            }
        }

        $status = $vehicles ? 'found' : ($errors ? 'error' : 'not_found');
        if (!$vehicles && !$errors) {
            $errors[] = 'TecDoc article was found, but compatibleCars was empty.';
        }

        $live = $this->result($partNumber, $normalized, $status, $articles, $vehicles, $errors, false, $existingPart ? (int) $existingPart['id'] : null, $forceLive);
        if ($save) {
            $partId = $this->database->save_lookup($partNumber, $live);
            $live['saved'] = $partId > 0;
            $live['cache_part_cache_id'] = $partId > 0 ? $partId : null;
        }

        return $live;
    }

    public function backfill(array $partNumbers): array
    {
        $limit = (int) $this->settings->get('batch_size');
        $processed = [];
        foreach (array_slice(array_values(array_unique($partNumbers)), 0, $limit) as $partNumber) {
            $processed[] = $this->lookup((string) $partNumber, true, false);
        }

        return ['processed' => $processed, 'limit' => $limit];
    }

    private function cached_result(string $raw, string $normalized, array $cached, bool $forceLive): array
    {
        return [
            'part_number_raw' => $raw,
            'part_number_normalized' => $normalized,
            'status' => (string) $cached['part_cache']['status'],
            'articles' => $cached['articles'],
            'vehicles' => $cached['vehicles'],
            'unique_vehicle_ids' => $cached['unique_vehicle_ids'],
            'errors' => $cached['part_cache']['error_message'] ? explode("\n", (string) $cached['part_cache']['error_message']) : [],
            'from_cache' => true,
            'saved' => false,
            'cache_lookup_key' => $normalized,
            'cache_part_cache_id' => (int) $cached['part_cache']['id'],
            'cache_hit' => true,
            'force_live' => $forceLive,
        ];
    }

    private function result(string $raw, string $normalized, string $status, array $articles, array $vehicles, array $errors, bool $fromCache, ?int $partCacheId, bool $forceLive): array
    {
        $vehicleIds = [];
        foreach ($vehicles as $vehicle) {
            if (isset($vehicle['vehicleId'])) {
                $vehicleIds[] = (string) $vehicle['vehicleId'];
            } elseif (isset($vehicle['vehicle_id'])) {
                $vehicleIds[] = (string) $vehicle['vehicle_id'];
            }
        }

        return [
            'part_number_raw' => $raw,
            'part_number_normalized' => $normalized,
            'status' => $status,
            'articles' => $articles,
            'vehicles' => $vehicles,
            'unique_vehicle_ids' => array_values(array_unique(array_filter($vehicleIds))),
            'errors' => $errors,
            'from_cache' => $fromCache,
            'saved' => false,
            'cache_lookup_key' => $normalized,
            'cache_part_cache_id' => $partCacheId,
            'cache_hit' => $fromCache,
            'force_live' => $forceLive,
        ];
    }
}
