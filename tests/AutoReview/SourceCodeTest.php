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
    private const array YODA_SCAN_DIRECTORIES = ['src', 'tests'];
    private const array CALL_CHAIN_TOKEN_IDS = [
        \T_STRING,
        \T_VARIABLE,
        \T_NAME_QUALIFIED,
        \T_NAME_FULLY_QUALIFIED,
        \T_NAME_RELATIVE,
        \T_NS_SEPARATOR,
        \T_DOUBLE_COLON,
        \T_OBJECT_OPERATOR,
        \T_NULLSAFE_OBJECT_OPERATOR,
    ];
    private const array CALL_CHAIN_START_TOKEN_IDS = [
        \T_STRING,
        \T_VARIABLE,
        \T_NAME_QUALIFIED,
        \T_NAME_FULLY_QUALIFIED,
        \T_NAME_RELATIVE,
        \T_NS_SEPARATOR,
    ];
    private const array TRIVIA_TOKEN_IDS = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];
    private const array COMPARISON_TOKEN_IDS = [
        \T_IS_IDENTICAL,
        \T_IS_NOT_IDENTICAL,
        \T_IS_EQUAL,
        \T_IS_NOT_EQUAL,
    ];

    /**
     * @var list<class-string>
     */
    private static array $sourceClasses = [];

    /**
     * @var list<array{string, string}>
     */
    private static array $phpFiles = [];

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

    #[DataProvider('provideNoFunctionCallYodaComparisonCases')]
    public function testNoFunctionCallYodaComparison(string $relativePath, string $absolutePath): void
    {
        $source = file_get_contents($absolutePath);
        self::assertIsString($source, \sprintf('Could not read "%s".', $relativePath));

        $report = array_map(
            static fn(array $violation): string => \sprintf(
                '  line %d: `%s %s ...(...)` — function call must be on the LEFT of the comparison operator.',
                $violation['line'],
                $violation['literal'],
                $violation['operator'],
            ),
            self::findFunctionCallYodaViolations($source),
        );

        self::assertSame([], $report, \sprintf(
            "%s contains function-call yoda comparisons (`yoda_style` does not auto-fix these — write `func() === \$x` not `\$x === func()`):\n%s",
            $relativePath,
            implode("\n", $report),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNoFunctionCallYodaComparisonCases(): iterable
    {
        foreach (self::getPhpFiles() as [$relativePath, $absolutePath]) {
            yield $relativePath => [$relativePath, $absolutePath];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideClassNamingConventionsCases')]
    public function testClassNamingConventions(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $name = $reflection->getShortName();

        if ($reflection->isTrait()) {
            self::assertStringEndsNotWith(
                'Trait',
                $name,
                \sprintf('Trait "%s" must NOT use the *Trait suffix.', $class),
            );

            return;
        }

        if ($reflection->isEnum()) {
            self::assertStringEndsNotWith(
                'Enum',
                $name,
                \sprintf('Enum "%s" must NOT use the *Enum suffix.', $class),
            );

            return;
        }

        if (str_starts_with($class, 'Nexus\\Mcp\\Core\\Schema\\')) {
            $this->expectNotToPerformAssertions();

            return;
        }

        if ($reflection->isInterface()) {
            self::assertStringEndsWith('Interface', $name, \sprintf(
                'Interface "%s" outside src/Core/Schema/ must use the *Interface suffix.',
                $class,
            ));

            return;
        }

        if ($reflection->isAbstract()) {
            self::assertStringStartsWith('Abstract', $name, \sprintf(
                'Abstract class "%s" outside src/Core/Schema/ must use the Abstract* prefix.',
                $class,
            ));

            return;
        }

        if ($reflection->isFinal()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $docComment = $reflection->getDocComment();
        self::assertIsString(
            $docComment,
            \sprintf('Concrete class "%s" outside src/Core/Schema/ is not final; a docblock is required.', $class),
        );
        self::assertStringContainsString(
            '@no-final',
            $docComment,
            \sprintf(
                'Concrete class "%s" outside src/Core/Schema/ is not final; its docblock must carry @no-final.',
                $class,
            ),
        );
    }

    /**
     * @return iterable<class-string, array{class-string}>
     */
    public static function provideClassNamingConventionsCases(): iterable
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

            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -4);
            $class = 'Nexus\\Mcp\\'.strtr($relativePath, '/', '\\');

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        self::$sourceClasses = $classes;

        return self::$sourceClasses;
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

    /**
     * @return list<array{string, string}>
     */
    private static function getPhpFiles(): array
    {
        if ([] !== self::$phpFiles) {
            return self::$phpFiles;
        }

        $base = realpath(__DIR__.'/../..');
        \assert(\is_string($base));

        $files = [];

        foreach (self::YODA_SCAN_DIRECTORIES as $dir) {
            $directory = $base.'/'.$dir;

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                \assert($file instanceof \SplFileInfo);

                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), \strlen($base) + 1);
                $files[] = [$relative, $file->getPathname()];
            }
        }

        sort($files);

        self::$phpFiles = $files;

        return self::$phpFiles;
    }

    /**
     * Token-walks the source looking for `<literal> [!=]==? <call-chain>(...)` —
     * a comparison where a literal/keyword sits on the left and a function or
     * method call sits on the right. The `yoda_style` fixer (with
     * `always_move_variable: true`) leaves these alone, so we gate manually.
     *
     * @return list<array{line: int, literal: string, operator: string}>
     */
    private static function findFunctionCallYodaViolations(string $source): array
    {
        $tokens = token_get_all($source);
        $violations = [];

        foreach ($tokens as $i => $token) {
            if (! \is_array($token) || ! self::isLiteralOrKeyword($token)) {
                continue;
            }

            $j = self::skipTrivia($tokens, $i + 1);

            if (! isset($tokens[$j]) || ! self::tokenIs($tokens[$j], self::COMPARISON_TOKEN_IDS)) {
                continue;
            }

            $k = self::skipTrivia($tokens, $j + 1);

            if (! isset($tokens[$k]) || ! self::tokenIs($tokens[$k], self::CALL_CHAIN_START_TOKEN_IDS)) {
                continue;
            }

            if (! self::callChainEndsInOpenParen($tokens, $k)) {
                continue;
            }

            $operator = $tokens[$j];
            $violations[] = [
                'line' => $token[2],
                'literal' => $token[1],
                'operator' => \is_array($operator) ? $operator[1] : $operator,
            ];
        }

        return $violations;
    }

    /**
     * @param array{int, string, int} $token
     */
    private static function isLiteralOrKeyword(array $token): bool
    {
        return match (true) {
            \in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER, \T_DNUMBER], true) => true,
            \T_STRING === $token[0] && \in_array(strtolower($token[1]), ['null', 'true', 'false'], true) => true,
            default => false,
        };
    }

    /**
     * @param array{int, string, int}|string $token
     * @param list<int>                      $ids
     */
    private static function tokenIs(array|string $token, array $ids): bool
    {
        return \is_array($token) && \in_array($token[0], $ids, true);
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function callChainEndsInOpenParen(array $tokens, int $start): bool
    {
        for ($i = $start; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if (\in_array($token[0], [...self::CALL_CHAIN_TOKEN_IDS, \T_WHITESPACE], true)) {
                    continue;
                }

                return false;
            }

            return '(' === $token;
        }

        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function skipTrivia(array $tokens, int $i): int
    {
        while (isset($tokens[$i]) && self::tokenIs($tokens[$i], self::TRIVIA_TOKEN_IDS)) {
            ++$i;
        }

        return $i;
    }
}
