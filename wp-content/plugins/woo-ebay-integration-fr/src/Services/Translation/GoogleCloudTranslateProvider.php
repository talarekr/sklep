<?php

namespace WEI_FR\Services\Translation;

use WEI_FR\Interfaces\TranslationProviderInterface;
use WEI_FR\Services\Logger;

class GoogleCloudTranslateProvider implements TranslationProviderInterface
{
    public function __construct(private array $settings, private Logger $logger)
    {
    }

    public function provider_key(): string
    {
        return 'google_cloud_translate';
    }

    public function is_configured(): bool
    {
        return trim((string) ($this->settings['translation_api_key'] ?? '')) !== '';
    }

    public function translate_product_content(\WC_Product $product, array $context): array
    {
        $sourceTitle = (string) ($context['source_title'] ?? $product->get_name());
        $sourceDescription = (string) ($context['source_description'] ?? $product->get_description());
        $translations = $this->translate_texts([$sourceTitle, $this->sanitize_title($sourceDescription)], $this->source_language(), $this->target_language(), 'text');

        if (count($translations) < 2) {
            throw new \RuntimeException('Google Translation API response was missing translated title or description.');
        }

        $title = $this->sanitize_title((string) ($translations[0] ?? ''));
        $description = $this->sanitize_description((string) ($translations[1] ?? ''));

        if ($title === '' || $description === '') {
            throw new \RuntimeException('Google Translation API returned empty French title or description.');
        }

        return ['title_fr' => $title, 'description_fr' => $description];
    }

    /**
     * @param array<int,string> $texts
     * @return array<int,string>
     */
    public function translate_texts(array $texts, string $source = 'pl', string $target = 'fr', string $format = 'text'): array
    {
        if (!$this->is_configured()) {
            throw new \RuntimeException('Google Translation provider is not configured.');
        }

        $texts = array_values(array_filter(array_map('strval', $texts), static fn(string $text): bool => trim($text) !== ''));
        if ($texts === []) {
            return [];
        }

        $payload = [
            'q' => $texts,
            'source' => $this->sanitize_language_code($source !== '' ? $source : $this->source_language(), 'pl'),
            'target' => 'fr',
            'format' => in_array($format, ['text', 'html'], true) ? $format : 'text',
        ];

        $response = wp_remote_post(
            'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode(trim((string) $this->settings['translation_api_key'])),
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException($this->redact_secret($response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $errorMessage = $this->redact_secret((string) ($decoded['error']['message'] ?? ('HTTP ' . $code)));
            $safeBody = $this->redact_secret(mb_substr($body, 0, 500));
            $this->logger->warning('Google Cloud Translation request failed', [
                'status' => $code,
                'error_message' => $errorMessage,
                'body' => $safeBody,
            ]);
            throw new \RuntimeException('Google Translation API error: ' . $errorMessage);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Google Translation API response did not contain valid JSON.');
        }

        $translations = $decoded['data']['translations'] ?? [];
        if (!is_array($translations) || count($translations) < count($texts)) {
            throw new \RuntimeException('Google Translation API response was missing translated text.');
        }

        $values = [];
        foreach ($translations as $translation) {
            $values[] = wp_specialchars_decode((string) ($translation['translatedText'] ?? ''), ENT_QUOTES);
        }

        return $values;
    }



    private function redact_secret(string $message): string
    {
        $apiKey = trim((string) ($this->settings['translation_api_key'] ?? ''));
        if ($apiKey !== '') {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        return (string) preg_replace('/([?&]key=)[^&\s]+/i', '$1[redacted]', $message);
    }

    private function source_language(): string
    {
        return $this->sanitize_language_code((string) ($this->settings['translation_source_language'] ?? 'pl'), 'pl');
    }

    private function target_language(): string
    {
        // This is the FR plugin: never allow the German target language here.
        return 'fr';
    }

    private function sanitize_language_code(string $language, string $fallback): string
    {
        $language = strtolower(trim($language));
        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) ? $language : $fallback;
    }

    private function sanitize_title(string $title): string
    {
        return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '');
    }

    private function sanitize_description(string $description): string
    {
        return trim(wp_kses_post(wp_specialchars_decode($description, ENT_QUOTES)));
    }
}
