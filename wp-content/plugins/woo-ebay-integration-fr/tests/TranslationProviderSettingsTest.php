<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$plugin = file_get_contents($root . '/src/Plugin.php') ?: '';
$view = file_get_contents($root . '/views/admin-page.php') ?: '';
$google = file_get_contents($root . '/src/Services/Translation/GoogleCloudTranslateProvider.php') ?: '';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($plugin, "TRANSLATION_OPTION_KEY = 'wei_fr_ebay_translation_provider_settings'"), 'FR Google Translate settings must use a dedicated FR-specific option key.');
$assert(str_contains($admin, "add_action('admin_post_wei_fr_save_translation_provider_settings'"), 'FR admin must expose a dedicated translation settings save action.');
$assert(str_contains($admin, 'save_translation_provider_settings($_POST, $s)'), 'FR settings save must persist the Google provider settings.');
$assert(str_contains($admin, "update_option(Plugin::TRANSLATION_OPTION_KEY"), 'FR Google provider settings must be stored in the FR-specific translation option.');
$assert(!str_contains($admin . $adapter . $view, 'wei_ebay_translation_provider_settings'), 'FR code must not use or overwrite DE translation provider options.');
$assert(str_contains($admin . $adapter, "'translation_target_language' => 'fr'") || str_contains($admin . $adapter, '$settings[\'translation_target_language\'] = \'fr\';'), 'FR target language must be enforced as fr.');
$assert(str_contains($google, "'target' => 'fr'"), 'Google Cloud Translate provider must force FR target language.');
$assert(!str_contains($google, "'target' => 'de'") && !str_contains($google, "'pl', 'de'"), 'FR Google provider must not send target=de.');
$assert(str_contains($google, 'redact_secret') && str_contains($google, '[redacted]'), 'Google provider errors/logs must redact the raw API key.');

$assert(str_contains($view, 'id="wei-fr-translation-provider"'), 'French Content panel must render a Translation provider section.');
$assert(str_contains($view, 'name="enable_google_cloud_translate"'), 'Translation provider UI must include an enable Google checkbox.');
$assert(str_contains($view, 'type="password"') && str_contains($view, 'name="translation_api_key"'), 'Translation provider UI must include a password API key field.');
$assert(str_contains($view, 'configured — enter a new key to replace'), 'Translation provider UI must show configured/masked state instead of the raw key after save.');
$assert(!str_contains($view, 'value="<?php echo esc_attr((string) ($s[\'translation_api_key\']'), 'Translation provider UI must not render the raw API key in the password input value.');
$assert(str_contains($view, 'google_provider_configured') && str_contains($view, 'google_credentials_source') && str_contains($view, 'source_language') && str_contains($view, 'target_language'), 'Translation provider diagnostics must show safe status fields.');
$assert(str_contains($view, 'fr_admin_setting'), 'Diagnostics must report fr_admin_setting as the configured key source.');
$assert(str_contains($view, 'Enforced by the FR plugin') && str_contains($view, 'German (<code>de</code>) is not allowed here.'), 'FR UI must explain that target=fr is enforced and de is not allowed.');

$assert(str_contains($adapter, 'TRANSLATION_OPTION_KEY') && str_contains($adapter, 'wei_fr_ebay_translation_provider_settings'), 'French content generator must read the FR admin translation option.');
$assert(str_contains($adapter, "'translation_provider_not_configured'") && str_contains($adapter, "'google_provider_configured' => 'no'"), 'Missing key diagnostic must be clear and redacted.');
$assert(!str_contains($adapter, 'translation_api_key\' =>') && !str_contains($adapter, 'translation_api_key" =>'), 'Adapter diagnostics must not return raw API keys.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Translation provider settings tests passed\n";
