<?php

namespace GPSwiss\Ovoko\Services;

class OvokoImageImportPlan
{
    public function preview_image_import_plan(array $normalizedPart, int $partId): array
    {
        $selection = $this->select_source_image_urls($normalizedPart);
        $sourceUrls = $selection['selected_urls'];
        $featured = $sourceUrls[0] ?? '';
        $gallery = array_values(array_slice($sourceUrls, 1));

        return [
            'part_id' => $partId,
            'source_fields' => ['photo', 'part_photo_gallery'],
            'source_image_urls' => $sourceUrls,
            'images_count' => count($sourceUrls),
            'featured_image_url' => $featured,
            'gallery_image_urls' => $gallery,
            'selected_source' => $selection['selected_source'],
            'ignored_thumbnail_photo' => $selection['ignored_thumbnail_photo'],
            'ignored_thumbnail_photo_url' => $selection['ignored_thumbnail_photo_url'],
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
            'reason' => 'prefer_full_size_gallery_over_thumbnail_photo',
            'preview_mode_reason' => 'preview_only_no_media_write',
        ];
    }

    private function select_source_image_urls(array $normalizedPart): array
    {
        $galleryUrls = $this->normalize_unique_urls((array) ($normalizedPart['part_photo_gallery'] ?? []));
        $photo = trim((string) ($normalizedPart['photo'] ?? ''));

        if (!empty($galleryUrls)) {
            return [
                'selected_urls' => $galleryUrls,
                'selected_source' => 'part_photo_gallery',
                'ignored_thumbnail_photo' => $photo !== '',
                'ignored_thumbnail_photo_url' => $photo !== '' ? $photo : '',
            ];
        }

        $fallbackUrls = $this->normalize_unique_urls($photo !== '' ? [$photo] : []);

        return [
            'selected_urls' => $fallbackUrls,
            'selected_source' => 'photo_fallback',
            'ignored_thumbnail_photo' => false,
            'ignored_thumbnail_photo_url' => '',
        ];
    }

    private function normalize_unique_urls(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $item) {
            $url = trim((string) $item);
            if ($url === '') {
                continue;
            }

            $normalized[] = $url;
        }

        return array_values(array_unique($normalized));
    }
}
