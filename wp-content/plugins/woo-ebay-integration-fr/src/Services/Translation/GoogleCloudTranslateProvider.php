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
        $translations = $this->translate_texts([$sourceTitle, $this->sanitize_title($sourceDescription)], 'pl', 'de', 'text');

        if (count($translations) < 2) {
            throw new \RuntimeException('Google Translation API response was missing translated title or description.');
        }

        $title = $this->sanitize_title((string) ($translations[0] ?? ''));
        $description = $this->sanitize_description((string) ($translations[1] ?? ''));

        if ($title === '' || $description === '') {
            throw new \RuntimeException('Google Translation API returned empty French title or description.');
        }

        return ['title_de' => $title, 'description_de' => $description];
    }

    /**
     * @param array<int,string> $texts
     * @return array<int,string>
     */
    public function translate_texts(array $texts, string $source = 'pl', string $target = 'de', string $format = 'text'): array
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
            'source' => $source,
            'target' => 'de',
            'format' => 'text',
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
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $errorMessage = (string) ($decoded['error']['message'] ?? ('HTTP ' . $code));
            $this->logger->warning('Google Cloud Translation request failed', [
                'status' => $code,
                'error_message' => $errorMessage,
                'body' => mb_substr($body, 0, 500),
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

    private function sanitize_title(string $title): string
    {
        return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '');
    }

    private function sanitize_description(string $description): string
    {
        return trim(wp_kses_post(wp_specialchars_decode($description, ENT_QUOTES)));
    }
}
