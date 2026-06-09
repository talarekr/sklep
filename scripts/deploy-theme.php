#!/usr/bin/env php
<?php
/**
 * Safe production theme deploy helper.
 *
 * This script intentionally does not copy the repository root into a WordPress
 * theme directory. Copying the root pollutes the active theme with unrelated
 * repository files (for example wp-content/plugins) and can break frontend
 * assets/templates after deploy.
 */

declare(strict_types=1);

function recurse_copy(string $source, string $target): void
{
    if (!is_dir($source)) {
        throw new RuntimeException("Theme source does not exist or is not a directory: {$source}");
    }

    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException("Unable to create theme target directory: {$target}");
    }

    $directory = opendir($source);
    if ($directory === false) {
        throw new RuntimeException("Unable to open theme source directory: {$source}");
    }

    try {
        while (($entry = readdir($directory)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
            $targetPath = $target . DIRECTORY_SEPARATOR . $entry;

            if (is_link($sourcePath)) {
                continue;
            }

            if (is_dir($sourcePath)) {
                recurse_copy($sourcePath, $targetPath);
                continue;
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException("Unable to copy {$sourcePath} to {$targetPath}");
            }
        }
    } finally {
        closedir($directory);
    }
}

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$themeTarget = $argv[1] ?? getenv('WP_THEME_TARGET') ?: '';
if ($themeTarget === '') {
    fwrite(STDERR, "Usage: php scripts/deploy-theme.php /absolute/path/to/wp-content/themes/shop\n");
    fwrite(STDERR, "No files were copied.\n");
    exit(2);
}

$shopThemeSource = $repoRoot . '/wp-content/themes/shop';
$rootThemeDetected = is_file($repoRoot . '/style.css') && is_file($repoRoot . '/functions.php');

if (!is_dir($shopThemeSource)) {
    fwrite(STDERR, "Theme deploy disabled: repository does not contain wp-content/themes/shop.\n");
    if ($rootThemeDetected) {
        fwrite(STDERR, "Detected WordPress theme files at repository root, not in wp-content/themes/shop.\n");
    }
    fwrite(STDERR, "Refusing to copy repository root into {$themeTarget}.\n");
    exit(0);
}

recurse_copy($shopThemeSource, $themeTarget);
fwrite(STDOUT, "Theme deployed from {$shopThemeSource} to {$themeTarget}.\n");
