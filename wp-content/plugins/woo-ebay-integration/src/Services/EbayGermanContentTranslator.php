<?php

namespace WEI\Services;

use WEI\Interfaces\TranslationProviderInterface;

class EbayGermanContentTranslator
{
    public const META_PAYLOAD = '_wei_ebay_de_content_payload';
    public const META_HASH = '_wei_ebay_de_content_hash';
    public const META_TITLE = '_wei_ebay_de_title';
    public const META_DESCRIPTION = '_wei_ebay_de_description';
    public const META_SOURCE = '_wei_ebay_de_content_source';
    public const META_GENERATED_AT = '_wei_ebay_de_content_generated_at';

    /** @var array<string,string> */
    private array $labelOverrides = [
        'Kod koloru' => 'Farbcode',
        'Kod silnika' => 'Motorcode',
        'Kolor' => 'Farbe',
        'Koła napędowe' => 'Antrieb',
        'Kola napedowe' => 'Antrieb',
        'Moc silnika' => 'Motorleistung',
        'Model' => 'Modell',
        'Modyfikacja' => 'Variante / Ausführung',
        'Numer części' => 'Teilenummer',
        'Numer czesci' => 'Teilenummer',
        'Okres' => 'Bauzeitraum',
        'Pojemność silnika' => 'Hubraum',
        'Pojemnosc silnika' => 'Hubraum',
        'Pozycja kierownicy' => 'Lenkradposition',
        'Producent' => 'Hersteller',
        'Przebieg' => 'Laufleistung',
        'Rodzaj paliwa' => 'Kraftstoffart',
        'Rok produkcji samochodu' => 'Baujahr des Fahrzeugs',
        'Stan' => 'Zustand',
        'Stan opakowania' => 'Verpackungszustand',
        'Typ skrzyni biegów' => 'Getriebeart',
        'Typ skrzyni biegow' => 'Getriebeart',
    ];

    /** @var array<string,string> */
    private array $valueOverrides = [
        'Szary' => 'Grau',
        'Czarny' => 'Schwarz',
        'Benzyna' => 'Benzin',
        'Używany' => 'Gebraucht',
        'Uzywany' => 'Gebraucht',
        'Lewa strona' => 'Linkslenker',
        'Automatyczny' => 'Automatik',
        'Automatyczna' => 'Automatik',
    ];

    public function __construct(private ?Logger $logger = null)
    {
    }

    /** @param array<string,mixed> $source */
    public function source_hash(array $source): string
    {
        return hash('sha256', $this->json([
            'version' => 2,
            'product_id' => (int) ($source['product_id'] ?? 0),
            'title' => (string) ($source['title'] ?? ''),
            'description' => (string) ($source['description'] ?? ''),
            'short_description' => (string) ($source['short_description'] ?? ''),
            'fields' => $source['fields'] ?? [],
            'aspects_source' => $source['aspects_source'] ?? [],
        ]));
    }

    /** @param array<string,mixed> $source */
    public function cached(int $productId, array $source): array
    {
        $currentHash = $this->source_hash($source);
        $storedHash = trim((string) get_post_meta($productId, self::META_HASH, true));
        $payload = $this->read_payload($productId);
        $legacyTitle = trim((string) get_post_meta($productId, self::META_TITLE, true));
        $legacyDescription = trim((string) get_post_meta($productId, self::META_DESCRIPTION, true));
        if ($payload === [] && ($legacyTitle !== '' || $legacyDescription !== '')) {
            $payload = [
                'title' => $legacyTitle,
                'description' => $legacyDescription,
                'fields' => [],
                'aspects' => [],
                'translation_source' => trim((string) get_post_meta($productId, self::META_SOURCE, true)) ?: 'legacy_meta',
            ];
        }

        $ready = trim((string) ($payload['title'] ?? '')) !== '' && trim((string) ($payload['description'] ?? '')) !== '';
        $stale = $ready && ($storedHash === '' || $storedHash !== $currentHash);

        return array_merge($payload, [
            'ready' => $ready,
            'stale' => $stale,
            'source_hash' => $currentHash,
            'cached_translation_hash' => $storedHash,
            'google_api_called' => false,
            'translation_source' => (string) ($payload['translation_source'] ?? 'cache'),
        ]);
    }

