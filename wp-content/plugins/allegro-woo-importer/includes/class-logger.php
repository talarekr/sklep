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
