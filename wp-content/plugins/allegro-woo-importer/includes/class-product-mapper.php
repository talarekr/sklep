<?php

namespace AWI;

use WC_Product;
use WC_Product_Attribute;

if (!defined('ABSPATH')) {
    exit;
}

class ProductMapper
{
    private const IMAGE_HTTP_SLOW_REQUEST_SECONDS = 8;
    private const IMAGE_HTTP_TIMEOUT_SECONDS = 20;
    private const IMAGE_HTTP_REDIRECTION_LIMIT = 3;
    private const MAX_IMAGE_FILE_SIZE_BYTES = 12582912; // 12 MB
    private const MAX_IMAGE_TOTAL_PIXELS = 24000000; // 24 MP
    private const IMPORT_ALLOWED_SUBSIZES = ['thumbnail'];
    private const LISTING_IMAGE_META_KEY = '_awi_listing_image_id';
    private const LISTING_IMAGE_SOURCE_META_KEY = '_awi_listing_image_source_id';
    private const LISTING_IMAGE_GENERATED_AT_META_KEY = '_awi_listing_image_generated_at';
    private const LISTING_IMAGE_ATTACHMENT_FLAG_META_KEY = '_awi_listing_variant';
    private const LISTING_IMAGE_ATTACHMENT_SOURCE_META_KEY = '_awi_listing_source_id';
    private const LISTING_IMAGE_ATTACHMENT_TARGET_FILL_RATIO_META_KEY = '_awi_listing_target_fill_ratio';
    private const LISTING_IMAGE_ATTACHMENT_SCALE_FACTOR_META_KEY = '_awi_listing_scale_factor';
    private const LISTING_IMAGE_ATTACHMENT_SOURCE_WIDTH_META_KEY = '_awi_listing_source_width';
    private const LISTING_IMAGE_ATTACHMENT_SOURCE_HEIGHT_META_KEY = '_awi_listing_source_height';
    private const LISTING_IMAGE_ATTACHMENT_OBJECT_WIDTH_META_KEY = '_awi_listing_object_width';
    private const LISTING_IMAGE_ATTACHMENT_OBJECT_HEIGHT_META_KEY = '_awi_listing_object_height';
    private const LISTING_IMAGE_ATTACHMENT_RENDERED_WIDTH_META_KEY = '_awi_listing_rendered_width';
    private const LISTING_IMAGE_ATTACHMENT_RENDERED_HEIGHT_META_KEY = '_awi_listing_rendered_height';
    private const LISTING_IMAGE_ATTACHMENT_SOURCE_ASPECT_RATIO_META_KEY = '_awi_listing_source_aspect_ratio';
    private const LISTING_IMAGE_ATTACHMENT_FINAL_FIT_MODE_META_KEY = '_awi_listing_final_fit_mode';
    private const LISTING_IMAGE_ATTACHMENT_USED_CROP_META_KEY = '_awi_listing_used_crop';
    private const LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY = '_awi_listing_fill_ratio';
    private const LISTING_IMAGE_ATTACHMENT_RENDER_PROFILE_META_KEY = '_awi_listing_render_profile';
    private const LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY = '_awi_listing_quality_boost_applied';
    private const LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_UPGRADED_META_KEY = '_awi_listing_quality_boost_upgraded';
    private const LISTING_IMAGE_ASPECT_RATIO_META_KEY = '_gp_listing_aspect_ratio';
    private const LISTING_IMAGE_IS_EXTREME_RATIO_META_KEY = '_gp_listing_is_extreme_ratio';
    private const LISTING_SELECTED_SOURCE_IMAGE_ID_META_KEY = '_gp_listing_selected_source_image_id';
    private const LISTING_SOURCE_SELECTION_REASON_META_KEY = '_gp_listing_source_selection_reason';
    private const LISTING_SELECTED_SOURCE_ASPECT_RATIO_META_KEY = '_gp_listing_selected_source_aspect_ratio';
    private const LISTING_QUALITY_TIER_META_KEY = '_gp_listing_quality_tier';
    private const LISTING_QUALITY_SCORE_META_KEY = '_gp_listing_quality_score';
    private const LISTING_BEST_AVAILABLE_SOURCE_QUALITY_TIER_META_KEY = '_gp_listing_best_available_source_quality_tier';
    private const LISTING_REQUIRES_BETTER_SOURCE_META_KEY = '_gp_listing_requires_better_source';
    private const LISTING_IMAGE_CANVAS_SIZE = 900;
    private const LISTING_IMAGE_TARGET_FILL_RATIO = 0.90;

    private AllegroClient $client;
    private Logger $logger;
    private array $image_import_context = [];
    /** @var array<string, array<int, array{id: string, name: string}>> */
    private array $category_path_cache = [];
    /** @var array<string, int> */
    private array $category_term_id_cache = [];

