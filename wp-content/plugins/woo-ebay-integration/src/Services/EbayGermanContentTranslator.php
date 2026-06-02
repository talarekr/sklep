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
    public const SCHEMA_VERSION = '2026-06-new-ebay-template-v3';
    public const TEMPLATE_VERSION = 'ebay-de-product-card-template-v3';
    public const TRANSLATION_SCHEMA_VERSION = 'pl-de-spec-overrides-2026-06-v4';

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
        'Strona zabudowy' => 'Einbauposition',
        'Pozycja' => 'Einbauposition',
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
        'Biały' => 'Weiß',
        'Bialy' => 'Weiß',
        'Srebrny' => 'Silber',
        'Benzyna' => 'Benzin',
        'Diesel' => 'Diesel',
        'Używany' => 'Gebraucht',
        'Uzywany' => 'Gebraucht',
        'Nowy' => 'Neu',
        'Lewa strona' => 'Linkslenker',
        'Prawa strona' => 'Rechtslenker',
        'Przód' => 'Vorne',
        'Przod' => 'Vorne',
        'Tył' => 'Hinten',
        'Tyl' => 'Hinten',
        'Automatyczny' => 'Automatik',
        'Automatyczne' => 'Automatik',
        'Automatyczna' => 'Automatik',
        'Manualny' => 'Schaltgetriebe',
        'Manualna' => 'Schaltgetriebe',
        'Mechaniczna' => 'Schaltgetriebe',
        'Mechaniczny' => 'Schaltgetriebe',
        'AWD' => 'Allradantrieb',
        '4x4' => 'Allradantrieb',
    ];

    public function __construct(private ?Logger $logger = null)
    {
    }

    /** @param array<string,mixed> $source */
    public function source_hash(array $source): string
    {
        return hash('sha256', $this->json([
            'version' => 4,
            'german_content_schema_version' => self::SCHEMA_VERSION,
            'template_version' => self::TEMPLATE_VERSION,
            'translation_schema_version' => self::TRANSLATION_SCHEMA_VERSION,
            'target_language' => 'de',
            'translated_raw_html' => false,
            'html_css_protected' => true,
            'product_id' => (int) ($source['product_id'] ?? 0),
            'title' => (string) ($source['title'] ?? ''),
            'source_title' => (string) ($source['title'] ?? ''),
            'source_description' => (string) ($source['description'] ?? ''),
            'source_description_field' => (string) ($source['description_source'] ?? 'post_content'),
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
        $staleReasons = $this->stale_reasons($payload, $source, $currentHash, $storedHash);
        $stale = $ready && $staleReasons !== [];

        return array_merge($payload, [
            'ready' => $ready,
            'stale' => $stale,
            'stale_reasons' => $ready ? $staleReasons : [],
            'stale_reason' => $ready ? ($staleReasons[0] ?? 'current') : 'missing',
            'current_schema_version' => self::SCHEMA_VERSION,
            'stored_schema_version' => (string) ($payload['german_content_schema_version'] ?? ''),
            'template_version' => (string) ($payload['template_version'] ?? ''),
            'translation_schema_version' => (string) ($payload['translation_schema_version'] ?? ''),
            'source_description_field' => (string) ($payload['source_description_field'] ?? $payload['description_source'] ?? ''),
            'source_description_used' => (string) ($source['description'] ?? ''),
            'source_hash' => $currentHash,
            'cached_translation_hash' => $storedHash,
            'content_hash' => (string) ($payload['content_hash'] ?? ''),
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
        $add('description', $this->sanitize_text((string) ($source['description'] ?? '')), 'text');
        foreach ((array) ($source['fields'] ?? []) as $index => $field) {
            $label = (string) ($field['polish_label'] ?? $field['label'] ?? '');
            $value = (string) ($field['value'] ?? $field['source_value'] ?? '');
            if ($label !== '' && !$this->override_label($label)) {
                $add('field_label.' . $index, $label, 'text');
            }
            if ($value !== '' && !$this->override_field_value($label, $value) && !$this->is_protected_technical_value($value)) {
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
                if ($value !== '' && !$this->override_field_value($name, $value) && !$this->is_protected_technical_value($value)) {
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
            $valueSource = (string) ($field['value'] ?? $field['source_value'] ?? '');
            $label = $this->override_label($labelSource) ?: (string) ($translatedByKey['field_label.' . $index] ?? $field['german_label'] ?? $labelSource);
            $overrideValue = $this->override_field_value($labelSource, $valueSource);
            $value = $overrideValue !== '' ? $overrideValue : ($this->is_protected_technical_value($valueSource) ? $valueSource : (string) ($translatedByKey['field_value.' . $index] ?? $valueSource));
            $fieldTranslationSource = $overrideValue !== '' ? 'local_override' : ($this->is_protected_technical_value($valueSource) ? 'protected_technical_value' : (array_key_exists('field_value.' . $index, $translatedByKey) ? 'generated_' . $provider->provider_key() : 'source_fallback'));
            $fields[] = array_merge($field, [
                'source_label' => $labelSource,
                'source_value' => $valueSource,
                'german_label' => $this->sanitize_text($label),
                'value' => $this->sanitize_text($value),
                'translated_value' => $this->sanitize_text($value),
                'translation_source' => $fieldTranslationSource,
            ]);
        }

        $aspects = [];
        foreach ((array) ($source['aspects_source'] ?? []) as $name => $values) {
            $sourceName = (string) $name;
            $translatedName = $this->override_label($sourceName) ?: ($this->looks_german_aspect_name($sourceName) ? $sourceName : (string) ($translatedByKey['aspect_label.' . $sourceName] ?? $sourceName));
            $translatedValues = [];
            foreach ((array) $values as $valueIndex => $value) {
                $sourceValue = (string) $value;
                $translatedValue = $this->override_field_value($sourceName, $sourceValue) ?: ($this->is_protected_technical_value($sourceValue) ? $sourceValue : (string) ($translatedByKey['aspect_value.' . $sourceName . '.' . $valueIndex] ?? $sourceValue));
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
            'version' => 4,
            'german_content_schema_version' => self::SCHEMA_VERSION,
            'template_version' => self::TEMPLATE_VERSION,
            'translation_schema_version' => self::TRANSLATION_SCHEMA_VERSION,
            'target_language' => 'de',
            'translated_raw_html' => false,
            'html_css_protected' => true,
            'product_id' => $productId,
            'title' => $this->sanitize_text((string) ($translatedByKey['title'] ?? $source['title'] ?? '')),
            'description' => trim(wp_kses_post((string) ($translatedByKey['description'] ?? $source['description'] ?? ''))),
            'fields' => $fields,
            'aspects' => $aspects,
            'source_title' => (string) ($source['title'] ?? ''),
            'source_description' => (string) ($source['description'] ?? ''),
            'source_description_field' => (string) ($source['description_source'] ?? 'post_content'),
            'source_hash' => $hash,
            'content_hash' => $this->content_hash((string) ($translatedByKey['title'] ?? $source['title'] ?? ''), (string) ($translatedByKey['description'] ?? $source['description'] ?? ''), $fields, $aspects),
            'cached_translation_hash' => $hash,
            'stale' => false,
            'ready' => true,
            'translation_source' => 'generated_' . $provider->provider_key(),
            'translated_fields' => array_values($keys),
            'translated_text_nodes' => array_values($keys),
            'protected_technical_values' => $this->collect_protected_technical_values($source),
            'untranslated_fields' => [],
            'google_api_called' => true,
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'created_ebay_listing' => false,
            'modified_woo_product' => false,
            'generated_at' => gmdate('c'),
            'stale_reasons' => [],
            'stale_reason' => 'current',
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
        $pattern = '/[ąćęłńóśźż]|\b(lewy|lewa|prawy|prawa|przód|przod|tył|tyl|manualna|manualny|mechaniczna|mechaniczny|automatyczna|automatyczny|benzyna|używany|uzywany|czarny|biały|bialy|srebrny|szary|niebieski|czerwony|zielony|żółty|zolty)\b/iu';
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


    /** @param array<string,mixed> $payload @param array<string,mixed> $source @return array<int,string> */
    private function stale_reasons(array $payload, array $source, string $currentHash, string $storedHash): array
    {
        $reasons = [];
        $storedSchema = trim((string) ($payload['german_content_schema_version'] ?? ''));
        if ($storedSchema !== self::SCHEMA_VERSION) {
            $reasons[] = 'old_schema_version';
        }
        if (trim((string) ($payload['template_version'] ?? '')) !== self::TEMPLATE_VERSION) {
            $reasons[] = 'old_template_version';
        }
        if ($storedHash === '' || $storedHash !== $currentHash || (string) ($payload['source_hash'] ?? '') !== $currentHash) {
            $reasons[] = 'old_source_hash';
        }
        if (trim((string) ($payload['source_description_field'] ?? $payload['description_source'] ?? '')) !== trim((string) ($source['description_source'] ?? 'post_content'))) {
            $reasons[] = 'old_source_hash';
        }
        $description = (string) ($payload['description'] ?? '');
        if (stripos($description, 'Hallo, das Angebot gilt für') !== false) {
            $reasons[] = 'old_allegro_description_marker';
        }
        if (stripos($description, 'TEIL IN FUNKTIONSFÄHIGEM ZUSTAND') !== false) {
            $reasons[] = 'old_allegro_description_marker';
        }
        if ($this->untranslated_fields($payload) !== [] || $this->has_missing_known_spec_value_translations($payload)) {
            $reasons[] = 'untranslated_spec_values';
        }
        return array_values(array_unique($reasons));
    }

    /** @param array<int,mixed> $fields @param array<string,mixed> $aspects */
    private function content_hash(string $title, string $description, array $fields, array $aspects): string
    {
        return hash('sha256', $this->json([
            'schema' => self::SCHEMA_VERSION,
            'template' => self::TEMPLATE_VERSION,
            'translation_schema' => self::TRANSLATION_SCHEMA_VERSION,
            'title' => $title,
            'description' => $description,
            'fields' => $fields,
            'aspects' => $aspects,
        ]));
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

    public function translate_known_spec_value(string $label, string $value): string
    {
        return $this->sanitize_text($this->override_field_value($label, $value));
    }

    public function detects_untranslated_spec_value(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        return preg_match('/[ąćęłńóśźż]|\b(lewy|lewa|prawy|prawa|przód|przod|tył|tyl|manualna|manualny|mechaniczna|mechaniczny|automatyczna|automatyczny|benzyna|używany|uzywany|czarny|biały|bialy|srebrny|szary|niebieski|czerwony|zielony|żółty|zolty)\b/iu', $value) === 1;
    }

    /** @param array<string,mixed> $payload */
    private function has_missing_known_spec_value_translations(array $payload): bool
    {
        foreach ((array) ($payload['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $sourceValue = trim((string) ($field['source_value'] ?? ''));
            $sourceLabel = trim((string) ($field['polish_label'] ?? $field['source_label'] ?? ''));
            if ($sourceValue === '' || strcasecmp($sourceValue, 'Diesel') === 0) {
                continue;
            }
            $knownTranslation = $this->translate_known_spec_value($sourceLabel, $sourceValue);
            if ($knownTranslation === '') {
                continue;
            }
            $translatedValue = trim((string) ($field['translated_value'] ?? ''));
            if ($translatedValue === '' || $translatedValue === $sourceValue) {
                return true;
            }
        }
        return false;
    }

    private function override_label(string $label): string
    {
        return $this->labelOverrides[$label] ?? '';
    }

    private function override_value(string $value): string
    {
        return $this->valueOverrides[$value] ?? '';
    }

    private function override_field_value(string $label, string $value): string
    {
        $normalizedLabel = $this->normalize_key($label);
        $normalizedValue = $this->normalize_key($value);
        if (in_array($normalizedLabel, ['kolanapedowe', 'kolanapędowe'], true)) {
            $driveOverride = [
                'przod' => 'Frontantrieb',
                'przód' => 'Frontantrieb',
                'tyl' => 'Heckantrieb',
                'tył' => 'Heckantrieb',
            ][$normalizedValue] ?? '';
            if ($driveOverride !== '') {
                return $driveOverride;
            }
        }
        return $this->override_value($value);
    }

    private function normalize_key(string $value): string
    {
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        } else {
            $value = strtr($value, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
        }
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/[^a-z0-9]+/u', '', $value) ?: '';
    }

    private function is_protected_technical_value(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/\bGPSWCarID:\d+\b/i', $value)) {
            return true;
        }
        if (preg_match('/^\d+(?:[\s.,-]\d+)*(?:\s?(?:km|kw|ps|cm3|l|r|rok|r\.|mm))?$/iu', $value)) {
            return true;
        }
        if (preg_match('/^(?:\d{4})(?:\s?[–-]\s?\d{4})?$/u', $value)) {
            return true;
        }
        if (preg_match('/^[A-Z0-9]{2,}(?:[\s\/-][A-Z0-9]{2,})*$/u', $value)) {
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $source @return array<int,string> */
    private function collect_protected_technical_values(array $source): array
    {
        $values = [];
        foreach ((array) ($source['fields'] ?? []) as $field) {
            $value = (string) ($field['value'] ?? $field['source_value'] ?? '');
            if ($this->is_protected_technical_value($value)) {
                $values[] = trim($value);
            }
        }
        foreach ((array) ($source['aspects_source'] ?? []) as $aspectValues) {
            foreach ((array) $aspectValues as $value) {
                if ($this->is_protected_technical_value((string) $value)) {
                    $values[] = trim((string) $value);
                }
            }
        }
        return array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
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
                $translatedValues[] = $this->override_field_value((string) $name, (string) $value) ?: (string) $value;
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
