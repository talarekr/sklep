<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Logger
{
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

        $content = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($content === false) {
            return '';
        }

        $slice = array_slice($content, -abs($lines));

        return implode(PHP_EOL, $slice);
    }

    private function write(string $level, string $message, array $context): void
    {
        $payload = sprintf('[%s] [%s] %s', gmdate('Y-m-d H:i:s'), $level, $message);
        if (!empty($context)) {
            $payload .= ' ' . wp_json_encode($context);
        }

        error_log($payload . PHP_EOL, 3, $this->file);
    }
}
