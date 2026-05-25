<?php

namespace GPSwiss\Ovoko\Services;

class OvokoImageImportPlan
{
    public const MAX_IMAGES_PER_PRODUCT = 10;

    public function preview_image_import_plan(array $normalizedPart, int $partId, array $authenticatedOriginalUrls = []): array
    {
        $selection = !empty($authenticatedOriginalUrls)
            ? ['selected_urls' => $this->normalize_unique_urls($authenticatedOriginalUrls), 'selected_source' => 'authenticated_original', 'ignored_thumbnail_photo' => true, 'ignored_thumbnail_photo_url' => trim((string) ($normalizedPart['photo'] ?? ''))]
            : $this->select_source_image_urls($normalizedPart);
        $sourceUrls = array_values(array_slice($selection['selected_urls'], 0, self::MAX_IMAGES_PER_PRODUCT));
        $featured = $sourceUrls[0] ?? '';
        $gallery = array_values(array_slice($sourceUrls, 1));
        $allPublicOvoko = !empty($sourceUrls) && count(array_filter($sourceUrls, static fn($url) => is_string($url) && str_contains($url, 'images.ovoko.com'))) === count($sourceUrls);
        $cleanSourceFound = ($selection['selected_source'] === 'authenticated_original') || (!$allPublicOvoko && !empty($sourceUrls));

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
            'max_images_per_product' => self::MAX_IMAGES_PER_PRODUCT,
            'image_model' => 'allegro_compatible',
            'image_source_policy' => [
                'prefer_original_without_watermark' => true,
                'fallback_public_ovoko_watermarked_allowed' => true,
                'clean_source_found' => $cleanSourceFound,
                'selected_source' => $selection['selected_source'] === 'authenticated_original' ? 'authenticated_original' : 'public_watermarked_fallback',
                'warning' => $cleanSourceFound ? '' : 'Only public Ovoko watermarked image URLs found or authenticated original image probe failed.',
            ],
            'image_import_blocked' => false,
            'reason' => 'ready_for_create_draft_media_import',
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
