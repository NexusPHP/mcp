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
 * Shared scaffolding for round-trip tests that pin a hand-authored JSON
 * fixture against a schema class's `toArray`/`jsonSerialize` output. Each
 * subclass binds the test to a fixture root and a registry of types, and
 * supplies the wrapper-aware reconstruction step.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
abstract class AbstractRoundTripTestCase extends TestCase
{
    protected const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('provideFixtureRoundTripsToCanonicalWireShapeCases')]
    public function testFixtureRoundTripsToCanonicalWireShape(string $dir, array $entry, string $fixturePath): void
    {
        $jsonString = file_get_contents($fixturePath);
        self::assertIsString($jsonString, \sprintf('Could not read fixture "%s".', $fixturePath));

        $jsonString = rtrim(str_replace("\r\n", "\n", $jsonString), "\n");

        $decoded = json_decode($jsonString, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, \sprintf('Fixture "%s" must decode to a JSON object.', $fixturePath));
        self::assertStringKeyed($decoded, $fixturePath);

        $instance = static::reconstruct($entry, $decoded);

        $reEncoded = json_encode($instance, self::JSON_FLAGS | \JSON_THROW_ON_ERROR);

        self::assertSame($jsonString, $reEncoded, \sprintf(
            'Wire shape for "%s/%s" does not round-trip. Fixture is the source of truth — either the schema class\'s `toArray`/`jsonSerialize` drifted from the spec, or the fixture needs updating.',
            $dir,
            basename($fixturePath, '.json'),
        ));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function provideFixtureRoundTripsToCanonicalWireShapeCases(): iterable
    {
        $root = static::fixtureRoot();

        foreach (static::registry() as $dir => $entry) {
            $directory = $root.'/'.$dir;

            if (! is_dir($directory)) {
                continue;
            }

            $files = glob($directory.'/*.json');

            if (false === $files) {
                self::fail(\sprintf('Could not enumerate JSON fixtures in directory "%s".', $directory));
            }

            sort($files);

            foreach ($files as $file) {
                $variant = basename($file, '.json');

                yield \sprintf('%s/%s', $dir, $variant) => [$dir, $entry, $file];
            }
        }
    }

    public function testNoOrphanFixtureDirectoriesExist(): void
    {
        $registered = [];

        foreach (static::registry() as $dir => $_entry) {
            $registered[$dir] = true;
        }

        $root = static::fixtureRoot();
        $onDisk = glob($root.'/*', \GLOB_ONLYDIR);

        if (false === $onDisk) {
            self::fail(\sprintf('Failed to enumerate fixture directories under "%s".', $root));
        }

        $orphans = [];

        foreach ($onDisk as $path) {
            $name = basename($path);

            if (! isset($registered[$name])) {
                $orphans[] = $name;
            }
        }

        self::assertSame([], $orphans, \sprintf(
            'Fixture directories without a matching entry in static::registry(): %s. Either register them or remove the directory.',
            implode(', ', $orphans),
        ));
    }

    public function testEveryRegisteredEntryHasFixtures(): void
    {
        $empty = [];
        $root = static::fixtureRoot();

        foreach (static::registry() as $dir => $_entry) {
            $directory = $root.'/'.$dir;

            if (! is_dir($directory)) {
                $empty[] = $dir.' (directory missing)';

                continue;
            }

            $files = glob($directory.'/*.json');

            if (! \is_array($files) || [] === $files) {
                $empty[] = $dir.' (no .json fixtures)';
            }
        }

        self::assertSame([], $empty, \sprintf(
            'Registered entries without on-disk fixtures: %s.',
            implode(', ', $empty),
        ));
    }

    /**
     * @return non-empty-string
     */
    abstract protected static function fixtureRoot(): string;

    /**
     * @return iterable<string, array<string, mixed>>
     */
    abstract protected static function registry(): iterable;

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $decoded
     */
    abstract protected static function reconstruct(array $entry, array $decoded): \JsonSerializable;

    /**
     * Asserts the decoded fixture is a string-keyed map (a JSON object). PHPUnit
     * has no built-in equivalent that produces the `array<string, mixed>` shape
     * downstream callers need, so we narrow here.
     *
     * @param array<array-key, mixed> $value
     *
     * @phpstan-assert array<string, mixed> $value
     */
    protected static function assertStringKeyed(array $value, string $fixturePath): void
    {
        foreach (array_keys($value) as $key) {
            self::assertIsString($key, \sprintf('Fixture "%s" must decode to a string-keyed object.', $fixturePath));
        }
    }

    /**
     * Walks `src/` and returns every concrete subclass of the given
     * abstract/interface base — used by completeness gates.
     *
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @return list<class-string<T>>
     */
    protected static function concreteSubclasses(string $base): array
    {
        $matches = [];

        foreach (self::sourceClasses() as $class) {
            if (! is_subclass_of($class, $base)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $matches[] = $class;
        }

        sort($matches);

        return $matches;
    }

    /**
     * Walks `src/` once and caches every concrete/abstract class found there.
     *
     * @return list<class-string>
     */
    private static function sourceClasses(): array
    {
        /** @var null|list<class-string> $cache */
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $directory = realpath(__DIR__.'/../../src');
        self::assertIsString($directory);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $classes = [];

        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);

            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -4);
            $class = 'Nexus\\Mcp\\'.strtr($relativePath, '/', '\\');

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $cache = $classes;
    }
}
