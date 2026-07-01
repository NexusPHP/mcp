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
    private const string INFECTION_BIN = self::PROJECT_ROOT.'/tools/vendor/bin/infection';
    private const string INFECTION_TMP_DIR = self::PROJECT_ROOT.'/build/infection';
    private const array MUTATION_SOURCE_DIRECTORIES = ['src', 'tests'];
    private const string PHPUNIT_CLOVER = self::PROJECT_ROOT.'/build/phpunit/clover.xml';

    /**
     * Native binaries used by the doc-lint composer scripts, mapped to their
     * Homebrew formula names.
     */
    private const array DOC_LINTER_BINARIES = [
        'typos' => 'typos-cli',
        'markdownlint-cli2' => 'markdownlint-cli2',
    ];

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

    /**
     * Installs the native binaries used by the `lint:*` composer scripts when
     * missing. Mirrors the bootstrap pattern that `tools/composer.json` provides
     * for PHP dev tools.
     */
    public static function installDocLinters(): void
    {
        $missing = [];

        foreach (self::DOC_LINTER_BINARIES as $binary => $formula) {
            if (! self::commandExists($binary)) {
                $missing[$formula] = true;
            }
        }

        if ([] === $missing) {
            return;
        }

        $formulas = array_keys($missing);

        if ('Darwin' !== \PHP_OS_FAMILY) {
            fwrite(\STDERR, \sprintf(
                "Doc linters not installed: %s. Install them with your platform's package manager.\n",
                implode(', ', $formulas),
            ));

            return;
        }

        if (! self::commandExists('brew')) {
            fwrite(\STDERR, \sprintf(
                "Homebrew not found. Install doc linters manually: %s.\n",
                implode(', ', $formulas),
            ));

            return;
        }

        $proc = proc_open(['brew', 'install', ...$formulas], [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes);

        if (false === $proc) {
            self::fail('Failed to launch brew.');
        }

        $exitCode = proc_close($proc);

        if (0 !== $exitCode) {
            self::fail(\sprintf('brew install failed for: %s', implode(', ', $formulas)));
        }
    }

    /**
     * Reclaims the per-mutant PHPUnit result-cache directories that Infection
     * leaves in its temp dir (one `--cache-directory` per forked mutant).
     */
    public static function cleanInfectionResultCaches(): void
    {
        self::pruneInfectionResultCaches();
        register_shutdown_function(self::pruneInfectionResultCaches(...));
    }

    /**
     * Runs Infection scoped to the diff against `origin/1.x`, including
     * untracked files that `git diff` would not otherwise see. Marks each
     * untracked source file as intent-to-add for the duration of the run, then
     * clears the markers on exit so the working tree returns to its prior state.
     */
    public static function runFilteredMutation(): void
    {
        chdir(self::PROJECT_ROOT);

        $untracked = self::listUntrackedSourceFiles();

        if ([] !== $untracked) {
            self::runGitCommand('add', '--intent-to-add', '--', ...$untracked);
            register_shutdown_function(static function () use ($untracked): void {
                self::runGitCommand('reset', '--quiet', 'HEAD', '--', ...$untracked);
            });
        }

        $command = [
            self::INFECTION_BIN,
            '--with-uncovered',
            '--static-analysis-tool=phpstan',
            '--git-diff-filter=AM',
            '--git-diff-base=origin/1.x',
        ];

        $proc = proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes);

        if (false === $proc) {
            self::fail('Failed to launch infection.');
        }

        $exitCode = proc_close($proc);

        if (0 !== $exitCode) {
            exit($exitCode);
        }
    }

    /**
     * Fails the build when any executable statement line under `src/` is left
     * uncovered. Reads the Clover report emitted by `test:unit`, so it must run
     * after it. Bridges the gap Infection cannot see: a line with no generated
     * mutant stays invisible to MSI even when no test exercises it.
     */
    public static function checkCoverage(): void
    {
        if (! is_file(self::PHPUNIT_CLOVER)) {
            self::fail(\sprintf('Coverage report not found at %s. Run "composer test:unit" first.', self::PHPUNIT_CLOVER));
        }

        $contents = file_get_contents(self::PHPUNIT_CLOVER);

        if (false === $contents) {
            self::fail(\sprintf('Cannot read coverage report at %s.', self::PHPUNIT_CLOVER));
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($contents);
        libxml_use_internal_errors($previous);

        if (false === $loaded) {
            self::fail(\sprintf('Invalid XML in coverage report at %s.', self::PHPUNIT_CLOVER));
        }

        $nodes = new \DOMXPath($dom)->query('//file/line[@type="stmt" and @count="0"]');

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

    private static function pruneInfectionResultCaches(): void
    {
        $caches = glob(self::INFECTION_TMP_DIR.'/.phpunit.result.cache.*', \GLOB_ONLYDIR);

        if (false === $caches) {
            return;
        }

        foreach ($caches as $cache) {
            self::recursiveDelete($cache);
        }
    }

    /**
     * @return list<string>
     */
    private static function listUntrackedSourceFiles(): array
    {
        $output = shell_exec(implode(' ', array_map(escapeshellarg(...), [
            'git',
            'ls-files',
            '--others',
            '--exclude-standard',
            '--',
            ...self::MUTATION_SOURCE_DIRECTORIES,
        ])));

        if (! \is_string($output) || '' === trim($output)) {
            return [];
        }

        $lines = preg_split('/\R/', trim($output));

        if (false === $lines) {
            return [];
        }

        return array_values(array_filter($lines, static fn(string $line): bool => '' !== $line));
    }

    private static function runGitCommand(string ...$args): void
    {
        passthru(implode(' ', array_map(escapeshellarg(...), ['git', ...$args])));
    }

    private static function commandExists(string $command): bool
    {
        $output = shell_exec(\sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

        return \is_string($output) && '' !== trim($output);
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
            )."\n";
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
        fwrite(\STDERR, $message."\n");

        exit(1);
    }
}
