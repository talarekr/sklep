<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Support;

final class PartNumberCandidateValidator
{
    private const MIN_LENGTH = 5;
    private const MAX_LENGTH = 24;

    private PartNumberNormalizer $normalizer;

    public function __construct(PartNumberNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidates(string $raw): array
    {
        $raw = trim($raw);
        $parts = $this->split_raw_candidates($raw);
        $plausibleParts = array_filter($parts, fn(string $part): bool => strlen($this->normalizer->normalize($part)) >= self::MIN_LENGTH);
        if (count($parts) > 1 && count($plausibleParts) >= 2) {
            $accepted = [];
            $rejected = [];
            foreach ($parts as $part) {
                $candidate = $this->validate_single($part, $raw);
                if (!empty($candidate['accepted'])) {
                    $candidate['raw'] = $part;
                    $candidate['source_raw'] = $raw;
                    $candidate['warnings'][] = 'split_from_multi_code_value';
                    $accepted[] = $candidate;
                } else {
                    $rejected[] = $candidate;
                }
            }

            if ($accepted && !$rejected) {
                return $accepted;
            }

            $joined = $this->normalizer->normalize($raw);
            return [[
                'accepted' => false,
                'raw' => $raw,
                'normalized' => $joined,
                'rejection_reason' => 'multi_code_value_contains_invalid_tokens',
                'warnings' => [],
                'rejected_tokens' => array_map(static fn(array $row): array => [
                    'raw' => (string) $row['raw'],
                    'normalized' => (string) $row['normalized'],
                    'rejection_reason' => (string) $row['rejection_reason'],
                ], $rejected),
            ]];
        }

        return [$this->validate_single($raw, $raw)];
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(string $raw): array
    {
        return $this->candidates($raw)[0];
    }

    /**
     * @return string[]
     */
    public function blocked_values(): array
    {
        $values = [
            'BRAK',
            'NONE',
            'NULL',
            'NA',
            'N/A',
            'FOTELE',
            'FOTEL',
            'KOMPLET',
            'ZESTAW',
            'TCB',
            'DXR',
            'WGDODAUDIOLCAR',
            'BTCARS33123',
            'FOTELFOTELEKOMPLETAUDIA38PLIFT5D',
        ];

        return array_values(array_unique(array_map(
            fn(string $value): string => $this->normalizer->normalize($value),
            apply_filters('gps_ebay_fitment_sync_blocked_part_number_values', $values)
        )));
    }

    /**
     * @return string[]
     */
    public function description_keywords(): array
    {
        $keywords = [
            'FOTEL',
            'FOTELE',
            'KOMPLET',
            'ZESTAW',
            'AUDIOLCAR',
            'BRAK',
        ];

        return array_values(array_unique(array_map(
            fn(string $value): string => $this->normalizer->normalize($value),
            apply_filters('gps_ebay_fitment_sync_part_number_description_keywords', $keywords)
        )));
    }

    /**
     * @return string[]
     */
    private function split_raw_candidates(string $raw): array
    {
        $parts = preg_split('/[\s,;\/]+/', trim($raw)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function validate_single(string $raw, string $sourceRaw): array
    {
        $normalized = $this->normalizer->normalize($raw);
        $warnings = [];
        $reject = function (string $reason) use ($raw, $normalized, $warnings): array {
            return [
                'accepted' => false,
                'raw' => $raw,
                'normalized' => $normalized,
                'rejection_reason' => $reason,
                'warnings' => $warnings,
            ];
        };

        if ($normalized === '') {
            return $reject('empty_after_normalization');
        }

        if (in_array($normalized, $this->blocked_values(), true)) {
            return $reject('blocked_value');
        }

        foreach ($this->description_keywords() as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return $reject('description_keyword_' . strtolower($keyword));
            }
        }

        if (strlen($normalized) < self::MIN_LENGTH) {
            return $reject('too_short');
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            return $reject('too_long');
        }

        if (!preg_match('/\d/', $normalized)) {
            return $reject('missing_digit');
        }

        if (!preg_match('/[A-Z]/', $normalized)) {
            $warnings[] = 'numeric_only_candidate';
        }

        $wordTokens = preg_split('/\s+/', trim($sourceRaw)) ?: [];
        $alphaWords = array_filter($wordTokens, static fn(string $token): bool => preg_match('/^[\p{L}]{2,}$/u', $token) === 1);
        if (count($wordTokens) >= 3 && count($alphaWords) >= 2) {
            return $reject('descriptive_text');
        }

        return [
            'accepted' => true,
            'raw' => $raw,
            'normalized' => $normalized,
            'rejection_reason' => '',
            'warnings' => $warnings,
        ];
    }
}
