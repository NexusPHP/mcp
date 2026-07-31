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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
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
        \T_IS_SMALLER_OR_EQUAL,
        \T_IS_GREATER_OR_EQUAL,
    ];
    private const array COMPARISON_OPERATOR_STRINGS = ['<', '>'];
    private const array CONTROL_FLOW_KEYWORD_TOKEN_IDS = [
        \T_RETURN,
        \T_WHILE,
        \T_IF,
        \T_ELSEIF,
        \T_FOREACH,
    ];
    private const array COMPOUND_ASSIGNMENT_TOKEN_IDS = [
        \T_AND_EQUAL,
        \T_CONCAT_EQUAL,
        \T_COALESCE_EQUAL,
        \T_DIV_EQUAL,
        \T_MINUS_EQUAL,
        \T_MOD_EQUAL,
        \T_MUL_EQUAL,
        \T_OR_EQUAL,
        \T_PLUS_EQUAL,
        \T_POW_EQUAL,
        \T_SL_EQUAL,
        \T_SR_EQUAL,
        \T_XOR_EQUAL,
    ];

    /**
     * Classes that intentionally provide a public method (instance or static)
     * outside their declared interface contract. Each exemption corresponds to
     * a design constraint that PHP interfaces cannot express:
     *
     * - `JsonRpcResultResponse::toArray()`: the success-response envelope has
     *   no method-name discriminator for results, so it cannot fulfil the
     *   round-trip `fromArray()` half of the `Arrayable` contract.
     * - `InMemoryTransport::createPair()`: paired-construction factory with a
     *   private constructor. Cannot be expressed via `TransportInterface`.
     */
    private const array INTERFACE_FAITHFULNESS_EXEMPT = [
        InMemoryTransport::class,
        JsonRpcResultResponse::class,
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
                '  line %d: `%s %s ...(...)`. Function call must be on the LEFT of the comparison operator.',
                $violation['line'],
                $violation['literal'],
                $violation['operator'],
            ),
            self::findFunctionCallYodaViolations($source),
        );

        self::assertSame([], $report, \sprintf(
            "%s contains function-call yoda comparisons that `yoda_style` does not auto-fix. Put the function call on the left of the comparison:\n%s",
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

    #[DataProvider('provideNoAssignmentInControlFlowExpressionCases')]
    public function testNoAssignmentInControlFlowExpression(string $relativePath, string $absolutePath): void
    {
        $source = file_get_contents($absolutePath);
        self::assertIsString($source, \sprintf('Could not read "%s".', $relativePath));

        $report = array_map(
            static fn(array $violation): string => \sprintf(
                '  line %d: assignment inside `%s` expression. Split the assignment onto its own statement.',
                $violation['line'],
                $violation['keyword'],
            ),
            self::findAssignmentInControlFlowViolations($source),
        );

        self::assertSame([], $report, \sprintf(
            "%s mixes assignment with a control-flow expression (do not write `return \$x = …;`, `while (\$x = …)`, etc.):\n%s",
            $relativePath,
            implode("\n", $report),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNoAssignmentInControlFlowExpressionCases(): iterable
    {
        foreach (self::getPhpFiles() as [$relativePath, $absolutePath]) {
            yield $relativePath => [$relativePath, $absolutePath];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideSeeTagComesLastInClassDocblockCases')]
    public function testSeeTagComesLastInClassDocblock(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $docComment = $reflection->getDocComment();

        if (! \is_string($docComment)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        preg_match_all('/^\s*\*\s*(@\S+)/m', $docComment, $matches);
        $tags = $matches[1];

        if (! \in_array('@see', $tags, true)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        self::assertSame('@see', end($tags), \sprintf(
            'Class "%s": `@see` must be the last tag in the docblock (after `@implements`/`@extends`/`@template*`/`@phpstan-type`), but the last tag is "%s".',
            $class,
            end($tags),
        ));
    }

    /**
     * @return iterable<class-string, array{class-string}>
     */
    public static function provideSeeTagComesLastInClassDocblockCases(): iterable
    {
        foreach (self::getSourceClasses() as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideClassNamingConventionsCases')]
    public function testSourceClassDoesNotExposeProperties(string $class): void
    {
        $rc = new \ReflectionClass($class);

        if ($rc->isInterface()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $externallyMutablePublicProperties = array_filter(
            $rc->getProperties(\ReflectionProperty::IS_PUBLIC),
            static fn(\ReflectionProperty $rp): bool => ! $rp->isReadOnly()
                && ! $rp->isPrivateSet()
                && ! $rp->isProtectedSet(),
        );
        self::assertEmpty($externallyMutablePublicProperties, \sprintf(
            "Class \"%s\" has public properties which are externally mutable. Mark them readonly or restrict set with `private(set)`/`protected(set)`.\n%s",
            $class,
            implode("\n", array_map(
                static fn(\ReflectionProperty $rp): string => \sprintf('  * $%s', $rp->getName()),
                $externallyMutablePublicProperties,
            )),
        ));

        if ($rc->isAbstract()) {
            // Abstract classes own their protected props as forwarded state for subclasses.
            return;
        }

        $declaredProtectedProps = array_map(
            static fn(\ReflectionProperty $rp): string => $rp->getName(),
            $rc->getProperties(\ReflectionProperty::IS_PROTECTED),
        );

        $allowedProtectedProps = [];
        $parent = $rc->getParentClass();

        while (false !== $parent) {
            $allowedProtectedProps = [
                ...$allowedProtectedProps,
                ...array_map(
                    static fn(\ReflectionProperty $rp): string => $rp->getName(),
                    $parent->getProperties(\ReflectionProperty::IS_PROTECTED),
                ),
            ];
            $parent = $parent->getParentClass();
        }

        $extraProtectedProps = array_diff($declaredProtectedProps, $allowedProtectedProps);
        sort($extraProtectedProps);

        self::assertEmpty($extraProtectedProps, \sprintf(
            "Class \"%s\" has protected properties not defined by its parent classes. Consider private visibility.\n%s",
            $class,
            implode("\n", array_map(
                static fn(string $name): string => \sprintf('  * $%s', $name),
                $extraProtectedProps,
            )),
        ));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideSourceClassDoesNotAbuseInterfacesCases')]
    public function testSourceClassDoesNotAbuseInterfaces(string $class): void
    {
        $rc = new \ReflectionClass($class);

        $allowedMethods = ['__construct', '__destruct', '__wakeup'];

        foreach ($rc->getInterfaces() as $interface) {
            $allowedMethods = [...$allowedMethods, ...self::getPublicMethodNames($interface)];
        }

        $parent = $rc->getParentClass();

        while (false !== $parent) {
            $allowedMethods = [...$allowedMethods, ...self::getPublicMethodNames($parent)];
            $parent = $parent->getParentClass();
        }

        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $rm) {
            $docComment = $rm->getDocComment();

            if (\is_string($docComment) && str_contains($docComment, '@internal')) {
                $allowedMethods[] = $rm->getName();
            }
        }

        $extraMethods = array_values(array_diff(self::getPublicMethodNames($rc), array_unique($allowedMethods)));
        sort($extraMethods);

        self::assertEmpty($extraMethods, \sprintf(
            "Class \"%s\" has public methods (instance or static) that are not on an implemented interface, inherited from a parent class, or marked @internal.\n%s",
            $class,
            implode("\n", array_map(
                static fn(string $method): string => \sprintf('  * public function %s()', $method),
                $extraMethods,
            )),
        ));
    }

    /**
     * @return iterable<class-string, array{class-string}>
     */
    public static function provideSourceClassDoesNotAbuseInterfacesCases(): iterable
    {
        foreach (self::getSourceClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            $docComment = $reflection->getDocComment();

            if (\is_string($docComment) && str_contains($docComment, '@internal')) {
                continue;
            }

            if ($reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            if ([] === $reflection->getInterfaceNames()) {
                continue;
            }

            if (\in_array($class, self::INTERFACE_FAITHFULNESS_EXEMPT, true)) {
                continue;
            }

            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideClassNamingConventionsCases')]
    public function testSourceClassDoesNotHaveUnnecessaryProtectedMethods(string $class): void
    {
        $rc = new \ReflectionClass($class);

        if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $declaredProtectedMethods = array_map(
            static fn(\ReflectionMethod $rm): string => $rm->getName(),
            $rc->getMethods(\ReflectionMethod::IS_PROTECTED),
        );

        if ([] === $declaredProtectedMethods) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $allowedProtectedMethods = [];
        $parent = $rc->getParentClass();

        while (false !== $parent) {
            $allowedProtectedMethods = [
                ...$allowedProtectedMethods,
                ...array_map(
                    static fn(\ReflectionMethod $rm): string => $rm->getName(),
                    $parent->getMethods(\ReflectionMethod::IS_PROTECTED),
                ),
            ];
            $parent = $parent->getParentClass();
        }

        $unnecessary = array_diff($declaredProtectedMethods, array_unique($allowedProtectedMethods));
        sort($unnecessary);

        self::assertEmpty($unnecessary, \sprintf(
            "Class \"%s\" has protected method%s not inherited from a parent class. Consider private visibility.\n%s",
            $class,
            \count($unnecessary) > 1 ? 's' : '',
            implode("\n", array_map(
                static fn(string $name): string => \sprintf('  * protected function %s()', $name),
                $unnecessary,
            )),
        ));
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
            \sprintf('Concrete class "%s" outside src/Core/Schema/ is not final. A docblock is required.', $class),
        );
        self::assertStringContainsString(
            '@no-final',
            $docComment,
            \sprintf(
                'Concrete class "%s" outside src/Core/Schema/ is not final. Its docblock must carry @no-final.',
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
     * @template T of object
     *
     * @param \ReflectionClass<T> $rc
     *
     * @return list<string>
     */
    private static function getPublicMethodNames(\ReflectionClass $rc): array
    {
        return array_values(array_map(
            static fn(\ReflectionMethod $rm): string => $rm->getName(),
            $rc->getMethods(\ReflectionMethod::IS_PUBLIC),
        ));
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
     * Token-walks the source looking for `<literal> <comparison> <call-chain>(...)`,
     * a comparison where a literal or keyword sits on the left and a function or
     * method call sits on the right. Covers equality, identity, and the relational
     * operators (`<`, `>`, `<=`, `>=`). The `yoda_style` fixer (with
     * `always_move_variable: true`) leaves function-call operands alone, so this
     * gate enforces them manually.
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

            if (! isset($tokens[$j]) || ! self::isComparisonOperator($tokens[$j])) {
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
     * @param array{int, string, int}|string $token
     */
    private static function isComparisonOperator(array|string $token): bool
    {
        if (\is_string($token)) {
            return \in_array($token, self::COMPARISON_OPERATOR_STRINGS, true);
        }

        return \in_array($token[0], self::COMPARISON_TOKEN_IDS, true);
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

    /**
     * Token-walks the source for `return $x = …;` and `KEYWORD ($x = …)` shapes
     * (`while`, `if`, `elseif`, `foreach`). Distinguishes the bare `=` token from
     * compound operators (`==`, `===`, `+=`, etc.) which carry their own token IDs.
     *
     * @return list<array{line: int, keyword: string}>
     */
    private static function findAssignmentInControlFlowViolations(string $source): array
    {
        $tokens = token_get_all($source);
        $violations = [];

        foreach ($tokens as $i => $token) {
            if (! \is_array($token) || ! \in_array($token[0], self::CONTROL_FLOW_KEYWORD_TOKEN_IDS, true)) {
                continue;
            }

            $keyword = $token[1];
            $line = $token[2];

            if (\T_RETURN === $token[0]) {
                if (self::scanReturnExpressionForAssignment($tokens, $i + 1)) {
                    $violations[] = ['line' => $line, 'keyword' => $keyword];
                }

                continue;
            }

            $parenStart = self::skipTrivia($tokens, $i + 1);

            if (! isset($tokens[$parenStart]) || '(' !== $tokens[$parenStart]) {
                continue;
            }

            if (self::scanParenthesisedExpressionForAssignment($tokens, $parenStart)) {
                $violations[] = ['line' => $line, 'keyword' => $keyword];
            }
        }

        return $violations;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function scanReturnExpressionForAssignment(array $tokens, int $start): bool
    {
        $depth = 0;

        for ($i = $start; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                if (0 === $depth && ';' === $token) {
                    return false;
                }

                if (0 === $depth && '=' === $token) {
                    return true;
                }

                if (\in_array($token, ['(', '[', '{'], true)) {
                    ++$depth;
                } elseif (\in_array($token, [')', ']', '}'], true)) {
                    --$depth;
                }
            } elseif (\T_CURLY_OPEN === $token[0] || \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                ++$depth;
            } elseif (0 === $depth && \in_array($token[0], self::COMPOUND_ASSIGNMENT_TOKEN_IDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function scanParenthesisedExpressionForAssignment(array $tokens, int $openParenIndex): bool
    {
        $depth = 1;

        for ($i = $openParenIndex + 1; isset($tokens[$i]) && $depth > 0; ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                if ('=' === $token) {
                    return true;
                }

                if (\in_array($token, ['(', '[', '{'], true)) {
                    ++$depth;
                } elseif (\in_array($token, [')', ']', '}'], true)) {
                    --$depth;
                }
            } elseif (\T_CURLY_OPEN === $token[0] || \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                ++$depth;
            } elseif (\in_array($token[0], self::COMPOUND_ASSIGNMENT_TOKEN_IDS, true)) {
                return true;
            }
        }

        return false;
    }
}
