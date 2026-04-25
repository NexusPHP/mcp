<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Nexus\Mcp\Tools;

/**
 * @internal
 */
final class ComposerScripts
{
    private const string PROJECT_ROOT = __DIR__.'/../..';
    private const string VSCODE_SETTINGS_JSON = self::PROJECT_ROOT.'/.vscode/settings.json';
    private const string PHPSTAN_PHAR = self::PROJECT_ROOT.'/vendor/phpstan/phpstan/phpstan.phar';
    private const string PHPSTAN_EXTRACTED_DIR = self::PROJECT_ROOT.'/vendor/phpstan/phpstan-phar';
    private const string INTELEPHENSE_INCLUDE_ENTRY = 'vendor/phpstan/phpstan-phar/';

    public static function postUpdate(): void
    {
        $settingsContents = @file_get_contents(self::VSCODE_SETTINGS_JSON);

        if (false === $settingsContents) {
            return;
        }

        try {
            $phar = new \Phar(self::PHPSTAN_PHAR);
        } catch (\UnexpectedValueException) {
            return;
        }

        self::recursiveDelete(self::PHPSTAN_EXTRACTED_DIR);

        try {
            $phar->extractTo(self::PHPSTAN_EXTRACTED_DIR, null, true);
        } catch (\PharException $e) {
            self::fail(\sprintf('Failed to extract PHPStan phar: %s', $e->getMessage()));
        }

        self::updateVscodeIntelephenseEnvironmentIncludePaths($settingsContents);
    }

    private static function recursiveDelete(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $path = $file->getPathname();

            if ($file->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private static function updateVscodeIntelephenseEnvironmentIncludePaths(string $contents): void
    {
        try {
            /** @var array<string, mixed> $settings */
            $settings = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail(\sprintf('Invalid JSON in %s: %s', self::VSCODE_SETTINGS_JSON, $e->getMessage()));
        }

        /** @var list<string> $includePaths */
        $includePaths = $settings['intelephense.environment.includePaths'] ?? [];

        if (! \in_array(self::INTELEPHENSE_INCLUDE_ENTRY, $includePaths, true)) {
            $includePaths[] = self::INTELEPHENSE_INCLUDE_ENTRY;
        }

        $settings['intelephense.environment.includePaths'] = $includePaths;
        ksort($settings);

        try {
            $newContents = json_encode(
                $settings,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ).\PHP_EOL;
        } catch (\JsonException $e) {
            self::fail(\sprintf('Failed to encode settings: %s', $e->getMessage()));
        }

        if ($newContents === $contents) {
            return;
        }

        if (false === file_put_contents(self::VSCODE_SETTINGS_JSON, $newContents)) {
            self::fail(\sprintf('Cannot write to %s.', self::VSCODE_SETTINGS_JSON));
        }
    }

    private static function fail(string $message): never
    {
        fwrite(\STDERR, $message.\PHP_EOL);

        exit(1);
    }
}
