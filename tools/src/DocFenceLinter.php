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
 * Lints every ```php fence in the repository's markdown against the declared PHP
 * floor, reporting a fence that parses on a newer runtime but not on the floor.
 */
final class DocFenceLinter
{
    /**
     * Directories holding third-party or generated markdown.
     */
    private const string EXCLUDED = '#/(vendor|node_modules|build|api/docs|\.git)/#';

    /**
     * @return int the process exit code
     */
    public static function run(string $root, string $floorBinary, string $newerBinary): int
    {
        $floor = self::readDeclaredFloor($root);
        self::assertBinaryMatches($floorBinary, $floor);

        $drifted = [];
        $fragments = 0;
        $scanned = 0;
        $workspace = self::createWorkspace();

        try {
            foreach (self::findMarkdown($root) as $file) {
                foreach (self::extractFences($file) as [$line, $code]) {
                    ++$scanned;
                    $onFloor = self::lint($floorBinary, $code, $workspace);

                    if (null === $onFloor) {
                        continue;
                    }

                    if (self::lint($newerBinary, $code, $workspace) !== null) {
                        ++$fragments;

                        continue;
                    }

                    $drifted[] = [substr($file, \strlen($root) + 1), $line, $onFloor];
                }
            }
        } finally {
            self::removeWorkspace($workspace);
        }

        foreach ($drifted as [$relative, $line, $error]) {
            printf("\033[31m%s:%d\033[0m %s\n", $relative, $line, $error);
        }

        printf(
            "\n%d fences scanned against PHP %s, %d drifted, %d partial fragments ignored.\n",
            $scanned,
            $floor,
            \count($drifted),
            $fragments,
        );

        if ([] !== $drifted) {
            printf(
                "\n\033[31mEvery fence above parses on a newer PHP but not on the %s floor.\033[0m\n",
                $floor,
            );

            return 1;
        }

        echo "\033[32mNo documentation fence uses syntax newer than the declared floor.\033[0m\n";

        return 0;
    }

    /**
     * Reads the floor from the package manifest so the gate cannot drift from the
     * version the package claims to support.
     */
    private static function readDeclaredFloor(string $root): string
    {
        $manifest = $root.'/composer.json';
        $raw = file_get_contents($manifest);

        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Could not read "%s".', $manifest));
        }

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            throw new \RuntimeException('composer.json does not decode to an object.');
        }

        $require = $decoded['require'] ?? null;
        $constraint = \is_array($require) ? $require['php'] ?? null : null;

        if (! \is_string($constraint)) {
            throw new \RuntimeException('composer.json declares no "require.php" constraint.');
        }

        if (preg_match('/(\d+)\.(\d+)/', $constraint, $matches) !== 1) {
            throw new \RuntimeException(\sprintf('Could not read a floor version from "%s".', $constraint));
        }

        return $matches[1].'.'.$matches[2];
    }

    /**
     * A gate that silently tested the wrong runtime would be worse than no gate, so
     * a missing or mismatched floor binary is fatal rather than a skip.
     */
    private static function assertBinaryMatches(string $binary, string $floor): void
    {
        exec(escapeshellarg($binary).' -n -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;" 2>&1', $output, $status);

        if (0 !== $status) {
            throw new \RuntimeException(\sprintf(
                'Could not run the floor PHP binary "%s". Pass --floor=<path> or set PHP_FLOOR_BIN.',
                $binary,
            ));
        }

        $reported = trim(implode('', $output));

        if ($reported !== $floor) {
            throw new \RuntimeException(\sprintf(
                'Binary "%s" reports PHP %s, but composer.json declares a %s floor.',
                $binary,
                $reported,
                $floor,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private static function findMarkdown(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $path = $entry->getPathname();

            if (! str_ends_with($path, '.md') || preg_match(self::EXCLUDED, $path) === 1) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array{int, string}> the 1-indexed opening line and the fence body
     */
    private static function extractFences(string $file): array
    {
        $lines = file($file, \FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            throw new \RuntimeException(\sprintf('Could not read "%s".', $file));
        }

        $fences = [];
        $open = false;
        $start = 0;
        $buffer = [];

        foreach ($lines as $index => $line) {
            if (! $open && preg_match('/^\s*```php\s*$/', $line) === 1) {
                $open = true;
                $start = $index + 2;
                $buffer = [];

                continue;
            }

            if ($open && preg_match('/^\s*```\s*$/', $line) === 1) {
                $open = false;
                $fences[] = [$start, implode("\n", $buffer)];

                continue;
            }

            if ($open) {
                $buffer[] = $line;
            }
        }

        return $fences;
    }

    /**
     * @return null|string the first diagnostic line, or null when the fence parses
     */
    private static function lint(string $binary, string $code, string $workspace): ?string
    {
        $snippet = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n".$code;
        $file = $workspace.'/fence.php';
        file_put_contents($file, $snippet);

        $output = [];
        exec(\sprintf('%s -n -l %s 2>&1', escapeshellarg($binary), escapeshellarg($file)), $output, $status);

        if (0 === $status) {
            return null;
        }

        $diagnostic = $output[1] ?? $output[0] ?? 'Unknown parse failure.';

        return trim((string) preg_replace('/ in \S+ on line \d+$/', '', $diagnostic));
    }

    private static function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/mcp-doc-fences-'.bin2hex(random_bytes(6));

        if (! mkdir($workspace, 0o700, true) && ! is_dir($workspace)) {
            throw new \RuntimeException(\sprintf('Could not create the scratch directory "%s".', $workspace));
        }

        return $workspace;
    }

    private static function removeWorkspace(string $workspace): void
    {
        $file = $workspace.'/fence.php';

        if (is_file($file)) {
            unlink($file);
        }

        if (is_dir($workspace)) {
            rmdir($workspace);
        }
    }
}
