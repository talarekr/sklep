<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Support\PartNumberCandidateValidator;
use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class FitmentLookupService
{
    private Database $database;
    private ApifyClient $client;
    private PartNumberNormalizer $normalizer;
    private Settings $settings;
    private PartNumberCandidateValidator $validator;

    public function __construct(Database $database, ApifyClient $client, PartNumberNormalizer $normalizer, Settings $settings, ?PartNumberCandidateValidator $validator = null)
    {
        $this->database = $database;
        $this->client = $client;
        $this->normalizer = $normalizer;
        $this->settings = $settings;
        $this->validator = $validator ?: new PartNumberCandidateValidator($normalizer);
    }

    public function lookup(string $partNumber, bool $save = false, bool $forceLive = false): array
    {
        $validation = $this->validator->validate($partNumber);
        $normalized = (string) $validation['normalized'];
        if (empty($validation['accepted'])) {
            return $this->result($partNumber, $normalized, 'rejected', [], [], ['Rejected before lookup: ' . (string) $validation['rejection_reason']], false, null, $forceLive);
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
                $live['save_debug'] = $this->database->last_save_debug();
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
                $live['save_debug'] = $this->database->last_save_debug();
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
            $live['save_debug'] = $this->database->last_save_debug();
        }

        return $live;
    }

    public function backfill(array $partNumbers): array
    {
        $limit = (int) $this->settings->get('batch_size');
        $processed = [];
        $rejected = [];
        $seen = [];
        $counters = [
            'accepted_lookup_candidates' => 0,
            'rejected_before_lookup' => 0,
            'skipped_cached' => 0,
            'apify_lookup_attempted' => 0,
            'found' => 0,
            'not_found' => 0,
            'errors' => 0,
        ];

        foreach ($partNumbers as $partNumber) {
            foreach ($this->validator->candidates((string) $partNumber) as $candidate) {
                if (empty($candidate['accepted'])) {
                    $counters['rejected_before_lookup']++;
                    $rejected[] = $candidate;
                    continue;
                }

                $normalized = (string) $candidate['normalized'];
                if (isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $counters['accepted_lookup_candidates']++;

                if (count($processed) >= $limit) {
                    continue 2;
                }

                $cached = $this->database->get_cached_result($normalized);
                if ($cached) {
                    $counters['skipped_cached']++;
                    $result = $this->cached_result((string) $candidate['raw'], $normalized, $cached, false);
                } else {
                    $counters['apify_lookup_attempted']++;
                    $result = $this->lookup((string) $candidate['raw'], true, false);
                }

                if ($result['status'] === 'found') {
                    $counters['found']++;
                } elseif ($result['status'] === 'not_found') {
                    $counters['not_found']++;
                } elseif ($result['status'] === 'error' || $result['status'] === 'rejected') {
                    $counters['errors']++;
                }

                $processed[] = $result;
            }
        }

        return array_merge(['processed' => $processed, 'rejected' => $rejected, 'limit' => $limit], $counters);
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
            'save_debug' => [],
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
            'save_debug' => [],
        ];
    }
}
