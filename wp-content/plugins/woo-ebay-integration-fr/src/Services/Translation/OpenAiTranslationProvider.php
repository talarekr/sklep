<?php

namespace WEI_FR\Services\Translation;

use WEI_FR\Interfaces\TranslationProviderInterface;
use WEI_FR\Services\Logger;

class OpenAiTranslationProvider implements TranslationProviderInterface
{
    public function __construct(private array $settings, private Logger $logger)
    {
    }

    public function provider_key(): string
    {
        return 'openai';
    }

    public function is_configured(): bool
    {
        return trim((string) ($this->settings['translation_api_key'] ?? '')) !== '';
    }

    public function translate_product_content(\WC_Product $product, array $context): array
    {
        if (!$this->is_configured()) {
            throw new \RuntimeException('OpenAI translation provider is not configured.');
        }

        $model = trim((string) ($this->settings['translation_openai_model'] ?? 'gpt-4o-mini'));
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }

        $sourceTitle = (string) ($context['source_title'] ?? $product->get_name());
        $sourceDescription = (string) ($context['source_description'] ?? $product->get_description());
        $mpn = (string) ($context['mpn'] ?? '');
        $manufacturer = (string) ($context['manufacturer'] ?? '');

        $payload = [
            'model' => $model,
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You translate Polish WooCommerce auto-parts listings into French for eBay.fr. Return only valid JSON with title_de and description_de. Do not invent facts. Preserve brand/manufacturer, MPN/OE numbers, condition, compatibility and technical notes. The title must be French, plain text, no HTML, concise, max 80 characters. Description must be French and may use simple safe HTML tags only.',
                ],
                [
                    'role' => 'user',
                    'content' => wp_json_encode([
                        'source_language' => 'pl-PL',
                        'target_language' => 'fr-FR',
                        'title_pl' => $sourceTitle,
                        'description_pl' => wp_strip_all_tags($sourceDescription),
                        'mpn_or_oe_number' => $mpn,
                        'manufacturer' => $manufacturer,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . trim((string) $this->settings['translation_api_key']),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            $this->logger->warning('OpenAI translation request failed', ['status' => $code, 'body' => mb_substr($body, 0, 500)]);
            throw new \RuntimeException('OpenAI translation request failed with HTTP ' . $code . '.');
        }

        $decoded = json_decode($body, true);
        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw new \RuntimeException('OpenAI translation response did not contain valid JSON.');
        }

        $title = $this->sanitize_title((string) ($json['title_de'] ?? ''));
        $description = $this->sanitize_description((string) ($json['description_de'] ?? ''));

        if ($title === '' || $description === '') {
            throw new \RuntimeException('OpenAI translation response was missing French title or description.');
        }

        return ['title_de' => $title, 'description_de' => $description];
    }

    private function sanitize_title(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($title)) ?: '');
        if (mb_strlen($title) > 80) {
            $title = trim(mb_substr($title, 0, 80));
        }
        return $title;
    }

    private function sanitize_description(string $description): string
    {
        return trim(wp_kses_post($description));
    }
}