    /** @param array<string,mixed> $source */
    public function refresh(int $productId, array $source, TranslationProviderInterface $provider): array
    {
        $hash = $this->source_hash($source);
        $texts = [];
        $keys = [];
        $formats = [];
        $add = static function (string $key, string $text, string $format = 'text') use (&$texts, &$keys, &$formats): void {
            $text = trim($text);
            if ($text === '') {
                return;
            }
            $keys[] = $key;
            $texts[] = $text;
            $formats[] = $format;
        };

        $add('title', (string) ($source['title'] ?? ''), 'text');
        $add('description', (string) ($source['description'] ?? ''), 'html');
        foreach ((array) ($source['fields'] ?? []) as $index => $field) {
            $label = (string) ($field['polish_label'] ?? $field['label'] ?? '');
            $value = (string) ($field['value'] ?? '');
            if ($label !== '' && !$this->override_label($label)) {
                $add('field_label.' . $index, $label, 'text');
            }
            if ($value !== '' && !$this->override_value($value)) {
                $add('field_value.' . $index, $value, 'text');
            }
        }
        foreach ((array) ($source['aspects_source'] ?? []) as $name => $values) {
            $name = (string) $name;
            if ($name !== '' && !$this->override_label($name) && !$this->looks_german_aspect_name($name)) {
                $add('aspect_label.' . $name, $name, 'text');
            }
            foreach ((array) $values as $valueIndex => $value) {
                $value = (string) $value;
                if ($value !== '' && !$this->override_value($value)) {
                    $add('aspect_value.' . $name . '.' . $valueIndex, $value, 'text');
                }
            }
        }

        $translatedByKey = [];
        if ($texts !== []) {
            $translated = $this->translate_mixed_texts($provider, $texts, $formats);
            foreach ($keys as $idx => $key) {
                $translatedByKey[$key] = trim((string) ($translated[$idx] ?? $texts[$idx] ?? ''));
            }
        }

        $fields = [];
        foreach ((array) ($source['fields'] ?? []) as $index => $field) {
            $labelSource = (string) ($field['polish_label'] ?? $field['label'] ?? '');
            $valueSource = (string) ($field['value'] ?? '');
            $label = $this->override_label($labelSource) ?: (string) ($translatedByKey['field_label.' . $index] ?? $field['german_label'] ?? $labelSource);
            $value = $this->override_value($valueSource) ?: (string) ($translatedByKey['field_value.' . $index] ?? $valueSource);
            $fields[] = array_merge($field, [
                'source_label' => $labelSource,
                'source_value' => $valueSource,
                'german_label' => $this->sanitize_text($label),
                'value' => $this->sanitize_text($value),
                'translated_value' => $this->sanitize_text($value),
            ]);
        }

        $aspects = [];
        foreach ((array) ($source['aspects_source'] ?? []) as $name => $values) {
            $sourceName = (string) $name;
            $translatedName = $this->override_label($sourceName) ?: ($this->looks_german_aspect_name($sourceName) ? $sourceName : (string) ($translatedByKey['aspect_label.' . $sourceName] ?? $sourceName));
            $translatedValues = [];
            foreach ((array) $values as $valueIndex => $value) {
                $sourceValue = (string) $value;
                $translatedValue = $this->override_value($sourceValue) ?: (string) ($translatedByKey['aspect_value.' . $sourceName . '.' . $valueIndex] ?? $sourceValue);
                $translatedValue = $this->sanitize_text($translatedValue);
                if ($translatedValue !== '') {
                    $translatedValues[] = $translatedValue;
                }
            }
            $translatedName = $this->sanitize_text($translatedName);
            if ($translatedName !== '' && $translatedValues !== []) {
                $aspects[$translatedName] = array_values(array_unique($translatedValues));
            }
        }

        $payload = [
            'version' => 2,
            'product_id' => $productId,
            'title' => $this->sanitize_text((string) ($translatedByKey['title'] ?? $source['title'] ?? '')),
            'description' => trim(wp_kses_post((string) ($translatedByKey['description'] ?? $source['description'] ?? ''))),
            'fields' => $fields,
            'aspects' => $aspects,
            'source_description' => (string) ($source['description'] ?? ''),
            'source_hash' => $hash,
            'cached_translation_hash' => $hash,
            'stale' => false,
            'ready' => true,
            'translation_source' => 'generated_' . $provider->provider_key(),
            'translated_fields' => array_values($keys),
            'untranslated_fields' => [],
            'google_api_called' => true,
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'created_ebay_listing' => false,
            'modified_woo_product' => false,
            'generated_at' => gmdate('c'),
        ];

        update_post_meta($productId, self::META_PAYLOAD, $payload);
        update_post_meta($productId, self::META_TITLE, $payload['title']);
        update_post_meta($productId, self::META_DESCRIPTION, $payload['description']);
        update_post_meta($productId, self::META_SOURCE, $payload['translation_source']);
        update_post_meta($productId, self::META_GENERATED_AT, $payload['generated_at']);
        update_post_meta($productId, self::META_HASH, $hash);

        return $payload;
    }

