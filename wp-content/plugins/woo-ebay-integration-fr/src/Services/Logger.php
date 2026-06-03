<?php

namespace WEI_FR\Services;

class Logger
{
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $encodedContext = wp_json_encode($context);
        error_log('[WEI][' . $level . '] ' . $message . ' ' . $encodedContext);

        $logs = get_option('wei_fr_logs', []);
        $logs = is_array($logs) ? $logs : [];
        array_unshift($logs, [
            'at' => gmdate('Y-m-d H:i:s'),
            'message' => '[' . $level . '] ' . $message . ' ' . $encodedContext,
        ]);
        update_option('wei_fr_logs', array_slice($logs, 0, 100), false);
    }
}
