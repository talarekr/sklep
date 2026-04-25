<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Logger
{
    private const MAX_LOG_LINE_BYTES = 4096;
    private const MAX_CONTEXT_DEPTH = 2;
    private const MAX_CONTEXT_ITEMS = 12;
    private const MAX_ARRAY_SAMPLE_ITEMS = 5;
    private const TAIL_READ_BYTES = 65536;
    private const LOG_THROTTLE_TRANSIENT_PREFIX = 'awi_log_throttle_';
    private const OAUTH_EXISTING_TOKEN_LOG_LIMIT_PER_MINUTE = 6;
    private const FRONTEND_PART_NUMBER_LOG_LIMIT_PER_MINUTE = 2;

    private string $file;

    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $dir = trailingslashit($upload_dir['basedir']);
        $this->file = $dir . 'allegro-import.log';
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public static function is_debug_enabled(): bool
    {
        if (defined('AWI_DEBUG_LOG')) {
            return (bool) AWI_DEBUG_LOG;
        }

        if (defined('WP_DEBUG')) {
            return (bool) WP_DEBUG;
        }

        return false;
    }

    public function read_tail(int $lines = 80): string
    {
        if (!file_exists($this->file)) {
            return '';
        }

        $handle = @fopen($this->file, 'rb');
        if (!is_resource($handle)) {
            return '';
        }

        $filesize = @filesize($this->file);
        if (!is_int($filesize) || $filesize <= 0) {
            fclose($handle);
            return '';
        }

        $read_bytes = min($filesize, self::TAIL_READ_BYTES);
        if (fseek($handle, -$read_bytes, SEEK_END) !== 0) {
            rewind($handle);
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        if (!is_string($content) || $content === '') {
            return '';
        }

        $content = ltrim($content, "\r\n");
        $rows = preg_split('/\r\n|\r|\n/', $content);
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $rows = array_values(array_filter($rows, static function ($row): bool {
            return trim((string) $row) !== '';
        }));
        if ($rows === []) {
            return '';
        }

        $slice = array_slice($rows, -max(1, abs($lines)));

        return implode(PHP_EOL, $slice);
    }

    private function write(string $level, string $message, array $context): void
    {
        $context = $this->enrich_runtime_context($context);
        if ($this->should_skip_log($level, $message, $context)) {
            return;
        }

        $payload = sprintf('[%s] [%s] %s', gmdate('Y-m-d H:i:s'), $level, $message);
        if (!empty($context)) {
            $context_json = wp_json_encode($this->normalize_context($context));
            if (is_string($context_json) && $context_json !== '') {
                $payload .= ' ' . $context_json;
            }
        }

        if (strlen($payload) > self::MAX_LOG_LINE_BYTES) {
            $payload = substr($payload, 0, self::MAX_LOG_LINE_BYTES - 12) . '...[truncated]';
        }

        error_log($payload . PHP_EOL, 3, $this->file);
    }

    private function should_skip_log(string $level, string $message, array $context): bool
    {
        if ($message === 'Frontend part number read from product meta.' && !self::is_debug_enabled()) {
            return true;
        }

        if ($message === 'OAuth using existing token.') {
            return $this->is_rate_limited($level, $message, self::OAUTH_EXISTING_TOKEN_LOG_LIMIT_PER_MINUTE, 60, $context['source'] ?? '');
        }

        if ($message === 'Frontend part number read from product meta.') {
            return $this->is_rate_limited($level, $message, self::FRONTEND_PART_NUMBER_LOG_LIMIT_PER_MINUTE, 60, $context['source'] ?? '');
        }

        return false;
    }

    private function enrich_runtime_context(array $context): array
    {
        if (!isset($context['request_id'])) {
            $context['request_id'] = $this->resolve_request_id();
        }

        if (!isset($context['pid'])) {
            $pid = function_exists('getmypid') ? getmypid() : 0;
            $context['pid'] = is_int($pid) ? $pid : 0;
        }

        if (!isset($context['source'])) {
            $context['source'] = $this->resolve_request_source();
        }

        return $context;
    }

    private function resolve_request_id(): string
    {
        static $request_id = null;
        if (is_string($request_id) && $request_id !== '') {
            return $request_id;
        }

        $header_id = isset($_SERVER['HTTP_X_REQUEST_ID']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_X_REQUEST_ID'])) : '';
        if ($header_id !== '') {
            $request_id = $header_id;
            return $request_id;
        }

        $request_id = function_exists('wp_generate_uuid4') ? (string) wp_generate_uuid4() : uniqid('awi_', true);
        return $request_id;
    }

    private function resolve_request_source(): string
    {
        if (defined('WP_CLI') && WP_CLI) {
            return 'wp_cli';
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return 'cron';
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return 'ajax';
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($request_uri, 'admin-post.php') !== false) {
            return 'admin-post';
        }

        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }

        return 'frontend';
    }

    private function is_rate_limited(string $level, string $message, int $limit, int $window_seconds, string $source): bool
    {
        $bucket = gmdate('YmdHi');
        $key = self::LOG_THROTTLE_TRANSIENT_PREFIX . md5($level . '|' . $message . '|' . $source . '|' . $bucket);
        $count = (int) get_transient($key);
        $count++;
        set_transient($key, $count, $window_seconds);

        return $count > $limit;
    }

    private function normalize_context(array $context): array
    {
        $normalized = [];
        $count = 0;

        foreach ($context as $key => $value) {
            if ($count >= self::MAX_CONTEXT_ITEMS) {
                $normalized['_truncated'] = 'context_item_limit_reached';
                break;
            }

            $normalized[(string) $key] = $this->normalize_value($value, 0);
            $count++;
        }

        return $normalized;
    }

    private function normalize_value($value, int $depth)
    {
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return '[depth_limit]';
        }

        if (is_scalar($value) || $value === null) {
            $string_value = (string) $value;
            if (strlen($string_value) > 512) {
                return substr($string_value, 0, 512) . '...[truncated]';
            }

            return $value;
        }

        if ($value instanceof \WP_Error) {
            return [
                'error_code' => $value->get_error_code(),
                'error_message' => $value->get_error_message(),
            ];
        }

        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }

        if (!is_array($value)) {
            return '[unsupported_type]';
        }

        if ($this->is_large_list_array($value)) {
            return [
                'type' => 'list',
                'count' => count($value),
                'sample' => array_map(
                    fn($item) => $this->normalize_value($item, $depth + 1),
                    array_slice($value, 0, self::MAX_ARRAY_SAMPLE_ITEMS)
                ),
            ];
        }

        $normalized = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= self::MAX_CONTEXT_ITEMS) {
                $normalized['_truncated'] = 'array_item_limit_reached';
                break;
            }

            $normalized[(string) $key] = $this->normalize_value($item, $depth + 1);
            $count++;
        }

        return $normalized;
    }

    private function is_large_list_array(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        return count($value) > self::MAX_ARRAY_SAMPLE_ITEMS;
    }
}