    public function __construct(AllegroClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function get_preferred_listing_image_id(int $product_id): int
    {
        $listing_image_id = (int) get_post_meta($product_id, self::LISTING_IMAGE_META_KEY, true);
        if ($listing_image_id > 0 && get_post($listing_image_id) instanceof \WP_Post) {
            return $listing_image_id;
        }

        $thumbnail_id = (int) get_post_thumbnail_id($product_id);
        if ($thumbnail_id > 0 && get_post($thumbnail_id) instanceof \WP_Post) {
            return $thumbnail_id;
        }

        return 0;
    }

    public function ensure_listing_image_for_product(int $product_id, bool $force = false): array
    {
        $selection = $this->select_best_listing_source_image($product_id);
        $selected_source_id = (int) ($selection['selected_source_image_id'] ?? 0);
        $selected_source_aspect_ratio = (float) ($selection['selected_source_aspect_ratio'] ?? 0.0);
        $selected_source_square_fill_ratio = (float) ($selection['selected_source_square_fill_ratio'] ?? 0.0);
        $selection_reason = (string) ($selection['selected_source_selection_reason'] ?? '');
        if ($selected_source_id <= 0) {
            return [
                'status' => 'skipped',
                'reason' => 'missing_listing_source_image',
                'selected_source_image_id' => 0,
                'selected_source_aspect_ratio' => $selected_source_aspect_ratio,
                'selected_source_square_fill_ratio' => $selected_source_square_fill_ratio,
                'selected_source_selection_reason' => $selection_reason,
            ];
        }

        $current_listing_id = (int) get_post_meta($product_id, self::LISTING_IMAGE_META_KEY, true);
        $current_source_id = (int) get_post_meta($product_id, self::LISTING_IMAGE_SOURCE_META_KEY, true);
        if (!$force && $current_listing_id > 0 && $current_source_id === $selected_source_id && get_post($current_listing_id) instanceof \WP_Post) {
            $this->ensure_listing_attachment_generation_meta($current_listing_id, $selected_source_id);
            $quality = $this->update_listing_quality_meta($product_id, $current_listing_id, $selection);
            return [
                'status' => 'skipped',
                'reason' => 'already_generated',
                'listing_image_id' => $current_listing_id,
                'selected_source_image_id' => $selected_source_id,
                'selected_source_aspect_ratio' => $selected_source_aspect_ratio,
                'selected_source_square_fill_ratio' => $selected_source_square_fill_ratio,
                'selected_source_selection_reason' => $selection_reason,
                'listing_quality_tier' => (string) ($quality['listing_quality_tier'] ?? 'unknown'),
                'listing_quality_score' => (float) ($quality['listing_quality_score'] ?? 0.0),
                'best_available_source_quality_tier' => (string) ($quality['best_available_source_quality_tier'] ?? 'unknown'),
                'requires_better_source' => !empty($quality['requires_better_source']),
            ];
        }

        $created_listing_id = $this->create_listing_image_attachment($selected_source_id, $product_id, 'standard');
        if (is_wp_error($created_listing_id)) {
            return [
                'status' => 'error',
                'reason' => 'listing_image_generation_failed',
                'error_code' => $created_listing_id->get_error_code(),
                'error_message' => $created_listing_id->get_error_message(),
                'selected_source_image_id' => $selected_source_id,
                'selected_source_aspect_ratio' => $selected_source_aspect_ratio,
                'selected_source_square_fill_ratio' => $selected_source_square_fill_ratio,
                'selected_source_selection_reason' => $selection_reason,
            ];
        }

        if ($current_listing_id > 0 && $current_listing_id !== $created_listing_id) {
            wp_delete_attachment($current_listing_id, true);
        }

        update_post_meta($product_id, self::LISTING_IMAGE_META_KEY, $created_listing_id);
        update_post_meta($product_id, self::LISTING_IMAGE_SOURCE_META_KEY, $selected_source_id);
        update_post_meta($product_id, self::LISTING_IMAGE_GENERATED_AT_META_KEY, gmdate('Y-m-d H:i:s'));
        $this->ensure_listing_attachment_generation_meta($created_listing_id, $selected_source_id);
        $quality = $this->update_listing_quality_meta($product_id, (int) $created_listing_id, $selection);
        $quality_boost_applied = false;
        $quality_boost_upgraded = false;
        $standard_quality_tier_before_boost = (string) ($quality['listing_quality_tier'] ?? 'unknown');
        $standard_quality_score_before_boost = (float) ($quality['listing_quality_score'] ?? 0.0);
        $standard_fill_ratio_before_boost = (float) get_post_meta($created_listing_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, true);

        if (
            $created_listing_id > 0
            && (
                (string) ($quality['listing_quality_tier'] ?? '') === 'degraded'
                || !empty($quality['requires_better_source'])
            )
        ) {
            $baseline_tier = (string) ($quality['listing_quality_tier'] ?? 'unknown');
            $baseline_score = (float) ($quality['listing_quality_score'] ?? 0.0);
            $baseline_fill_ratio = (float) get_post_meta($created_listing_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, true);
            $boost_profile = $this->determine_listing_quality_boost_profile($selected_source_aspect_ratio);
            $boosted_listing_id = $this->create_listing_image_attachment($selected_source_id, $product_id, $boost_profile);
            if (!is_wp_error($boosted_listing_id)) {
                wp_delete_attachment((int) $created_listing_id, true);
                $created_listing_id = (int) $boosted_listing_id;
                update_post_meta($product_id, self::LISTING_IMAGE_META_KEY, $created_listing_id);
                update_post_meta($product_id, self::LISTING_IMAGE_SOURCE_META_KEY, $selected_source_id);
                update_post_meta($product_id, self::LISTING_IMAGE_GENERATED_AT_META_KEY, gmdate('Y-m-d H:i:s'));
                $this->ensure_listing_attachment_generation_meta($created_listing_id, $selected_source_id);
                $quality = $this->update_listing_quality_meta($product_id, $created_listing_id, $selection);
                $quality_boost_applied = true;
                $boosted_fill_ratio = (float) get_post_meta($created_listing_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, true);
                $quality_boost_upgraded = $this->did_quality_boost_upgrade_quality(
                    $baseline_tier,
                    (float) $baseline_score,
                    $baseline_fill_ratio,
                    (string) ($quality['listing_quality_tier'] ?? 'unknown'),
                    (float) ($quality['listing_quality_score'] ?? 0.0),
                    $boosted_fill_ratio
                );
                update_post_meta($created_listing_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY, 1);
                update_post_meta($created_listing_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_UPGRADED_META_KEY, $quality_boost_upgraded ? 1 : 0);
            } else {
                $this->logger->warning('Listing quality boost render failed, keeping standard render.', [
                    'product_id' => $product_id,
                    'source_attachment_id' => $selected_source_id,
                    'boost_profile' => $boost_profile,
                    'error_code' => $boosted_listing_id->get_error_code(),
                    'error_message' => $boosted_listing_id->get_error_message(),
                ]);
            }
        }

        return [
            'status' => 'created',
            'listing_image_id' => $created_listing_id,
            'selected_source_image_id' => $selected_source_id,
            'selected_source_aspect_ratio' => $selected_source_aspect_ratio,
            'selected_source_square_fill_ratio' => $selected_source_square_fill_ratio,
            'selected_source_selection_reason' => $selection_reason,
            'listing_quality_tier' => (string) ($quality['listing_quality_tier'] ?? 'unknown'),
            'listing_quality_score' => (float) ($quality['listing_quality_score'] ?? 0.0),
            'best_available_source_quality_tier' => (string) ($quality['best_available_source_quality_tier'] ?? 'unknown'),
            'requires_better_source' => !empty($quality['requires_better_source']),
            'standard_quality_tier_before_boost' => $standard_quality_tier_before_boost,
            'standard_quality_score_before_boost' => round($standard_quality_score_before_boost, 6),
            'standard_fill_ratio_before_boost' => round($standard_fill_ratio_before_boost, 6),
            'final_quality_tier_after_boost' => (string) ($quality['listing_quality_tier'] ?? 'unknown'),
            'final_quality_score_after_boost' => round((float) ($quality['listing_quality_score'] ?? 0.0), 6),
            'quality_boost_applied' => $quality_boost_applied,
            'quality_boost_upgraded' => $quality_boost_upgraded,
        ];
    }

    public function get_listing_image_diagnostics(int $product_id): array
    {
        $listing_image_id = (int) get_post_meta($product_id, self::LISTING_IMAGE_META_KEY, true);
        $featured_image_id = (int) get_post_thumbnail_id($product_id);
        $helper_selected_image_id = $this->get_preferred_listing_image_id($product_id);
        $selected_source_image_id = (int) get_post_meta($product_id, self::LISTING_SELECTED_SOURCE_IMAGE_ID_META_KEY, true);
        $selected_source_aspect_ratio = (float) get_post_meta($product_id, self::LISTING_SELECTED_SOURCE_ASPECT_RATIO_META_KEY, true);
        $selected_source_selection_reason = (string) get_post_meta($product_id, self::LISTING_SOURCE_SELECTION_REASON_META_KEY, true);
        $gallery_image_ids = $this->get_listing_source_candidate_image_ids($product_id);

        $rendered_source = 'placeholder';
        if ($helper_selected_image_id > 0) {
            if ($listing_image_id > 0 && $helper_selected_image_id === $listing_image_id) {
                $rendered_source = 'listing_image';
            } elseif ($featured_image_id > 0 && $helper_selected_image_id === $featured_image_id) {
                $rendered_source = 'featured_image_helper_fallback';
            } else {
                $rendered_source = 'helper_attachment';
            }
        } elseif ($featured_image_id > 0) {
            $rendered_source = 'featured_image_template_fallback';
        }

        $listing_path = $listing_image_id > 0 ? (string) get_attached_file($listing_image_id) : '';
        $featured_path = $featured_image_id > 0 ? (string) get_attached_file($featured_image_id) : '';
        $object_width = (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_WIDTH_META_KEY, true);
        $object_height = (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_HEIGHT_META_KEY, true);
        $aspect_ratio = (float) get_post_meta($listing_image_id, self::LISTING_IMAGE_ASPECT_RATIO_META_KEY, true);
        $is_extreme_aspect_ratio = (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_IS_EXTREME_RATIO_META_KEY, true) === 1;
        $fit_limited_by = $object_height > $object_width ? 'height' : 'width';

        return [
            'product_id' => $product_id,
            'listing_image_id' => $listing_image_id,
            'featured_image_id' => $featured_image_id,
            'candidate_source_image_ids' => $gallery_image_ids,
            'gallery_images_count' => count($gallery_image_ids),
            'selected_source_image_id' => $selected_source_image_id,
            'selected_source_aspect_ratio' => $selected_source_aspect_ratio,
            'selected_source_selection_reason' => $selected_source_selection_reason,
            'listing_quality_tier' => (string) get_post_meta($product_id, self::LISTING_QUALITY_TIER_META_KEY, true),
            'listing_quality_score' => (float) get_post_meta($product_id, self::LISTING_QUALITY_SCORE_META_KEY, true),
            'best_available_source_quality_tier' => (string) get_post_meta($product_id, self::LISTING_BEST_AVAILABLE_SOURCE_QUALITY_TIER_META_KEY, true),
            'requires_better_source' => (int) get_post_meta($product_id, self::LISTING_REQUIRES_BETTER_SOURCE_META_KEY, true) === 1,
            'helper_selected_image_id' => $helper_selected_image_id,
            'rendered_source' => $rendered_source,
            'listing_image_meta_source_id' => (int) get_post_meta($product_id, self::LISTING_IMAGE_SOURCE_META_KEY, true),
            'listing_image_generated_at' => (string) get_post_meta($product_id, self::LISTING_IMAGE_GENERATED_AT_META_KEY, true),
            'listing_file_path' => $listing_path,
            'listing_file_exists' => $listing_path !== '' ? file_exists($listing_path) : false,
            'listing_file_url' => $listing_image_id > 0 ? wp_get_attachment_url($listing_image_id) : '',
            'featured_file_path' => $featured_path,
            'featured_file_exists' => $featured_path !== '' ? file_exists($featured_path) : false,
            'featured_file_url' => $featured_image_id > 0 ? wp_get_attachment_url($featured_image_id) : '',
            'listing_attachment_variant_flag' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_FLAG_META_KEY, true),
            'listing_attachment_source_id' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_META_KEY, true),
            'listing_attachment_target_fill_ratio' => (float) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_TARGET_FILL_RATIO_META_KEY, true),
            'listing_attachment_fill_ratio' => (float) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, true),
            'listing_attachment_scale_factor' => (float) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_SCALE_FACTOR_META_KEY, true),
            'listing_attachment_source_width' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_WIDTH_META_KEY, true),
            'listing_attachment_source_height' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_HEIGHT_META_KEY, true),
            'listing_attachment_source_aspect_ratio' => (float) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_ASPECT_RATIO_META_KEY, true),
            'listing_attachment_object_width' => $object_width,
            'listing_attachment_object_height' => $object_height,
            'listing_attachment_rendered_width' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_WIDTH_META_KEY, true),
            'listing_attachment_rendered_height' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_HEIGHT_META_KEY, true),
            'listing_attachment_final_fit_mode' => (string) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_FINAL_FIT_MODE_META_KEY, true),
            'listing_attachment_used_crop' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_USED_CROP_META_KEY, true) === 1,
            'listing_attachment_fallback_used' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_USED_CROP_META_KEY, true) === 1,
            'listing_attachment_render_profile' => (string) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_RENDER_PROFILE_META_KEY, true),
            'listing_attachment_quality_boost_applied' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY, true) === 1,
            'listing_attachment_quality_boost_upgraded' => (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_UPGRADED_META_KEY, true) === 1,
            'aspect_ratio' => $aspect_ratio,
            'is_extreme_aspect_ratio' => $is_extreme_aspect_ratio,
            'fit_limited_by' => $fit_limited_by,
        ];
    }

    public function select_best_listing_source_image(int $product_id): array
    {
        $candidate_ids = $this->get_listing_source_candidate_image_ids($product_id);
        if ($candidate_ids === []) {
            update_post_meta($product_id, self::LISTING_SELECTED_SOURCE_IMAGE_ID_META_KEY, 0);
            update_post_meta($product_id, self::LISTING_SELECTED_SOURCE_ASPECT_RATIO_META_KEY, 0);
            update_post_meta($product_id, self::LISTING_SOURCE_SELECTION_REASON_META_KEY, 'no_valid_image_candidates');
            return [
                'candidate_source_image_ids' => [],
                'gallery_images_count' => 0,
                'selected_source_image_id' => 0,
                'selected_source_aspect_ratio' => 0.0,
                'selected_source_quality_tier' => 'unknown',
                'selected_source_selection_reason' => 'no_valid_image_candidates',
            ];
        }

        $best_candidate = null;
        $best_score = null;
        foreach ($candidate_ids as $candidate_id) {
            $metrics = $this->get_attachment_trim_metrics_for_listing_selection((int) $candidate_id);
            if ($metrics === null) {
                continue;
            }

            $metrics['listing_quality_score'] = $this->calculate_listing_source_quality_score($metrics);
            $metrics['quality_tier'] = $this->determine_listing_source_quality_tier($metrics);

            if ($best_candidate === null) {
                $best_candidate = $metrics;
                $best_score = (float) $metrics['listing_quality_score'];
                continue;
            }

            $current_score = (float) $metrics['listing_quality_score'];
            $score_delta = $current_score - (float) $best_score;
            $is_significantly_better = $score_delta > 0.000001;
            $is_score_tie = abs($score_delta) < 0.000001;

            $is_better_fill = $metrics['square_fill_ratio'] > $best_candidate['square_fill_ratio'];
            $is_tie_fill = abs($metrics['square_fill_ratio'] - $best_candidate['square_fill_ratio']) < 0.000001;
            $is_better_area = $metrics['object_area_ratio'] > $best_candidate['object_area_ratio'];
            $is_better_aspect = $metrics['aspect_distance_from_square'] < $best_candidate['aspect_distance_from_square'];

            if (
                $is_significantly_better
                || ($is_score_tie && $is_better_fill)
                || ($is_score_tie && $is_tie_fill && $is_better_area)
                || ($is_score_tie && $is_tie_fill && !$is_better_area && $is_better_aspect)
            ) {
                $best_candidate = $metrics;
                $best_score = $current_score;
            }
        }

        if ($best_candidate === null) {
            $fallback_candidate_id = (int) $candidate_ids[0];
            update_post_meta($product_id, self::LISTING_SELECTED_SOURCE_IMAGE_ID_META_KEY, $fallback_candidate_id);
            update_post_meta($product_id, self::LISTING_SELECTED_SOURCE_ASPECT_RATIO_META_KEY, 0);
            update_post_meta($product_id, self::LISTING_SOURCE_SELECTION_REASON_META_KEY, 'fallback_first_candidate_missing_metrics');
            return [
                'candidate_source_image_ids' => $candidate_ids,
                'gallery_images_count' => count($candidate_ids),
                'selected_source_image_id' => $fallback_candidate_id,
                'selected_source_aspect_ratio' => 0.0,
                'selected_source_square_fill_ratio' => 0.0,
                'selected_source_selection_reason' => 'fallback_first_candidate_missing_metrics',
            ];
        }

        $selection_reason = sprintf(
            'listing_first_quality_score:score=%.6f;tier=%s;aspect_ratio=%.6f;aspect_distance=%.6f;object_area_ratio=%.6f;square_fill_ratio=%.6f',
            (float) ($best_candidate['listing_quality_score'] ?? 0.0),
            (string) ($best_candidate['quality_tier'] ?? 'unknown'),
            $best_candidate['aspect_ratio'],
            $best_candidate['aspect_distance_from_square'],
            $best_candidate['object_area_ratio'],
            $best_candidate['square_fill_ratio']
        );

        update_post_meta($product_id, self::LISTING_SELECTED_SOURCE_IMAGE_ID_META_KEY, (int) $best_candidate['attachment_id']);
        update_post_meta($product_id, self::LISTING_SELECTED_SOURCE_ASPECT_RATIO_META_KEY, round((float) $best_candidate['aspect_ratio'], 6));
        update_post_meta($product_id, self::LISTING_SOURCE_SELECTION_REASON_META_KEY, $selection_reason);

        $this->logger->info('Listing source image selected.', [
            'product_id' => $product_id,
            'candidate_source_image_ids' => $candidate_ids,
            'gallery_images_count' => count($candidate_ids),
            'selected_source_image_id' => (int) $best_candidate['attachment_id'],
            'selected_source_aspect_ratio' => round((float) $best_candidate['aspect_ratio'], 6),
            'selected_source_square_fill_ratio' => round((float) $best_candidate['square_fill_ratio'], 6),
            'selected_source_selection_reason' => $selection_reason,
        ]);

        return [
            'candidate_source_image_ids' => $candidate_ids,
            'gallery_images_count' => count($candidate_ids),
            'selected_source_image_id' => (int) $best_candidate['attachment_id'],
            'selected_source_aspect_ratio' => round((float) $best_candidate['aspect_ratio'], 6),
            'selected_source_square_fill_ratio' => round((float) $best_candidate['square_fill_ratio'], 6),
            'selected_source_selection_reason' => $selection_reason,
        ];
    }

    private function determine_listing_source_quality_tier(array $metrics): string
    {
        $square_fill_ratio = (float) ($metrics['square_fill_ratio'] ?? 0.0);
        $aspect_ratio = (float) ($metrics['aspect_ratio'] ?? 0.0);

        if ($square_fill_ratio >= 0.60 && $aspect_ratio >= 0.55 && $aspect_ratio <= 1.80) {
            return 'preferred';
        }

        if ($square_fill_ratio < 0.50 || $aspect_ratio < 0.55 || $aspect_ratio > 1.80) {
            return 'degraded';
        }

        return 'acceptable';
    }

    private function calculate_listing_source_quality_score(array $metrics): float
    {
        $square_fill_ratio = max(0.0, min(1.0, (float) ($metrics['square_fill_ratio'] ?? 0.0)));
        $aspect_ratio = max(0.000001, (float) ($metrics['aspect_ratio'] ?? 1.0));
        $aspect_distance = abs(1.0 - $aspect_ratio);
        $object_area_ratio = max(0.0, min(1.0, (float) ($metrics['object_area_ratio'] ?? 0.0)));

        $score = ($square_fill_ratio * 1000.0) + ($object_area_ratio * 150.0) - ($aspect_distance * 110.0);

        if ($square_fill_ratio >= 0.60) {
            $score += 120.0;
        } elseif ($square_fill_ratio < 0.50) {
            $score -= 260.0;
        }

        if ($square_fill_ratio < 0.40) {
            $score -= 220.0;
        }

        if ($aspect_ratio < 0.55 || $aspect_ratio > 1.80) {
            $score -= 220.0;
        }

        if ($aspect_ratio < 0.40 || $aspect_ratio > 2.50) {
            $score -= 420.0;
        }

        return $score;
    }

    private function calculate_listing_render_quality_score(
        float $fill_ratio,
        string $fit_mode,
        string $render_profile,
        bool $quality_boost_applied,
        int $rendered_width,
        int $rendered_height
    ): float {
        $fill_ratio = max(0.0, min(1.0, $fill_ratio));
        $score = $fill_ratio * 1000.0;
        $max_rendered_side = max($rendered_width, $rendered_height);
        $rendered_side_ratio = max(0.0, min(1.0, $max_rendered_side / max(1, self::LISTING_IMAGE_CANVAS_SIZE)));
        $score += $rendered_side_ratio * 150.0;

        if ($fill_ratio >= 0.99) {
            $score += 140.0;
        } elseif ($fill_ratio >= 0.975) {
            $score += 90.0;
        } elseif ($fill_ratio >= 0.95) {
            $score += 45.0;
        } elseif ($fill_ratio < 0.90) {
            $score -= 180.0;
        }

        if (str_starts_with($fit_mode, 'quality_boost_')) {
            $score += 60.0;
        }

        if ($quality_boost_applied || $render_profile !== 'standard') {
            $score += 30.0;
        }

        return $score;
    }

    private function determine_listing_render_quality_tier(
        float $fill_ratio,
        string $fit_mode,
        string $render_profile,
        bool $quality_boost_applied
    ): string {
        $fill_ratio = max(0.0, min(1.0, $fill_ratio));
        $is_boost_render = $quality_boost_applied || $render_profile !== 'standard' || str_starts_with($fit_mode, 'quality_boost_');

        if ($fill_ratio >= 0.99) {
            return 'preferred';
        }

        if ($fill_ratio >= 0.975 && $is_boost_render) {
            return 'preferred';
        }

        if ($fill_ratio >= 0.95) {
            return 'acceptable';
        }

        if ($fill_ratio >= 0.92 && $is_boost_render) {
            return 'acceptable';
        }

        return 'degraded';
    }

    private function did_quality_boost_upgrade_quality(
        string $baseline_tier,
        float $baseline_score,
        float $baseline_fill_ratio,
        string $final_tier,
        float $final_score,
        float $final_fill_ratio
    ): bool {
        $baseline_rank = $this->get_listing_quality_tier_rank($baseline_tier);
        $final_rank = $this->get_listing_quality_tier_rank($final_tier);
        if ($final_rank > $baseline_rank) {
            return true;
        }

        if ($final_rank === $baseline_rank) {
            $score_delta = $final_score - $baseline_score;
            $fill_delta = $final_fill_ratio - $baseline_fill_ratio;
            if ($score_delta >= 35.0 && $fill_delta >= 0.02 && $final_fill_ratio >= 0.95) {
                return true;
            }
        }

        return false;
    }

    private function update_listing_quality_meta(int $product_id, int $listing_image_id, array $selection): array
    {
        $selected_source_image_id = (int) ($selection['selected_source_image_id'] ?? 0);

        $selected_metrics = null;
        if ($selected_source_image_id > 0) {
            $selected_metrics = $this->get_attachment_trim_metrics_for_listing_selection($selected_source_image_id);
        }

        $source_quality_score = 0.0;
        $source_quality_tier = 'unknown';
        if (is_array($selected_metrics)) {
            $source_quality_score = $this->calculate_listing_source_quality_score($selected_metrics);
            $source_quality_tier = $this->determine_listing_source_quality_tier($selected_metrics);
        }

        $best_available_tier = 'unknown';
        $best_available_rank = 0;
        $candidate_ids = array_map('intval', (array) ($selection['candidate_source_image_ids'] ?? []));
        foreach ($candidate_ids as $candidate_id) {
            if ($candidate_id <= 0) {
                continue;
            }

            $candidate_metrics = $this->get_attachment_trim_metrics_for_listing_selection($candidate_id);
            if (!is_array($candidate_metrics)) {
                continue;
            }

            $candidate_tier = $this->determine_listing_source_quality_tier($candidate_metrics);
            $candidate_rank = $this->get_listing_quality_tier_rank($candidate_tier);
            if ($candidate_rank > $best_available_rank) {
                $best_available_rank = $candidate_rank;
                $best_available_tier = $candidate_tier;
            }
        }

        if ($best_available_tier === 'unknown') {
            $best_available_tier = $source_quality_tier;
        }

        $render_fill_ratio = (float) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, true);
        $render_fit_mode = (string) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_FINAL_FIT_MODE_META_KEY, true);
        $render_profile = (string) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_RENDER_PROFILE_META_KEY, true);
        $quality_boost_applied = (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY, true) === 1;
        $rendered_width = (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_WIDTH_META_KEY, true);
        $rendered_height = (int) get_post_meta($listing_image_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_HEIGHT_META_KEY, true);

        $render_quality_score = $this->calculate_listing_render_quality_score(
            $render_fill_ratio,
            $render_fit_mode,
            $render_profile,
            $quality_boost_applied,
            $rendered_width,
            $rendered_height
        );
        $render_quality_tier = $this->determine_listing_render_quality_tier(
            $render_fill_ratio,
            $render_fit_mode,
            $render_profile,
            $quality_boost_applied
        );

        $source_rank = $this->get_listing_quality_tier_rank($source_quality_tier);
        $render_rank = $this->get_listing_quality_tier_rank($render_quality_tier);
        $listing_quality_tier = $source_quality_tier;
        $listing_quality_score = $source_quality_score;

        if ($quality_boost_applied || $render_profile !== 'standard') {
            if ($render_rank >= $source_rank) {
                $listing_quality_tier = $render_quality_tier;
            }

            $listing_quality_score = ($source_quality_score * 0.35) + ($render_quality_score * 0.65);
        }

        $requires_better_source = $this->get_listing_quality_tier_rank($best_available_tier) < $this->get_listing_quality_tier_rank('preferred');
        if (($quality_boost_applied || $render_profile !== 'standard') && $render_rank >= $this->get_listing_quality_tier_rank('acceptable') && $render_fill_ratio >= 0.95) {
            $requires_better_source = false;
        }

        update_post_meta($product_id, self::LISTING_QUALITY_TIER_META_KEY, $listing_quality_tier);
        update_post_meta($product_id, self::LISTING_QUALITY_SCORE_META_KEY, round($listing_quality_score, 6));
        update_post_meta($product_id, self::LISTING_BEST_AVAILABLE_SOURCE_QUALITY_TIER_META_KEY, $best_available_tier);
        update_post_meta($product_id, self::LISTING_REQUIRES_BETTER_SOURCE_META_KEY, $requires_better_source ? 1 : 0);

        if ($listing_image_id > 0) {
            update_post_meta($listing_image_id, self::LISTING_QUALITY_TIER_META_KEY, $listing_quality_tier);
            update_post_meta($listing_image_id, self::LISTING_QUALITY_SCORE_META_KEY, round($listing_quality_score, 6));
            update_post_meta($listing_image_id, self::LISTING_BEST_AVAILABLE_SOURCE_QUALITY_TIER_META_KEY, $best_available_tier);
            update_post_meta($listing_image_id, self::LISTING_REQUIRES_BETTER_SOURCE_META_KEY, $requires_better_source ? 1 : 0);
        }

        return [
            'listing_quality_tier' => $listing_quality_tier,
            'listing_quality_score' => round($listing_quality_score, 6),
            'listing_source_quality_tier' => $source_quality_tier,
            'listing_source_quality_score' => round($source_quality_score, 6),
            'listing_render_quality_tier' => $render_quality_tier,
            'listing_render_quality_score' => round($render_quality_score, 6),
            'best_available_source_quality_tier' => $best_available_tier,
            'requires_better_source' => $requires_better_source,
        ];
    }

    private function get_listing_quality_tier_rank(string $tier): int
    {
        return match ($tier) {
            'preferred' => 3,
            'acceptable' => 2,
            'degraded' => 1,
            default => 0,
        };
    }

    public function upsert_product(array $offer, array $settings): array
    {
        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        if ($offer_id === '') {
            return ['result' => 'skipped', 'error' => 'missing_offer_id'];
        }
        $this->logger->info('PRODUCT_MAP_START', ['offer_id' => $offer_id]);

        $existing_id = $this->find_product_id_by_offer_id($offer_id);
        $sync_mode = $settings['sync_mode'] ?? 'create_update';

        if ($existing_id && $sync_mode === 'create_only') {
            return ['result' => 'skipped', 'reason' => 'sync_mode_create_only_existing_product', 'product_id' => $existing_id];
        }

        if (!$existing_id && $sync_mode === 'update_only') {
            return ['result' => 'skipped', 'reason' => 'sync_mode_update_only_missing_product'];
        }

        $product = $existing_id ? wc_get_product($existing_id) : new \WC_Product_Simple();
        if (!$product instanceof WC_Product) {
            return ['result' => 'error', 'error' => 'invalid_product_instance'];
        }
        $previous_status = $existing_id ? (string) $product->get_status() : '';

        $title = sanitize_text_field((string) ($offer['name'] ?? __('Oferta Allegro', 'allegro-woo-importer')));
        $description = $this->map_description($offer);
        $price = $this->extract_price($offer);
        $currency = sanitize_text_field((string) ($offer['sellingMode']['price']['currency'] ?? 'PLN'));
        $publication_status = strtoupper((string) ($offer['publication']['status'] ?? 'INACTIVE'));
        $stock_available = $this->normalize_stock_available($offer['stock']['available'] ?? null);
        $missing_fields = $this->collect_missing_fields($offer, $title, $description, $price);
        if (!empty($missing_fields)) {
            $this->logger->warning('Offer mapping used fallback values.', ['offer_id' => $offer_id, 'missing_fields' => $missing_fields]);
        }
        $this->logger->info('PRODUCT_MAP_DONE', [
            'offer_id' => $offer_id,
            'has_price' => $price !== null,
            'has_images' => !empty($offer['images']) && is_array($offer['images']),
            'existing_product_id' => (int) $existing_id,
        ]);

        $product->set_name($title);
        $product->set_description($description);

        if ($price !== null) {
            $product->set_regular_price((string) $price);
            $product->set_price((string) $price);
        }

        $sku = $this->extract_sku($offer);
        if (!empty($sku)) {
            try {
                $product->set_sku($sku);
            } catch (\Throwable $throwable) {
                return [
                    'result' => 'error',
                    'error' => 'invalid_or_duplicate_sku',
                    'reason' => $throwable->getMessage(),
                ];
            }
        }

        $product->set_catalog_visibility('visible');
        $target_status = $this->map_product_status($publication_status, $stock_available, $settings);
        $product->set_status($target_status);

        $this->apply_stock_state($product, $publication_status, $stock_available);

        $this->logger->info('PRODUCT_SAVE_START', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product->get_id(),
            'phase' => 'initial',
        ]);
        try {
            $product_id = $product->save();
        } catch (\Throwable $throwable) {
            return [
                'result' => 'error',
                'error' => 'product_save_exception',
                'stage' => 'product_save_initial',
                'reason' => $throwable->getMessage(),
            ];
        }

        if (!$product_id) {
            return ['result' => 'error', 'error' => 'save_failed', 'stage' => 'product_save_initial'];
        }
        $this->logger->info('PRODUCT_SAVE_DONE', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product_id,
            'phase' => 'initial',
        ]);

        try {
            $this->assign_category($product_id, $offer);
            $this->map_attributes($product, $offer);
        } catch (\Throwable $throwable) {
            return [
                'result' => 'error',
                'error' => 'product_mapping_exception',
                'stage' => 'assign_category_or_attributes',
                'reason' => $throwable->getMessage(),
                'product_id' => (int) $product_id,
            ];
        }
        $this->logger->info('IMAGE_SYNC_START', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product_id,
        ]);
        try {
            $this->sync_product_images($product, $offer, $offer_id, !$existing_id);
        } catch (\Throwable $throwable) {
            return [
                'result' => 'error',
                'error' => 'image_sync_exception',
                'stage' => 'sync_product_images',
                'reason' => $throwable->getMessage(),
                'product_id' => (int) $product_id,
            ];
        }
        $this->logger->info('IMAGE_SYNC_DONE', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product_id,
        ]);
        $this->logger->info('Saving product after image sync.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'image_id_before_save' => (int) $product->get_image_id(),
            'gallery_ids_before_save' => array_map('intval', $product->get_gallery_image_ids()),
        ]);
        $this->logger->info('PRODUCT_SAVE_START', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product_id,
            'phase' => 'final_after_images',
        ]);
        try {
            $product->save();
        } catch (\Throwable $throwable) {
            return [
                'result' => 'error',
                'error' => 'product_save_exception',
                'stage' => 'product_save_final',
                'reason' => $throwable->getMessage(),
                'product_id' => (int) $product_id,
            ];
        }
        $this->logger->info('PRODUCT_SAVE_DONE', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product_id,
            'phase' => 'final_after_images',
        ]);

        $part_number_result = $this->extract_part_number($offer);
        $existing_part_number = sanitize_text_field((string) get_post_meta($product_id, '_part_number', true));
        $part_number_to_save = 'Brak';

        if ($part_number_result['found']) {
            $part_number_to_save = $part_number_result['value'];
        } elseif ($existing_part_number !== '' && $existing_part_number !== 'Brak') {
            $part_number_to_save = $existing_part_number;
        }

        update_post_meta($product_id, '_part_number', $part_number_to_save);

        $this->logger->info('Part number mapping decision saved to product meta.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'found_in_offer' => (bool) $part_number_result['found'],
            'source' => (string) $part_number_result['source'],
            'existing_meta_before_save' => $existing_part_number,
            'saved_meta_value' => $part_number_to_save,
        ]);

        update_post_meta($product_id, '_allegro_offer_id', $offer_id);
        update_post_meta($product_id, '_allegro_offer_url', esc_url_raw($this->extract_offer_url($offer)));
        update_post_meta($product_id, '_allegro_category_id', sanitize_text_field((string) ($offer['category']['id'] ?? '')));
        update_post_meta($product_id, '_allegro_status', $publication_status);
        update_post_meta($product_id, '_allegro_currency', $currency);
        update_post_meta($product_id, '_allegro_imported_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($product_id, '_allegro_parameters', wp_json_encode($offer['parameters'] ?? []));

        $this->logger->info('Product import upsert completed.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'operation' => $existing_id ? 'updated' : 'created',
        ]);

        if ($stock_available === 0) {
            $this->logger->info('Product marked as outofstock based on explicit Allegro stock=0.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'reason' => 'stock_available_zero',
                'status_before' => $previous_status,
                'status_after' => $target_status,
            ]);
        }

        if ($publication_status !== 'ACTIVE') {
            $this->logger->info('Product marked as outofstock because Allegro publication is not ACTIVE.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'reason' => 'publication_not_active',
                'publication_status' => $publication_status,
                'status_before' => $previous_status,
                'status_after' => $target_status,
            ]);
        }

        if (
            $previous_status !== 'publish'
            && $target_status === 'publish'
            && $publication_status === 'ACTIVE'
            && ($stock_available === null || $stock_available > 0)
        ) {
            $this->logger->info('Product restored to storefront after Allegro availability returned.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'status_before' => $previous_status,
                'status_after' => $target_status,
                'stock_available' => $stock_available,
            ]);
        }

        return ['result' => $existing_id ? 'updated' : 'created', 'product_id' => $product_id];
    }

    public function find_existing_product_id_for_offer(array $offer): int
    {
        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        if ($offer_id !== '') {
            $by_offer_id = $this->find_product_id_by_offer_id($offer_id);
            if ($by_offer_id > 0) {
                return $by_offer_id;
            }
        }

        $sku = $this->extract_sku($offer);
        if ($sku === '') {
            return 0;
        }

        if (function_exists('wc_get_product_id_by_sku')) {
            $by_sku = (int) wc_get_product_id_by_sku($sku);
            if ($by_sku > 0) {
                return $by_sku;
            }
        }

        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_sku',
                    'value' => $sku,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function find_product_id_by_offer_id(string $offer_id): int
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_allegro_offer_id',
                    'value' => $offer_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function map_description(array $offer): string
    {
        if (!empty($offer['description']['sections']) && is_array($offer['description']['sections'])) {
            $html = '';
            foreach ($offer['description']['sections'] as $section) {
                foreach (($section['items'] ?? []) as $item) {
                    if (($item['type'] ?? '') === 'TEXT' && !empty($item['content'])) {
                        $html .= wp_kses_post((string) $item['content']);
                    }
                }
            }
            return $html;
        }

        if (!empty($offer['description']) && is_string($offer['description'])) {
            return wp_kses_post($offer['description']);
        }

        return '';
    }

    private function extract_price(array $offer): ?float
    {
        $amount = $offer['sellingMode']['price']['amount'] ?? ($offer['sellingMode']['startingPrice']['amount'] ?? null);
        if ($amount === null || $amount === '') {
            return null;
        }

        return (float) $amount;
    }

    private function extract_sku(array $offer): string
    {
        foreach (($offer['parameters'] ?? []) as $parameter) {
            $name = mb_strtolower((string) ($parameter['name'] ?? ''));
            if (in_array($name, ['numer części', 'nr części', 'sku', 'part number'], true) && !empty($parameter['values'])) {
                $value = $parameter['values'][0] ?? '';
                return sanitize_text_field((string) $value);
            }
        }

        return '';
    }

    private function extract_part_number(array $offer): array
    {
        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        $parameter_sources = $this->collect_part_number_parameter_sources($offer);

        $total_parameters = 0;
        $sources_preview = [];
        foreach ($parameter_sources as $source) {
            $parameters_in_source = is_array($source['parameters'] ?? null) ? count((array) $source['parameters']) : 0;
            $total_parameters += $parameters_in_source;
            $sources_preview[] = [
                'path' => (string) ($source['path'] ?? ''),
                'count' => $parameters_in_source,
                'preview' => is_array($source['parameters'] ?? null) ? array_slice((array) $source['parameters'], 0, 4) : [],
            ];
        }

        $this->logger->info('Inspecting Allegro parameters for part number mapping.', [
            'offer_id' => $offer_id,
            'parameters_count' => $total_parameters,
            'parameters_sources_preview' => $sources_preview,
        ]);

        foreach ($parameter_sources as $source) {
            $source_path = sanitize_text_field((string) ($source['path'] ?? 'unknown'));
            $parameters = is_array($source['parameters'] ?? null) ? (array) $source['parameters'] : [];

            foreach ($parameters as $parameter) {
                $raw_name = sanitize_text_field((string) ($parameter['name'] ?? ''));
                $name = $this->normalize_parameter_name($raw_name);
                if (!in_array($name, ['numer katalogowy części', 'numer katalogowy czesci'], true)) {
                    continue;
                }

                $this->logger->info('Found Allegro part number parameter by name match.', [
                    'offer_id' => $offer_id,
                    'raw_name' => $raw_name,
                    'normalized_name' => $name,
                    'source_path' => $source_path,
                ]);

                $source_map = [
                    'values' => (array) ($parameter['values'] ?? []),
                    'valuesLabels' => (array) ($parameter['valuesLabels'] ?? []),
                    'value' => isset($parameter['value']) ? [(string) $parameter['value']] : [],
                    'valueLabel' => isset($parameter['valueLabel']) ? [(string) $parameter['valueLabel']] : [],
                ];

                foreach ($source_map as $source_value_key => $candidates) {
                    foreach ($candidates as $candidate) {
                        $normalized = sanitize_text_field((string) $candidate);
                        if (trim($normalized) === '') {
                            continue;
                        }

                        $matched_source = $source_path . '.' . $source_value_key;
                        $this->logger->info('Part number extracted from Allegro parameter source.', [
                            'offer_id' => $offer_id,
                            'source' => $matched_source,
                            'value' => $normalized,
                        ]);

                        return [
                            'found' => true,
                            'value' => $normalized,
                            'source' => $matched_source,
                        ];
                    }
                }
            }
        }

        $this->logger->warning('Part number parameter not found in Allegro offer parameters.', [
            'offer_id' => $offer_id,
            'checked_sources' => array_map(
                static fn (array $source): string => (string) ($source['path'] ?? ''),
                $parameter_sources
            ),
        ]);

        return [
            'found' => false,
            'value' => 'Brak',
            'source' => 'fallback',
        ];
    }

    private function normalize_parameter_name(string $name): string
    {
        $name = mb_strtolower(trim(sanitize_text_field($name)));
        $name = str_replace(
            ['ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ż', 'ź'],
            ['a', 'c', 'e', 'l', 'n', 'o', 's', 'z', 'z'],
            $name
        );

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }

    private function collect_part_number_parameter_sources(array $offer): array
    {
        $sources = [];
        if (is_array($offer['parameters'] ?? null)) {
            $sources[] = [
                'path' => 'parameters',
                'parameters' => (array) $offer['parameters'],
            ];
        }

        $product_set = $offer['productSet'] ?? [];
        if (!is_array($product_set)) {
            return $sources;
        }

        foreach ($product_set as $index => $product_set_item) {
            if (!is_array($product_set_item)) {
                continue;
            }

            $product_parameters = $product_set_item['product']['parameters'] ?? null;
            if (is_array($product_parameters)) {
                $sources[] = [
                    'path' => sprintf('productSet[%d].product.parameters', (int) $index),
                    'parameters' => $product_parameters,
                ];
            }

            $item_parameters = $product_set_item['parameters'] ?? null;
            if (is_array($item_parameters)) {
                $sources[] = [
                    'path' => sprintf('productSet[%d].parameters', (int) $index),
                    'parameters' => $item_parameters,
                ];
            }
        }

        return $sources;
    }

    private function normalize_stock_available($raw_value): ?int
    {
        if ($raw_value === null || $raw_value === '') {
            return null;
        }

        if (!is_numeric($raw_value)) {
            return null;
        }

        return max(0, (int) $raw_value);
    }

    private function apply_stock_state(WC_Product $product, string $publication_status, ?int $stock_available): void
    {
        if ($stock_available !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_available);
            $product->set_stock_status($stock_available > 0 ? 'instock' : 'outofstock');
            return;
        }

        if ($publication_status !== 'ACTIVE') {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
        }
    }

    private function map_product_status(string $publication_status, ?int $stock_available, array $settings): string
    {
        if ($publication_status === 'ACTIVE' && ($stock_available === null || $stock_available > 0)) {
            return 'publish';
        }

        $inactive = $settings['inactive_product_status'] ?? 'draft';
        return in_array($inactive, ['draft', 'private'], true) ? $inactive : 'draft';
    }

    private function assign_category(int $product_id, array $offer): void
    {
        $allegro_category_id = sanitize_text_field((string) ($offer['category']['id'] ?? ''));
        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'checkpoint' => 'assign_category_start',
            'allegro_category_id' => $allegro_category_id,
        ]);
        if ($allegro_category_id === '') {
            $this->logger->warning('CATEGORY_ASSIGNMENT_CHECKPOINT', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'checkpoint' => 'assign_category_skipped_missing_allegro_category_id',
            ]);
            return;
        }

        $allegro_category_name = sanitize_text_field((string) ($offer['category']['name'] ?? ''));
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'checkpoint' => 'get_category_path_start',
            'allegro_category_id' => $allegro_category_id,
        ]);
        $category_path = $this->extract_allegro_category_path($offer, $allegro_category_id, $allegro_category_name);
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'checkpoint' => 'get_category_path_done',
            'path_nodes_count' => count($category_path),
        ]);
        if ($category_path === []) {
            $this->logger->warning('CATEGORY_ASSIGNMENT_CHECKPOINT', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'checkpoint' => 'assign_category_skipped_empty_path',
                'allegro_category_id' => $allegro_category_id,
            ]);
            return;
        }

        $parent_term_id = 0;
        $leaf_term_id = 0;
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'checkpoint' => 'create_or_update_terms_for_path_start',
            'path_nodes_count' => count($category_path),
        ]);

        foreach ($category_path as $node) {
            $node_id = sanitize_text_field((string) ($node['id'] ?? ''));
            $node_name = sanitize_text_field((string) ($node['name'] ?? ''));
            if ($node_id === '' || $node_name === '') {
                continue;
            }

            $term_id = $this->find_or_create_category_term($node_id, $node_name, $parent_term_id);
            if ($term_id <= 0) {
                continue;
            }

            $parent_term_id = $term_id;
            $leaf_term_id = $term_id;
        }
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'checkpoint' => 'create_or_update_terms_for_path_done',
            'leaf_term_id' => $leaf_term_id,
        ]);

        if ($leaf_term_id > 0) {
            $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'checkpoint' => 'wp_set_post_terms_start',
                'leaf_term_id' => $leaf_term_id,
            ]);
            wp_set_post_terms($product_id, [$leaf_term_id], 'product_cat', false);
            $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'checkpoint' => 'wp_set_post_terms_done',
                'leaf_term_id' => $leaf_term_id,
            ]);
        }
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'checkpoint' => 'assign_category_done',
        ]);
    }

    private function extract_allegro_category_path(array $offer, string $category_id, string $fallback_name): array
    {
        if (isset($this->category_path_cache[$category_id])) {
            return $this->category_path_cache[$category_id];
        }

        $offer_id = sanitize_text_field((string) ($offer['id'] ?? ''));
        $raw_path = $offer['category']['path'] ?? null;
        if (is_array($raw_path) && $raw_path !== []) {
            $mapped = [];
            foreach ($raw_path as $item) {
                $id = sanitize_text_field((string) ($item['id'] ?? ''));
                $name = sanitize_text_field((string) ($item['name'] ?? ''));
                if ($id !== '' && $name !== '') {
                    $mapped[] = ['id' => $id, 'name' => $name];
                }
            }

            if ($mapped !== []) {
                $this->category_path_cache[$category_id] = $mapped;
                return $mapped;
            }
        }

        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'checkpoint' => 'category_path_api_fetch_start',
            'category_id' => $category_id,
        ]);
        $path = $this->client->get_category_path($category_id);
        $this->logger->info('CATEGORY_ASSIGNMENT_CHECKPOINT', [
            'offer_id' => $offer_id,
            'checkpoint' => 'category_path_api_fetch_done',
            'category_id' => $category_id,
            'is_error' => is_wp_error($path),
            'nodes_count' => is_array($path) ? count($path) : 0,
        ]);
        if (is_wp_error($path)) {
            $this->logger->warning('Failed to fetch Allegro category path; using fallback category node.', [
                'category_id' => $category_id,
                'error' => $path->get_error_message(),
            ]);

            $name = $fallback_name !== '' ? $fallback_name : $category_id;
            $fallback_path = [['id' => $category_id, 'name' => $name]];
            $this->category_path_cache[$category_id] = $fallback_path;
            return $fallback_path;
        }

        if (is_array($path) && $path !== []) {
            $this->category_path_cache[$category_id] = $path;
            return $path;
        }

        $name = $fallback_name !== '' ? $fallback_name : $category_id;
        $fallback_path = [['id' => $category_id, 'name' => $name]];
        $this->category_path_cache[$category_id] = $fallback_path;
        return $fallback_path;
    }

    private function find_or_create_category_term(string $allegro_category_id, string $name, int $parent_term_id): int
    {
        if (isset($this->category_term_id_cache[$allegro_category_id])) {
            return $this->category_term_id_cache[$allegro_category_id];
        }

        $existing = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 1,
            'meta_query' => [
                [
                    'key' => '_allegro_category_id',
                    'value' => $allegro_category_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if (is_array($existing) && !empty($existing[0]) && $existing[0] instanceof \WP_Term) {
            $term = $existing[0];
            $updates = [];
            if ((int) $term->parent !== $parent_term_id) {
                $updates['parent'] = $parent_term_id;
            }
            if ($term->name !== $name) {
                $updates['name'] = $name;
            }
            if ($updates !== []) {
                wp_update_term($term->term_id, 'product_cat', $updates);
            }

            update_term_meta($term->term_id, '_allegro_category_id', $allegro_category_id);
            $this->category_term_id_cache[$allegro_category_id] = (int) $term->term_id;
            return (int) $term->term_id;
        }

        $term = term_exists($name, 'product_cat', $parent_term_id);
        if ($term) {
            $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
            if ($term_id > 0) {
                update_term_meta($term_id, '_allegro_category_id', $allegro_category_id);
                $this->category_term_id_cache[$allegro_category_id] = $term_id;
                return $term_id;
            }
        }

        $created = wp_insert_term($name, 'product_cat', [
            'parent' => $parent_term_id,
        ]);

        if (is_wp_error($created)) {
            $this->logger->error('Failed to create WooCommerce category for Allegro category.', [
                'allegro_category_id' => $allegro_category_id,
                'name' => $name,
                'parent_term_id' => $parent_term_id,
                'error' => $created->get_error_message(),
            ]);
            return 0;
        }

        $term_id = (int) ($created['term_id'] ?? 0);
        if ($term_id > 0) {
            update_term_meta($term_id, '_allegro_category_id', $allegro_category_id);
            $this->category_term_id_cache[$allegro_category_id] = $term_id;
        }

        return $term_id;
    }

    private function map_attributes(WC_Product $product, array $offer): void
    {
        $attributes = [];

        foreach (($offer['parameters'] ?? []) as $parameter) {
            $name = sanitize_text_field((string) ($parameter['name'] ?? ''));
            $values = array_map('sanitize_text_field', $parameter['values'] ?? []);
            if ($name === '' || empty($values)) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name($name);
            $attribute->set_options($values);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }
    }

    private function extract_offer_url(array $offer): string
    {
        if (!empty($offer['external']['id'])) {
            return 'https://allegro.pl/oferta/' . rawurlencode((string) $offer['external']['id']);
        }

        if (!empty($offer['id'])) {
            return 'https://allegro.pl/oferta/' . rawurlencode((string) $offer['id']);
        }

        return '';
    }

    private function sync_product_images(WC_Product $product, array $offer, string $offer_id, bool $is_new_product = false): void
    {
        $this->logger->info('IMAGE_SYNC_BUILD_INFO', [
            'build_marker' => 'awi-image-sync-build-2026-04-25-v2',
            'file' => __FILE__,
            'plugin_version' => defined('AWI_VERSION') ? (string) AWI_VERSION : 'undefined',
        ]);

        $raw_images_preview = [];
        if (is_array($offer['images'] ?? null)) {
            $raw_images_preview = (array) $offer['images'];
        } elseif (is_array($offer['productSet'] ?? null)) {
            foreach ((array) $offer['productSet'] as $product_set_item) {
                if (!is_array($product_set_item)) {
                    continue;
                }

                if (is_array($product_set_item['product']['images'] ?? null)) {
                    $raw_images_preview = (array) $product_set_item['product']['images'];
                    break;
                }

                if (is_array($product_set_item['images'] ?? null)) {
                    $raw_images_preview = (array) $product_set_item['images'];
                    break;
                }
            }
        }
        $first_image_raw = $raw_images_preview[0] ?? null;
        $first_image_url_extracted = $this->extract_single_image_url_from_payload_item($first_image_raw);
        $this->logger->info('IMAGE_SYNC_START_INPUT', [
            'offer_id' => $offer_id,
            'images_count' => count($raw_images_preview),
            'first_image_raw' => $first_image_raw,
            'first_image_raw_type' => gettype($first_image_raw),
            'first_image_extracted_url' => $first_image_url_extracted,
        ]);
        $this->logger->info('IMAGE_SYNC_AFTER_START_INPUT', [
            'offer_id' => $offer_id,
            'product_id' => (int) $product->get_id(),
            'is_new_product' => (bool) $is_new_product,
            'awi_skip_images_flag' => defined('AWI_SKIP_IMAGES') ? (bool) AWI_SKIP_IMAGES : false,
        ]);

        $product_id = $product->get_id();
        if ($product_id <= 0) {
            $this->logger->error('Cannot sync images for unsaved product.', ['offer_id' => $offer_id]);
            $this->logger->warning('IMAGE_SYNC_EARLY_RETURN', [
                'offer_id' => $offer_id,
                'reason' => 'unsaved_product',
                'product_id' => (int) $product_id,
            ]);
            return;
        }

        $image_urls = $this->extract_image_urls_from_offer_payload($offer);
        $images_count = count($image_urls);
        $first_image_url = $images_count > 0 ? (string) $image_urls[0] : '';
        $this->logger->info('IMAGE_SYNC_AFTER_NORMALIZE_URLS', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'normalized_images_count' => $images_count,
            'first_normalized_url' => $first_image_url,
        ]);
        $this->logger->info('IMAGE_SYNC_BEFORE_EARLY_RETURNS', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'normalized_images_count' => $images_count,
            'first_normalized_url' => $first_image_url,
        ]);
        $this->logger->info('IMAGE_SYNC_INPUT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'images_count' => $images_count,
            'first_image_url' => $first_image_url,
            'has_top_level_images_array' => is_array($offer['images'] ?? null),
            'product_set_count' => is_array($offer['productSet'] ?? null) ? count((array) $offer['productSet']) : 0,
        ]);

        if (defined('AWI_SKIP_IMAGES') && AWI_SKIP_IMAGES) {
            $this->logger->warning('AWI_SKIP_IMAGES flag detected but continuing because normalized images were found.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'images_count' => $images_count,
            ]);
        }

        $this->logger->info('Starting product image sync.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'images_found' => $images_count,
        ]);

        $this->logger->info('Normalized image URLs count.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'normalized_image_urls_count' => count($image_urls),
        ]);

        if (empty($image_urls)) {
            $this->logger->warning('No valid image URLs found after normalization.', ['offer_id' => $offer_id, 'product_id' => $product_id]);
            $this->logger->warning('IMAGE_SYNC_EARLY_RETURN', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'reason' => 'no_normalized_image_urls',
            ]);
            return;
        }

        $this->logger->info('IMAGE_SYNC_BEFORE_DOWNLOAD_LOOP', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'normalized_images_count' => count($image_urls),
            'first_normalized_url' => (string) ($image_urls[0] ?? ''),
        ]);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $gallery_ids = [];
        $this->logger->info('IMAGE_DOWNLOAD_LOOP_ENTER', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'normalized_images_count' => count($image_urls),
            'first_normalized_url' => (string) ($image_urls[0] ?? ''),
        ]);

        foreach ($image_urls as $index => $url) {
            $image_no = (int) $index + 1;
            $this->logger->info('Image import started.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
            ]);
            $this->logger->info('IMAGE_DOWNLOAD_START', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'image_url' => $url,
            ]);
            $this->persist_last_image_checkpoint([
                'stage' => 'before_attachment_lookup',
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
            ]);
            $existing_attachment_id = $this->find_existing_attachment_by_source($url);
            if ($existing_attachment_id > 0) {
                $this->logger->info('Reusing existing attachment for image URL.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => $existing_attachment_id]);
                $this->logger->info('Sideload/reuse result attachment ID.', ['offer_id' => $offer_id, 'product_id' => $product_id, 'url' => $url, 'attachment_id' => (int) $existing_attachment_id, 'source' => 'reuse']);
                $this->logger->info('IMAGE_DOWNLOAD_DONE', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'image_index' => $image_no,
                    'images_total' => count($image_urls),
                    'image_url' => $url,
                    'attachment_id' => (int) $existing_attachment_id,
                    'source' => 'reuse',
                ]);
                $gallery_ids[] = $existing_attachment_id;
                continue;
            }

            $attachment_id = $this->sideload_image_attachment($url, $product_id, $offer_id);
            if (is_wp_error($attachment_id)) {
                $this->logger->error('IMAGE_DOWNLOAD_FAILED', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'image_index' => $image_no,
                    'images_total' => count($image_urls),
                    'image_url' => $url,
                    'error_reason' => $attachment_id->get_error_message(),
                    'error_code' => $attachment_id->get_error_code(),
                ]);
                $this->logger->error('Image sideload failed.', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'image_index' => $image_no,
                    'images_total' => count($image_urls),
                    'url' => $url,
                    'error_code' => $attachment_id->get_error_code(),
                    'error_message' => $attachment_id->get_error_message(),
                    'error_data' => $attachment_id->get_error_data(),
                ]);
                continue;
            }
            $this->logger->info('Image sideload succeeded.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
                'attachment_id' => (int) $attachment_id,
                'source' => 'sideload',
            ]);
            $this->logger->info('IMAGE_DOWNLOAD_DONE', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'image_url' => $url,
                'attachment_id' => (int) $attachment_id,
                'source' => 'sideload',
            ]);

            update_post_meta($attachment_id, '_awi_source_url', $url);
            $gallery_ids[] = (int) $attachment_id;
            $this->logger->info('Attachment created for image URL.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_index' => $image_no,
                'images_total' => count($image_urls),
                'url' => $url,
                'attachment_id' => (int) $attachment_id,
            ]);
        }

        $gallery_ids = array_values(array_unique(array_map('intval', $gallery_ids)));

        if (empty($gallery_ids)) {
            $this->logger->warning('No valid image attachments were created for offer.', ['offer_id' => $offer_id, 'product_id' => $product_id]);
            return;
        }

        $featured_id = (int) $gallery_ids[0];
        $gallery_only = array_values(array_filter(array_map('intval', array_slice($gallery_ids, 1))));

        $product->set_image_id($featured_id);
        $product->set_gallery_image_ids($gallery_only);
        update_post_meta($product_id, '_thumbnail_id', $featured_id);
        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_only));

        $this->logger->info('Final product image ID.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'final_image_id' => (int) $product->get_image_id(),
            'thumbnail_meta' => (int) get_post_meta($product_id, '_thumbnail_id', true),
        ]);
        $this->logger->info('Final product gallery image IDs.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'final_gallery_image_ids' => array_map('intval', $product->get_gallery_image_ids()),
            'gallery_meta' => sanitize_text_field((string) get_post_meta($product_id, '_product_image_gallery', true)),
        ]);

        $this->logger->info('Product image sync completed.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'featured_attachment_id' => $featured_id,
            'gallery_count' => count($gallery_only),
        ]);
        $this->logger->info('IMAGE_ATTACH_RESULT', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'attachment_id' => $featured_id,
            'set_as_featured' => true,
            'added_to_gallery' => false,
        ]);
        foreach ($gallery_only as $gallery_attachment_id) {
            $this->logger->info('IMAGE_ATTACH_RESULT', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'attachment_id' => (int) $gallery_attachment_id,
                'set_as_featured' => false,
                'added_to_gallery' => true,
            ]);
        }

        $listing_image_result = $this->ensure_listing_image_for_product($product_id, true);
        $listing_status = sanitize_key((string) ($listing_image_result['status'] ?? 'unknown'));
        $listing_diagnostics = $this->get_listing_image_diagnostics($product_id);
        $this->logger->info('Listing image generation after import.', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'result' => $listing_image_result,
        ]);

        if ($is_new_product) {
            $this->logger->info('New Allegro product listing image pipeline summary.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'images_fetched_count' => count($image_urls),
                'featured_image_id' => $featured_id,
                'gallery_image_ids' => $gallery_only,
                'listing_image_status' => $listing_status,
                'listing_image_id' => (int) ($listing_image_result['listing_image_id'] ?? 0),
                'listing_source_image_id' => (int) ($listing_image_result['selected_source_image_id'] ?? 0),
                'listing_selection_reason' => (string) ($listing_image_result['selected_source_selection_reason'] ?? ''),
                'listing_quality_tier' => (string) ($listing_image_result['listing_quality_tier'] ?? ''),
                'listing_quality_score' => round((float) ($listing_image_result['listing_quality_score'] ?? 0.0), 6),
                'helper_selected_image_id' => (int) ($listing_diagnostics['helper_selected_image_id'] ?? 0),
                'rendered_source' => (string) ($listing_diagnostics['rendered_source'] ?? ''),
            ]);
        }
    }

    /**
     * @return int|\WP_Error
     */
    private function sideload_image_attachment(string $image_url, int $product_id, string $offer_id = '')
    {
        $download_started_at = microtime(true);
        $tmp_file = download_url($image_url, self::IMAGE_HTTP_TIMEOUT_SECONDS);
        $download_elapsed = round(max(0, microtime(true) - $download_started_at), 3);
        $this->logger->info('Image HTTP request completed.', [
            'request_type' => 'image_download',
            'endpoint' => $this->sanitize_http_endpoint_for_logs($image_url),
            'host' => (string) parse_url($image_url, PHP_URL_HOST),
            'timeout' => self::IMAGE_HTTP_TIMEOUT_SECONDS,
            'elapsed_time' => $download_elapsed,
            'http_code' => 0,
            'error_reason' => is_wp_error($tmp_file) ? $tmp_file->get_error_message() : '',
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'image_url' => $image_url,
        ]);
        if ($download_elapsed >= self::IMAGE_HTTP_SLOW_REQUEST_SECONDS) {
            $this->logger->warning('Image HTTP request slow.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_url' => $image_url,
                'elapsed_time' => $download_elapsed,
                'slow_threshold_seconds' => self::IMAGE_HTTP_SLOW_REQUEST_SECONDS,
            ]);
        }
        if (is_wp_error($tmp_file)) {
            $this->logger->error('IMAGE_DOWNLOAD_FAILED', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_url' => $image_url,
                'error_reason' => $tmp_file->get_error_message(),
                'error_code' => $tmp_file->get_error_code(),
            ]);
            return $tmp_file;
        }

        if (!is_string($tmp_file) || $tmp_file === '') {
            return new \WP_Error('image_download_failed', __('Nie udało się pobrać obrazka.', 'allegro-woo-importer'));
        }

        $skip_reason = $this->get_heavy_image_skip_reason($tmp_file);
        if ($skip_reason !== null) {
            $this->logger->warning('Skipping heavy image before sideload metadata generation.', [
                'product_id' => $product_id,
                'url' => $image_url,
                'skip_reason' => $skip_reason,
            ]);
            if (file_exists($tmp_file)) {
                @unlink($tmp_file);
            }

            return new \WP_Error('image_skipped_heavy', $skip_reason);
        }

        $filename = $this->build_sideload_filename_from_url_and_headers($image_url, $tmp_file, $offer_id, $product_id);
        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp_file,
        ];

        $this->persist_last_image_checkpoint([
            'stage' => 'before_media_handle_sideload',
            'product_id' => $product_id,
            'url' => $image_url,
            'filename' => $filename,
            'tmp_file' => $tmp_file,
            'file_size_bytes' => (int) @filesize($tmp_file),
        ]);

        $this->begin_image_import_runtime_limits([
            'product_id' => $product_id,
            'url' => $image_url,
            'filename' => $filename,
        ]);
        try {
            $attachment_id = media_handle_sideload($file_array, $product_id);
        } finally {
            $this->end_image_import_runtime_limits();
        }

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp_file)) {
                @unlink($tmp_file);
            }
            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    /**
     * @return string[]
     */
    private function extract_image_urls_from_offer_payload(array $offer): array
    {
        $raw_images = [];

        if (is_array($offer['images'] ?? null)) {
            $raw_images = array_merge($raw_images, (array) $offer['images']);
        }

        $product_set = $offer['productSet'] ?? [];
        if (is_array($product_set)) {
            foreach ($product_set as $product_set_item) {
                if (!is_array($product_set_item)) {
                    continue;
                }

                if (is_array($product_set_item['product']['images'] ?? null)) {
                    $raw_images = array_merge($raw_images, (array) $product_set_item['product']['images']);
                }

                if (is_array($product_set_item['images'] ?? null)) {
                    $raw_images = array_merge($raw_images, (array) $product_set_item['images']);
                }
            }
        }

        $image_urls = [];
        foreach ($raw_images as $image) {
            $url = $this->extract_single_image_url_from_payload_item($image);
            $url = trim($url);
            if ($url === '') {
                continue;
            }

            $image_urls[] = $url;
        }

        return array_values(array_unique($image_urls));
    }

    /**
     * @param mixed $image
     */
    private function extract_single_image_url_from_payload_item($image): string
    {
        if (is_string($image)) {
            return trim($image);
        }

        if (!is_array($image)) {
            return '';
        }

        $candidate_paths = [
            ['url'],
            ['src'],
            ['source', 'url'],
            ['source', 'src'],
            ['original', 'url'],
            ['original', 'src'],
            ['sizes', 'original', 'url'],
            ['sizes', 'large', 'url'],
            ['standard', 'url'],
            ['secure_url'],
            ['href'],
        ];

        foreach ($candidate_paths as $path) {
            $value = $image;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function build_sideload_filename_from_url_and_headers(string $url, string $tmp_file, string $offer_id = '', int $product_id = 0): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = sanitize_file_name((string) basename($path));
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'allegro-image';
        }

        $name_without_ext = pathinfo($base, PATHINFO_FILENAME);
        if ($name_without_ext === '') {
            $name_without_ext = 'allegro-image';
        }

        $extension = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if ($extension === '') {
            $mime = $this->detect_mime_from_headers($url, $offer_id, $product_id);
            if ($mime !== '') {
                $extension = $this->map_mime_to_extension($mime);
            }
        }

        if ($extension === '') {
            $extension = $this->detect_file_extension($tmp_file);
        }

        if ($extension === '') {
            $extension = 'jpg';
        }

        return sanitize_file_name($name_without_ext . '.' . $extension);
    }

    private function detect_mime_from_headers(string $url, string $offer_id = '', int $product_id = 0): string
    {
        $head_started_at = microtime(true);
        $response = wp_safe_remote_head($url, [
            'timeout' => self::IMAGE_HTTP_TIMEOUT_SECONDS,
            'redirection' => self::IMAGE_HTTP_REDIRECTION_LIMIT,
        ]);
        $head_elapsed = round(max(0, microtime(true) - $head_started_at), 3);
        $head_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $this->logger->info('Image HTTP request completed.', [
            'request_type' => 'image_download',
            'endpoint' => $this->sanitize_http_endpoint_for_logs($url),
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'timeout' => self::IMAGE_HTTP_TIMEOUT_SECONDS,
            'elapsed_time' => $head_elapsed,
            'http_code' => $head_code,
            'error_reason' => is_wp_error($response) ? $response->get_error_message() : (($head_code >= 400) ? 'http_status_non_success' : ''),
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'image_url' => $url,
        ]);
        if ($head_elapsed >= self::IMAGE_HTTP_SLOW_REQUEST_SECONDS) {
            $this->logger->warning('Image HTTP request slow.', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_url' => $url,
                'elapsed_time' => $head_elapsed,
                'slow_threshold_seconds' => self::IMAGE_HTTP_SLOW_REQUEST_SECONDS,
                'request_method' => 'HEAD',
            ]);
        }

        if (is_wp_error($response) || $head_code >= 400) {
            $get_started_at = microtime(true);
            $response = wp_safe_remote_get($url, [
                'timeout' => self::IMAGE_HTTP_TIMEOUT_SECONDS,
                'redirection' => self::IMAGE_HTTP_REDIRECTION_LIMIT,
                'headers' => ['Range' => 'bytes=0-0'],
            ]);
            $get_elapsed = round(max(0, microtime(true) - $get_started_at), 3);
            $get_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $this->logger->info('Image HTTP request completed.', [
                'request_type' => 'image_download',
                'endpoint' => $this->sanitize_http_endpoint_for_logs($url),
                'host' => (string) parse_url($url, PHP_URL_HOST),
                'timeout' => self::IMAGE_HTTP_TIMEOUT_SECONDS,
                'elapsed_time' => $get_elapsed,
                'http_code' => $get_code,
                'error_reason' => is_wp_error($response) ? $response->get_error_message() : (($get_code >= 400) ? 'http_status_non_success' : ''),
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'image_url' => $url,
            ]);
            if ($get_elapsed >= self::IMAGE_HTTP_SLOW_REQUEST_SECONDS) {
                $this->logger->warning('Image HTTP request slow.', [
                    'offer_id' => $offer_id,
                    'product_id' => $product_id,
                    'image_url' => $url,
                    'elapsed_time' => $get_elapsed,
                    'slow_threshold_seconds' => self::IMAGE_HTTP_SLOW_REQUEST_SECONDS,
                    'request_method' => 'GET',
                ]);
            }
        }

        if (is_wp_error($response)) {
            return '';
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ($content_type === '') {
            return '';
        }

        $parts = explode(';', $content_type);
        return strtolower(trim((string) ($parts[0] ?? '')));
    }

    private function sanitize_http_endpoint_for_logs(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return '/';
        }

        return $path;
    }

    private function map_mime_to_extension(string $mime): string
    {
        $mime_to_extension = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tif',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
        ];

        return $mime_to_extension[strtolower($mime)] ?? '';
    }

    private function detect_file_extension(string $tmp_file): string
    {
        $mime = '';

        if (function_exists('wp_get_image_mime')) {
            $mime = (string) wp_get_image_mime($tmp_file);
        }

        if ($mime === '' && function_exists('mime_content_type')) {
            $detected = mime_content_type($tmp_file);
            if (is_string($detected)) {
                $mime = $detected;
            }
        }

        if ($mime === '' && function_exists('getimagesize')) {
            $image_info = @getimagesize($tmp_file);
            if (is_array($image_info) && !empty($image_info['mime']) && is_string($image_info['mime'])) {
                $mime = $image_info['mime'];
            }
        }

        $extension = $this->map_mime_to_extension($mime);
        return $extension !== '' ? $extension : 'jpg';
    }

    private function find_existing_attachment_by_source(string $url): int
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_awi_source_url',
                    'value' => $url,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function collect_missing_fields(array $offer, string $title, string $description, ?float $price): array
    {
        $missing = [];

        if ($title === '' || $title === __('Oferta Allegro', 'allegro-woo-importer')) {
            $missing[] = 'name';
        }

        if ($description === '') {
            $missing[] = 'description';
        }

        if ($price === null) {
            $missing[] = 'sellingMode.price.amount';
        }

        if (empty($offer['images']) || !is_array($offer['images'])) {
            $missing[] = 'images';
        }

        if (empty($offer['publication']['status'])) {
            $missing[] = 'publication.status';
        }

        if (!isset($offer['stock']['available'])) {
            $missing[] = 'stock.available';
        }

        if (empty($offer['external']['id'])) {
            $missing[] = 'external.id';
        }

        return $missing;
    }

    private function get_heavy_image_skip_reason(string $tmp_file): ?string
    {
        $file_size = @filesize($tmp_file);
        if (is_int($file_size) && $file_size > self::MAX_IMAGE_FILE_SIZE_BYTES) {
            return sprintf(
                'Plik obrazu pominięty: %d B > %d B limitu.',
                $file_size,
                self::MAX_IMAGE_FILE_SIZE_BYTES
            );
        }

        $image_size = @getimagesize($tmp_file);
        if (!is_array($image_size)) {
            return 'Plik obrazu pominięty: nie udało się odczytać wymiarów.';
        }

        $width = isset($image_size[0]) ? (int) $image_size[0] : 0;
        $height = isset($image_size[1]) ? (int) $image_size[1] : 0;
        if ($width <= 0 || $height <= 0) {
            return 'Plik obrazu pominięty: nieprawidłowe wymiary.';
        }

        $total_pixels = $width * $height;
        if ($total_pixels > self::MAX_IMAGE_TOTAL_PIXELS) {
            return sprintf(
                'Plik obrazu pominięty: %d px > %d px limitu.',
                $total_pixels,
                self::MAX_IMAGE_TOTAL_PIXELS
            );
        }

        return null;
    }

    private function begin_image_import_runtime_limits(array $context): void
    {
        $this->image_import_context = [
            'active' => true,
            'context' => $context,
        ];

        add_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_image_sizes_advanced'], 9999, 2);
        add_filter('fallback_intermediate_image_sizes', [$this, 'filter_fallback_intermediate_image_sizes'], 9999, 2);
        add_filter('big_image_size_threshold', [$this, 'disable_big_image_size_threshold_for_import'], 9999, 4);
        add_filter('wp_image_editors', [$this, 'prefer_gd_image_editor_for_import'], 9999, 1);
    }

    private function end_image_import_runtime_limits(): void
    {
        remove_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_image_sizes_advanced'], 9999);
        remove_filter('fallback_intermediate_image_sizes', [$this, 'filter_fallback_intermediate_image_sizes'], 9999);
        remove_filter('big_image_size_threshold', [$this, 'disable_big_image_size_threshold_for_import'], 9999);
        remove_filter('wp_image_editors', [$this, 'prefer_gd_image_editor_for_import'], 9999);
        $this->image_import_context = [];
    }

    public function filter_intermediate_image_sizes_advanced(array $sizes): array
    {
        if (!$this->is_image_import_context_active()) {
            return $sizes;
        }

        return array_intersect_key($sizes, array_flip(self::IMPORT_ALLOWED_SUBSIZES));
    }

    public function filter_fallback_intermediate_image_sizes(array $fallback_sizes): array
    {
        if (!$this->is_image_import_context_active()) {
            return $fallback_sizes;
        }

        return self::IMPORT_ALLOWED_SUBSIZES;
    }

    public function disable_big_image_size_threshold_for_import($threshold)
    {
        if (!$this->is_image_import_context_active()) {
            return $threshold;
        }

        return false;
    }

    public function prefer_gd_image_editor_for_import(array $editors): array
    {
        if (!$this->is_image_import_context_active()) {
            return $editors;
        }

        if (!in_array('WP_Image_Editor_GD', $editors, true)) {
            return $editors;
        }

        $filtered = array_values(array_filter($editors, static fn($editor): bool => $editor !== 'WP_Image_Editor_GD'));
        array_unshift($filtered, 'WP_Image_Editor_GD');

        return $filtered;
    }

    private function is_image_import_context_active(): bool
    {
        return !empty($this->image_import_context['active']);
    }

    private function persist_last_image_checkpoint(array $checkpoint): void
    {
        $checkpoint['logged_at'] = gmdate('Y-m-d H:i:s');
        update_option('awi_last_image_import_checkpoint', wp_json_encode($checkpoint), false);
        $this->logger->info('Image import checkpoint.', $checkpoint);
    }

    /**
     * @return int|\WP_Error
     */
    private function create_listing_image_attachment(int $source_attachment_id, int $product_id, string $render_profile = 'standard')
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return new \WP_Error('gd_missing', __('Brak biblioteki GD wymaganej do generowania obrazu listingowego.', 'allegro-woo-importer'));
        }

        $source_path = get_attached_file($source_attachment_id);
        if (!is_string($source_path) || $source_path === '' || !file_exists($source_path)) {
            return new \WP_Error('missing_source_file', __('Brak pliku źródłowego dla zdjęcia listingowego.', 'allegro-woo-importer'));
        }

        $source_blob = file_get_contents($source_path);
        if (!is_string($source_blob) || $source_blob === '') {
            return new \WP_Error('source_read_failed', __('Nie udało się odczytać pliku źródłowego.', 'allegro-woo-importer'));
        }

        $source_image = @imagecreatefromstring($source_blob);
        if (!$source_image) {
            return new \WP_Error('source_decode_failed', __('Nie udało się odczytać obrazu źródłowego.', 'allegro-woo-importer'));
        }

        $source_width = imagesx($source_image);
        $source_height = imagesy($source_image);
        if ($source_width <= 0 || $source_height <= 0) {
            imagedestroy($source_image);
            return new \WP_Error('invalid_source_dimensions', __('Nieprawidłowe wymiary obrazu źródłowego.', 'allegro-woo-importer'));
        }
        $canvas_size = self::LISTING_IMAGE_CANVAS_SIZE;
        $target_ratio = 0.96;
        $safe_margin_ratio = 0.02;
        $crop_padding_ratio = 0.08;
        if ($render_profile === 'boost_wide') {
            $target_ratio = 0.995;
            $safe_margin_ratio = 0.002;
            $crop_padding_ratio = 0.02;
        } elseif ($render_profile === 'boost_tall') {
            $target_ratio = 0.995;
            $safe_margin_ratio = 0.002;
            $crop_padding_ratio = 0.02;
        } elseif ($render_profile === 'boost_generic') {
            $target_ratio = 0.99;
            $safe_margin_ratio = 0.005;
            $crop_padding_ratio = 0.03;
        }
        $usable_canvas_size = max(1, (int) floor($canvas_size * (1 - (2 * $safe_margin_ratio))));
        $target_object_size = max(1, (int) round($usable_canvas_size * $target_ratio));
        $bbox = $this->detect_non_white_bbox($source_image, $source_width, $source_height);
        $object_x = 0;
        $object_y = 0;
        $object_width = $source_width;
        $object_height = $source_height;
        if (is_array($bbox)) {
            $object_x = (int) $bbox['x'];
            $object_y = (int) $bbox['y'];
            $object_width = (int) $bbox['width'];
            $object_height = (int) $bbox['height'];
        }

        $object_max_size = max($object_width, $object_height);
        $object_min_size = max(1, min($object_width, $object_height));
        $source_aspect_ratio = $source_width / max(1, $source_height);
        $object_aspect_ratio = $object_width / max(1, $object_height);
        $is_extreme_object_ratio = ($object_aspect_ratio < 0.65 || $object_aspect_ratio > 1.55);

        $fit_mode = 'contain_full';
        $used_crop = 0;
        $crop_x = $object_x;
        $crop_y = $object_y;
        $crop_width = $object_width;
        $crop_height = $object_height;

        $use_quality_boost_profile = $render_profile !== 'standard';
        if ($is_extreme_object_ratio || $use_quality_boost_profile) {
            $fit_mode = $use_quality_boost_profile ? ('quality_boost_' . $render_profile) : 'smart_crop_square';
            $used_crop = 1;
            if ($render_profile === 'boost_wide') {
                $desired_side = (int) round($object_height * (1 + $crop_padding_ratio));
            } elseif ($render_profile === 'boost_tall') {
                $desired_side = (int) round($object_width * (1 + $crop_padding_ratio));
            } else {
                $desired_side = (int) round(max($object_width, $object_height) * (1 + $crop_padding_ratio));
            }
            $crop_side = max($object_min_size, min($desired_side, min($source_width, $source_height)));

            $object_center_x = $object_x + ($object_width / 2);
            $object_center_y = $object_y + ($object_height / 2);

            $crop_x = (int) round($object_center_x - ($crop_side / 2));
            $crop_y = (int) round($object_center_y - ($crop_side / 2));

            if ($crop_x < 0) {
                $crop_x = 0;
            }
            if ($crop_y < 0) {
                $crop_y = 0;
            }
            if (($crop_x + $crop_side) > $source_width) {
                $crop_x = $source_width - $crop_side;
            }
            if (($crop_y + $crop_side) > $source_height) {
                $crop_y = $source_height - $crop_side;
            }

            $crop_width = max(1, $crop_side);
            $crop_height = max(1, $crop_side);
        }

        $render_source_max_size = max($crop_width, $crop_height);
        $scale = $target_object_size / max(1, $render_source_max_size);
        if (!is_finite($scale) || $scale <= 0) {
            $scale = 1.0;
        }
        $target_width = max(1, (int) round($crop_width * $scale));
        $target_height = max(1, (int) round($crop_height * $scale));
        $dst_x = (int) floor(($canvas_size - $target_width) / 2);
        $dst_y = (int) floor(($canvas_size - $target_height) / 2);
        $fill_ratio = max($target_width, $target_height) / max(1, $canvas_size);

        $canvas = imagecreatetruecolor($canvas_size, $canvas_size);
        if (!$canvas) {
            imagedestroy($source_image);
            return new \WP_Error('canvas_create_failed', __('Nie udało się utworzyć płótna dla obrazu listingowego.', 'allegro-woo-importer'));
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled(
            $canvas,
            $source_image,
            $dst_x,
            $dst_y,
            $crop_x,
            $crop_y,
            $target_width,
            $target_height,
            $crop_width,
            $crop_height
        );

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            imagedestroy($source_image);
            imagedestroy($canvas);
            return new \WP_Error('upload_dir_error', (string) $upload_dir['error']);
        }

        $source_filename = (string) pathinfo((string) basename($source_path), PATHINFO_FILENAME);
        $target_filename = wp_unique_filename((string) $upload_dir['path'], sanitize_file_name($source_filename . '-awi-listing.jpg'));
        $target_path = trailingslashit((string) $upload_dir['path']) . $target_filename;

        $saved = imagejpeg($canvas, $target_path, 90);
        imagedestroy($source_image);
        imagedestroy($canvas);
        if (!$saved || !file_exists($target_path)) {
            return new \WP_Error('listing_image_save_failed', __('Nie udało się zapisać obrazu listingowego.', 'allegro-woo-importer'));
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'image/jpeg',
            'post_title' => sanitize_text_field((string) get_the_title($source_attachment_id)) . ' listing',
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $product_id,
        ], $target_path, $product_id);

        if (!$attachment_id || is_wp_error($attachment_id)) {
            @unlink($target_path);
            return new \WP_Error('listing_attachment_insert_failed', __('Nie udało się dodać załącznika obrazu listingowego.', 'allegro-woo-importer'));
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_metadata = wp_generate_attachment_metadata((int) $attachment_id, $target_path);
        if (is_array($attachment_metadata)) {
            wp_update_attachment_metadata((int) $attachment_id, $attachment_metadata);
        }

        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_FLAG_META_KEY, 1);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_META_KEY, $source_attachment_id);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_TARGET_FILL_RATIO_META_KEY, $target_ratio);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_SCALE_FACTOR_META_KEY, round($scale, 6));
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_WIDTH_META_KEY, $source_width);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_HEIGHT_META_KEY, $source_height);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_ASPECT_RATIO_META_KEY, round($source_aspect_ratio, 6));
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_WIDTH_META_KEY, $object_width);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_HEIGHT_META_KEY, $object_height);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_WIDTH_META_KEY, $target_width);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_HEIGHT_META_KEY, $target_height);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_FINAL_FIT_MODE_META_KEY, $fit_mode);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_USED_CROP_META_KEY, $used_crop);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, round($fill_ratio, 6));
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDER_PROFILE_META_KEY, $render_profile);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY, $render_profile === 'standard' ? 0 : 1);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_UPGRADED_META_KEY, 0);
        $aspect_ratio = $crop_width / max(1, $crop_height);
        $is_extreme = ($aspect_ratio < 0.55 || $aspect_ratio > 1.8);
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_ASPECT_RATIO_META_KEY, round($aspect_ratio, 6));
        update_post_meta((int) $attachment_id, self::LISTING_IMAGE_IS_EXTREME_RATIO_META_KEY, $is_extreme ? 1 : 0);

        $this->logger->info('Listing image render metrics.', [
            'product_id' => $product_id,
            'source_attachment_id' => $source_attachment_id,
            'listing_attachment_id' => (int) $attachment_id,
            'image_width' => $source_width,
            'image_height' => $source_height,
            'object_width' => $object_width,
            'object_height' => $object_height,
            'crop_width' => $crop_width,
            'crop_height' => $crop_height,
            'final_rendered_width' => $target_width,
            'final_rendered_height' => $target_height,
            'target_fill_ratio' => $target_ratio,
            'fill_ratio' => round($fill_ratio, 6),
            'scale_factor' => round($scale, 6),
            'fit_mode' => $fit_mode,
            'render_profile' => $render_profile,
            'used_crop' => $used_crop === 1,
            'fallback_used' => $used_crop === 1,
            'aspect_ratio' => round($aspect_ratio, 6),
            'is_extreme_aspect_ratio' => $is_extreme,
            'fit_limited_by' => $crop_height > $crop_width ? 'height' : 'width',
        ]);

        return (int) $attachment_id;
    }

    private function ensure_listing_attachment_generation_meta(int $listing_attachment_id, int $source_attachment_id): void
    {
        if ($listing_attachment_id <= 0) {
            return;
        }

        $target_fill_ratio_raw = get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_TARGET_FILL_RATIO_META_KEY, true);
        if (!is_numeric($target_fill_ratio_raw) || (float) $target_fill_ratio_raw <= 0) {
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_TARGET_FILL_RATIO_META_KEY, self::LISTING_IMAGE_TARGET_FILL_RATIO);
        }

        $scale_factor_raw = get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SCALE_FACTOR_META_KEY, true);
        if (!is_numeric($scale_factor_raw) || (float) $scale_factor_raw <= 0) {
            $scale = $this->calculate_listing_scale_factor_from_source($source_attachment_id);
            if ($scale !== null) {
                update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SCALE_FACTOR_META_KEY, round($scale, 6));
            }
        }

        $source_width = (int) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_WIDTH_META_KEY, true);
        $source_height = (int) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_HEIGHT_META_KEY, true);
        $object_width = (int) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_WIDTH_META_KEY, true);
        $object_height = (int) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_HEIGHT_META_KEY, true);
        $rendered_width = (int) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_WIDTH_META_KEY, true);
        $rendered_height = (int) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_HEIGHT_META_KEY, true);

        if ($source_width <= 0 || $source_height <= 0) {
            $source_metadata = wp_get_attachment_metadata($source_attachment_id);
            $source_width = is_array($source_metadata) ? (int) ($source_metadata['width'] ?? 0) : 0;
            $source_height = is_array($source_metadata) ? (int) ($source_metadata['height'] ?? 0) : 0;
            if ($source_width > 0 && $source_height > 0) {
                update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_WIDTH_META_KEY, $source_width);
                update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_HEIGHT_META_KEY, $source_height);
            }
        }
        $source_aspect_ratio = (float) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_ASPECT_RATIO_META_KEY, true);
        if ($source_aspect_ratio <= 0 && $source_width > 0 && $source_height > 0) {
            $source_aspect_ratio = $source_width / max(1, $source_height);
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SOURCE_ASPECT_RATIO_META_KEY, round($source_aspect_ratio, 6));
        }

        if ($object_width <= 0 || $object_height <= 0) {
            $object_width = max(1, $source_width);
            $object_height = max(1, $source_height);
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_WIDTH_META_KEY, $object_width);
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_OBJECT_HEIGHT_META_KEY, $object_height);
        }

        if ($rendered_width <= 0 || $rendered_height <= 0) {
            $scale_factor = (float) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_SCALE_FACTOR_META_KEY, true);
            if ($scale_factor > 0) {
                $rendered_width = max(1, (int) round($object_width * $scale_factor));
                $rendered_height = max(1, (int) round($object_height * $scale_factor));
                update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_WIDTH_META_KEY, $rendered_width);
                update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDERED_HEIGHT_META_KEY, $rendered_height);
            }
        }
        $fill_ratio = (float) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, true);
        if ($fill_ratio <= 0 && $rendered_width > 0 && $rendered_height > 0) {
            $fill_ratio = max($rendered_width, $rendered_height) / max(1, self::LISTING_IMAGE_CANVAS_SIZE);
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_FILL_RATIO_META_KEY, round($fill_ratio, 6));
        }

        $fit_mode = (string) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_FINAL_FIT_MODE_META_KEY, true);
        if ($fit_mode === '') {
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_FINAL_FIT_MODE_META_KEY, 'contain_full');
        }

        $used_crop = get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_USED_CROP_META_KEY, true);
        if ($used_crop === '') {
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_USED_CROP_META_KEY, 0);
        }

        $render_profile = (string) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDER_PROFILE_META_KEY, true);
        if ($render_profile === '') {
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_RENDER_PROFILE_META_KEY, 'standard');
        }

        $quality_boost_applied = get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY, true);
        if ($quality_boost_applied === '') {
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_APPLIED_META_KEY, 0);
        }

        $quality_boost_upgraded = get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_UPGRADED_META_KEY, true);
        if ($quality_boost_upgraded === '') {
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ATTACHMENT_QUALITY_BOOST_UPGRADED_META_KEY, 0);
        }

        $aspect_ratio = (float) get_post_meta($listing_attachment_id, self::LISTING_IMAGE_ASPECT_RATIO_META_KEY, true);
        if ($aspect_ratio <= 0) {
            $aspect_ratio = $object_width / max(1, $object_height);
            update_post_meta($listing_attachment_id, self::LISTING_IMAGE_ASPECT_RATIO_META_KEY, round($aspect_ratio, 6));
        }

        $is_extreme = ($aspect_ratio < 0.55 || $aspect_ratio > 1.8) ? 1 : 0;
        update_post_meta($listing_attachment_id, self::LISTING_IMAGE_IS_EXTREME_RATIO_META_KEY, $is_extreme);
    }

    private function determine_listing_quality_boost_profile(float $selected_source_aspect_ratio): string
    {
        if ($selected_source_aspect_ratio > 2.0) {
            return 'boost_wide';
        }

        if ($selected_source_aspect_ratio < 0.55) {
            return 'boost_tall';
        }

        return 'boost_generic';
    }

    private function detect_non_white_bbox($image, int $width, int $height): ?array
    {
        $threshold = 245;
        $min_x = $width;
        $min_y = $height;
        $max_x = -1;
        $max_y = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $alpha = ($color >> 24) & 0x7F;
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;

                if ($alpha >= 120) {
                    continue;
                }

                if ($red >= $threshold && $green >= $threshold && $blue >= $threshold) {
                    continue;
                }

                if ($x < $min_x) {
                    $min_x = $x;
                }
                if ($y < $min_y) {
                    $min_y = $y;
                }
                if ($x > $max_x) {
                    $max_x = $x;
                }
                if ($y > $max_y) {
                    $max_y = $y;
                }
            }
        }

        if ($max_x < 0 || $max_y < 0 || $min_x > $max_x || $min_y > $max_y) {
            return null;
        }

        return [
            'x' => $min_x,
            'y' => $min_y,
            'width' => ($max_x - $min_x) + 1,
            'height' => ($max_y - $min_y) + 1,
        ];
    }

    private function get_listing_source_candidate_image_ids(int $product_id): array
    {
        $featured_id = (int) get_post_thumbnail_id($product_id);
        $gallery_ids = [];
        $product = wc_get_product($product_id);
        if ($product instanceof \WC_Product) {
            $gallery_ids = array_map('intval', $product->get_gallery_image_ids());
        }

        $candidate_ids = [];
        if ($featured_id > 0) {
            $candidate_ids[] = $featured_id;
        }

        foreach ($gallery_ids as $gallery_id) {
            if ($gallery_id > 0) {
                $candidate_ids[] = (int) $gallery_id;
            }
        }

        $candidate_ids = array_values(array_unique(array_map('intval', $candidate_ids)));
        $candidate_ids = array_values(array_filter($candidate_ids, static fn(int $attachment_id): bool => $attachment_id > 0 && get_post($attachment_id) instanceof \WP_Post));

        return $candidate_ids;
    }

    private function get_attachment_trim_metrics_for_listing_selection(int $attachment_id): ?array
    {
        $source_path = get_attached_file($attachment_id);
        if (!is_string($source_path) || $source_path === '' || !file_exists($source_path)) {
            return null;
        }

        $source_blob = file_get_contents($source_path);
        if (!is_string($source_blob) || $source_blob === '') {
            return null;
        }

        $image = @imagecreatefromstring($source_blob);
        if (!$image) {
            return null;
        }

        $source_width = imagesx($image);
        $source_height = imagesy($image);
        if ($source_width <= 0 || $source_height <= 0) {
            imagedestroy($image);
            return null;
        }

        $bbox = $this->detect_non_white_bbox($image, $source_width, $source_height);
        imagedestroy($image);

        $object_width = $source_width;
        $object_height = $source_height;
        if (is_array($bbox)) {
            $object_width = max(1, (int) ($bbox['width'] ?? $source_width));
            $object_height = max(1, (int) ($bbox['height'] ?? $source_height));
        }

        $longer_side = max($object_width, $object_height);
        $shorter_side = max(1, min($object_width, $object_height));
        $aspect_ratio = $object_width / max(1, $object_height);
        $aspect_distance_from_square = abs(1 - $aspect_ratio);
        $square_fill_ratio = $shorter_side / max(1, $longer_side);
        $object_area_ratio = ($object_width * $object_height) / max(1, ($source_width * $source_height));

        return [
            'attachment_id' => $attachment_id,
            'aspect_ratio' => $aspect_ratio,
            'aspect_distance_from_square' => $aspect_distance_from_square,
            'square_fill_ratio' => $square_fill_ratio,
            'object_area_ratio' => $object_area_ratio,
        ];
    }

    private function calculate_listing_scale_factor_from_source(int $source_attachment_id): ?float
    {
        if ($source_attachment_id <= 0) {
            return null;
        }

        $source_width = 0;
        $source_height = 0;
        $source_metadata = wp_get_attachment_metadata($source_attachment_id);
        if (is_array($source_metadata)) {
            $source_width = isset($source_metadata['width']) ? (int) $source_metadata['width'] : 0;
            $source_height = isset($source_metadata['height']) ? (int) $source_metadata['height'] : 0;
        }

        if ($source_width <= 0 || $source_height <= 0) {
            $source_path = get_attached_file($source_attachment_id);
            if (is_string($source_path) && $source_path !== '' && file_exists($source_path)) {
                $source_size = @getimagesize($source_path);
                if (is_array($source_size)) {
                    $source_width = isset($source_size[0]) ? (int) $source_size[0] : 0;
                    $source_height = isset($source_size[1]) ? (int) $source_size[1] : 0;
                }
            }
        }

        if ($source_width <= 0 || $source_height <= 0) {
            return null;
        }

        $max_object_size = (int) round(self::LISTING_IMAGE_CANVAS_SIZE * self::LISTING_IMAGE_TARGET_FILL_RATIO);
        if ($max_object_size <= 0) {
            return null;
        }

        $scale = min($max_object_size / $source_width, $max_object_size / $source_height);
        if (!is_finite($scale) || $scale <= 0) {
            return null;
        }

        return (float) $scale;
    }
}