    /** @param array<string,mixed> $source */
    public function translate_aspects_from_cache(int $productId, array $source, array $fallbackAspects): array
    {
        $cached = $this->cached($productId, $source);
        $fallback = $this->translate_with_overrides_only($fallbackAspects);
        if (empty($cached['ready']) || !empty($cached['stale']) || !is_array($cached['aspects'] ?? null) || $cached['aspects'] === []) {
            return $fallback;
        }
        return array_replace($fallback, (array) $cached['aspects']);
    }

    /** @param array<string,mixed> $payload */
    public function untranslated_fields(array $payload): array
    {
        $warnings = [];
        $pattern = '/[ąćęłńóśźż]|\b(lewy|prawy|przód|przod|tył|tyl|manualna|automatyczna|automatyczny|benzyna|diesel|używany|uzywany|czarny|biały|bialy|srebrny|szary|niebieski|czerwony|zielony|żółty|zolty)\b/iu';
        foreach ((array) ($payload['fields'] ?? []) as $field) {
            foreach (['german_label', 'value'] as $key) {
                $value = trim((string) ($field[$key] ?? ''));
                if ($value !== '' && preg_match($pattern, $value)) {
                    $warnings[] = ['field' => (string) ($field['german_label'] ?? $field['polish_label'] ?? ''), 'key' => $key, 'value' => $value, 'message' => 'Looks untranslated or still Polish.'];
                }
            }
        }
        foreach ((array) ($payload['aspects'] ?? []) as $name => $values) {
            if (preg_match($pattern, (string) $name)) {
                $warnings[] = ['field' => (string) $name, 'key' => 'aspect_label', 'value' => (string) $name, 'message' => 'Looks untranslated or still Polish.'];
            }
            foreach ((array) $values as $value) {
                if (preg_match($pattern, (string) $value)) {
                    $warnings[] = ['field' => (string) $name, 'key' => 'aspect_value', 'value' => (string) $value, 'message' => 'Looks untranslated or still Polish.'];
                }
            }
        }
        return $warnings;
    }

    private function read_payload(int $productId): array
    {
        $payload = get_post_meta($productId, self::META_PAYLOAD, true);
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** @param array<int,string> $texts @param array<int,string> $formats */
    private function translate_mixed_texts(TranslationProviderInterface $provider, array $texts, array $formats): array
    {
        if (!method_exists($provider, 'translate_texts')) {
            throw new \RuntimeException('Configured translation provider cannot translate field labels and values.');
        }
        $results = [];
        foreach ($texts as $idx => $text) {
            $format = (string) ($formats[$idx] ?? 'text');
            $translated = $provider->translate_texts([$text], 'pl', 'de', $format);
            $results[] = (string) ($translated[0] ?? $text);
        }
        return $results;
    }

    private function override_label(string $label): string
    {
        return $this->labelOverrides[$label] ?? '';
    }

    private function override_value(string $value): string
    {
        return $this->valueOverrides[$value] ?? '';
    }

    private function looks_german_aspect_name(string $name): bool
    {
        $known = ['MPN', 'Hersteller', 'Herstellernummer', 'Manufacturer Part Number', 'OE/OEM Referenznummer', 'Ursprungsland', 'Farbe', 'Kraftstoffart', 'Getriebeart', 'Lenkradposition'];
        return in_array($name, $known, true);
    }

    private function translate_with_overrides_only(array $aspects): array
    {
        $translated = [];
        foreach ($aspects as $name => $values) {
            $translatedName = $this->override_label((string) $name) ?: (string) $name;
            $translatedValues = [];
            foreach ((array) $values as $value) {
                $translatedValues[] = $this->override_value((string) $value) ?: (string) $value;
            }
            $translated[$translatedName] = array_values(array_unique(array_filter($translatedValues, static fn($value): bool => trim((string) $value) !== '')));
        }
        return $translated;
    }

    private function sanitize_text(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '');
    }

    private function json(array $data): string
    {
        return wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
