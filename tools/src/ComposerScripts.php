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
    private const array COMPONENT_DIRECTORIES = ['Core', 'Server', 'Client', 'Extension'];
    private const string DEPENDENCY_ANALYSER_BIN = self::PROJECT_ROOT.'/tools/vendor/bin/composer-dependency-analyser';
    private const string COMPONENT_ANALYSER_CONFIG = self::PROJECT_ROOT.'/composer-dependency-analyser.component.php';
    private const string PHPUNIT_CLOVER = self::PROJECT_ROOT.'/build/phpunit/clover.xml';

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

        self::deleteRecursively(self::PHPSTAN_EXTRACTED_DIR);

        try {
            $phar->extractTo(self::PHPSTAN_EXTRACTED_DIR, null, true);
        } catch (\PharException $e) {
            self::fail(\sprintf('Failed to extract PHPStan phar: %s', $e->getMessage()));
        }

        self::updateVscodeIntelephenseEnvironmentIncludePaths($settingsContents);
    }

    /**
     * Fails the build when any executable statement line under `src/` is uncovered.
     */
    public static function checkCoverage(): void
    {
        if (! is_file(self::PHPUNIT_CLOVER)) {
            self::fail(\sprintf(
                'Coverage report not found at %s. Run "composer test:unit" first.',
                self::PHPUNIT_CLOVER,
            ));
        }

        $contents = file_get_contents(self::PHPUNIT_CLOVER);

        if (false === $contents) {
            self::fail(\sprintf('Cannot read coverage report at %s.', self::PHPUNIT_CLOVER));
        }

        if ('' === $contents) {
            self::fail(\sprintf('Coverage report at %s is empty.', self::PHPUNIT_CLOVER));
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($contents);
        libxml_use_internal_errors($previous);

        if (false === $loaded) {
            self::fail(\sprintf('Invalid XML in coverage report at %s.', self::PHPUNIT_CLOVER));
        }

        $nodes = (new \DOMXPath($dom))->query('//file/line[@type="stmt" and @count="0"]');

        if (false === $nodes) {
            self::fail('Failed to inspect the coverage report.');
        }

        $offenders = [];

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $file = $node->parentNode;

            if (! $file instanceof \DOMElement) {
                continue;
            }

            $offenders[$file->getAttribute('name')][] = $node->getAttribute('num');
        }

        if ([] === $offenders) {
            echo "\nLine coverage is 100%.\n";

            return;
        }

        $root = realpath(self::PROJECT_ROOT);
        $report = [];

        foreach ($offenders as $file => $lines) {
            if (\is_string($root) && str_starts_with($file, $root.\DIRECTORY_SEPARATOR)) {
                $file = substr($file, \strlen($root) + 1);
            }

            foreach ($lines as $line) {
                $report[] = \sprintf('  * %s:%s', $file, $line);
            }
        }

        sort($report);

        self::fail(\sprintf("\nLine coverage is below 100%%. Uncovered statement lines:\n%s", implode("\n", $report)));
    }

    /**
     * Runs the dependency analyser once per component against its own `composer.json`.
     */
    public static function checkComponentDependencies(): void
    {
        $root = realpath(self::PROJECT_ROOT);
        $vendorDir = realpath(self::PROJECT_ROOT.'/vendor');

        if (! \is_string($root) || ! \is_string($vendorDir)) {
            self::fail('Vendor directory not found. Run "composer update" first.');
        }

        $failures = [];

        foreach (self::COMPONENT_DIRECTORIES as $component) {
            $manifest = self::relocateComponentManifest($root, $component, $vendorDir);

            echo \sprintf("\nAnalysing src/%s against %s\n", $component, substr($manifest, \strlen($root) + 1));

            $proc = proc_open(
                [
                    self::DEPENDENCY_ANALYSER_BIN,
                    '--composer-json',
                    $manifest,
                    '--config',
                    self::COMPONENT_ANALYSER_CONFIG,
                ],
                [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
                $pipes,
                $root,
                ['MCP_COMPONENT_DIR' => \sprintf('%s/src/%s', $root, $component)] + getenv(),
            );

            if (false === $proc) {
                self::fail('Failed to launch composer-dependency-analyser.');
            }

            if (proc_close($proc) !== 0) {
                $failures[] = $component;
            }
        }

        if ([] !== $failures) {
            self::fail(\sprintf("\nComponent dependency analysis failed for: %s", implode(', ', $failures)));
        }

        echo "\nEvery component manifest matches its usage.\n";
    }

    /**
     * Writes a copy of the component manifest whose vendor dir is the root one.
     */
    private static function relocateComponentManifest(string $root, string $component, string $vendorDir): string
    {
        $source = \sprintf('%s/src/%s/composer.json', $root, $component);
        $contents = file_get_contents($source);

        if (false === $contents) {
            self::fail(\sprintf('Cannot read %s.', $source));
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
            $manifest['config'] = ['vendor-dir' => $vendorDir];
            $relocated = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail(\sprintf('Invalid JSON in %s: %s', $source, $e->getMessage()));
        }

        $directory = \sprintf('%s/build/deps/%s', $root, strtolower($component));

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
            self::fail(\sprintf('Cannot create %s.', $directory));
        }

        $target = $directory.'/composer.json';

        if (file_put_contents($target, $relocated."\n") === false) {
            self::fail(\sprintf('Cannot write to %s.', $target));
        }

        return $target;
    }

    private static function deleteRecursively(string $directory): void
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
            )."\n";
        } catch (\JsonException $e) {
            self::fail(\sprintf('Failed to encode settings: %s', $e->getMessage()));
        }

        if ($newContents === $contents) {
            return;
        }

        if (file_put_contents(self::VSCODE_SETTINGS_JSON, $newContents) === false) {
            self::fail(\sprintf('Cannot write to %s.', self::VSCODE_SETTINGS_JSON));
        }
    }

    private static function fail(string $message): never
    {
        fwrite(\STDERR, $message."\n");

        exit(1);
    }
}
