<?php

namespace GPSwiss\Ovoko\Services;

class OvokoImageImportPlan
{
    public function preview_image_import_plan(array $normalizedPart, int $partId): array
    {
        $sourceUrls = $this->collect_source_image_urls($normalizedPart);
        $featured = $sourceUrls[0] ?? '';
        $gallery = array_values(array_slice($sourceUrls, 1));

        return [
            'part_id' => $partId,
            'source_fields' => ['photo', 'part_photo_gallery'],
            'source_image_urls' => $sourceUrls,
            'images_count' => count($sourceUrls),
            'featured_image_url' => $featured,
            'gallery_image_urls' => $gallery,
            'expected_woo_gallery_order' => $sourceUrls,
            'dedup_strategy' => 'normalize_trim_then_unique_preserve_order',
            'source_url_meta_key_candidate' => '_awi_source_url',
            'attachment_source_marker_candidate' => '_ovoko_source_url',
            'compatible_with_allegro_image_model' => !empty($sourceUrls) ? 'yes' : 'no',
            'compatibility_notes' => [
                'Featured image should be first URL after normalized de-duplication, exactly like Allegro sync_product_images flow.',
                'Woo gallery should contain remaining URLs in source order and be persisted to _product_image_gallery after media import step.',
                'Source URL tracking should reuse _awi_source_url to enable future cross-channel attachment de-duplication by URL.',
            ],
            'image_import_blocked' => true,
            'reason' => 'preview_only_no_media_write',
        ];
    }

    private function collect_source_image_urls(array $normalizedPart): array
    {
        $urls = [];

        $primary = trim((string) ($normalizedPart['photo'] ?? ''));
        if ($primary !== '') {
            $urls[] = $primary;
        }

        foreach ((array) ($normalizedPart['part_photo_gallery'] ?? []) as $item) {
            $url = trim((string) $item);
            if ($url === '') {
                continue;
            }

            $urls[] = $url;
        }

        $normalized = [];
        foreach ($urls as $url) {
            $normalized[] = trim($url);
        }

        return array_values(array_unique(array_filter($normalized, static fn(string $url): bool => $url !== '')));
    }
}
