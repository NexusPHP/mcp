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
}
