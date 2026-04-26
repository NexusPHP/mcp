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

namespace Nexus\Mcp\Tests\AutoReview;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class SourceCodeTest extends TestCase
{
    private const int DOCBLOCK_SUMMARY_MAX_WIDTH = 120;

    /**
     * @var list<class-string>
     */
    private static array $sourceClasses = [];

    /**
     * @param class-string $class
     */
    #[DataProvider('provideClassDocblockSummaryFitsWidthCases')]
    public function testClassDocblockSummaryFitsWidth(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $docComment = $reflection->getDocComment();

        if (! \is_string($docComment)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $offending = [];

        foreach (self::extractDocblockSummaryLines($docComment) as $line) {
            $width = mb_strlen($line);

            if ($width > self::DOCBLOCK_SUMMARY_MAX_WIDTH) {
                $offending[] = \sprintf('  (%d chars) %s', $width, $line);
            }
        }

        self::assertSame([], $offending, \sprintf(
            "Class \"%s\" docblock summary lines must be ≤ %d chars wide so VSCode and GitHub do not horizontal-scroll:\n%s",
            $class,
            self::DOCBLOCK_SUMMARY_MAX_WIDTH,
            implode("\n", $offending),
        ));
    }

    /**
     * @return iterable<class-string, array{class-string}>
     */
    public static function provideClassDocblockSummaryFitsWidthCases(): iterable
    {
        foreach (self::getSourceClasses() as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @return list<class-string>
     */
    private static function getSourceClasses(): array
    {
        if ([] !== self::$sourceClasses) {
            return self::$sourceClasses;
        }

        $directory = realpath(__DIR__.'/../../src');
        \assert(\is_string($directory));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $classes = [];

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);

            if (! $file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -4);
            $class = 'Nexus\\Mcp\\'.strtr($relativePath, '/', '\\');

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return self::$sourceClasses = $classes;
    }

    /**
     * Returns the full physical lines that form the docblock summary
     * (everything between `/` + `**` and the first `@`-tag), so callers can
     * measure the rendered width including the leading ` * ` prefix.
     *
     * @return list<string>
     */
    private static function extractDocblockSummaryLines(string $docComment): array
    {
        $lines = [];

        foreach (explode("\n", $docComment) as $line) {
            $trimmed = trim($line);

            if ('/**' === $trimmed || '*/' === $trimmed) {
                continue;
            }

            $content = preg_replace('/^\s*\*\s?/', '', $line) ?? '';

            if (str_starts_with(ltrim($content), '@')) {
                break;
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
