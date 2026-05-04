<?php

namespace WEI\Services;

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
        error_log('[WEI][' . $level . '] ' . $message . ' ' . wp_json_encode($context));
    }
}
