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

use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class TestCodeTest extends AbstractMcpTestCase
{
    /**
     * @var list<class-string<TestCase>>
     */
    private static array $testClasses = [];

    /**
     * @var list<class-string>
     */
    private static array $sourceClasses = [];

    /**
     * @var array<class-string, list<class-string>>
     */
    private static array $coveredClassesByTest = [];

    /**
     * Verify each concrete source class has a same-named test class or is referenced via `#[CoversClass]` somewhere.
     *
     * @param class-string $class
     */
    #[DataProvider('provideEachSourceClassHasTestClassCases')]
    public function testEachSourceClassHasTestClass(string $class): void
    {
        $expectedTestClassName = str_replace('Nexus\\Mcp\\', 'Nexus\\Mcp\\Tests\\', $class).'Test';

        if (! class_exists($expectedTestClassName)) {
            foreach ($this->getCoveredClassesByTest() as $testClassName => $coveredClasses) {
                if (\in_array($class, $coveredClasses, true)) {
                    $expectedTestClassName = $testClassName;

                    break;
                }
            }
        }

        self::assertTrue(class_exists($expectedTestClassName), \sprintf(
            'Expected test class "%s" for "%s" was not found. Add a same-named test class or reference the source class via #[CoversClass] in some test.',
            $expectedTestClassName,
            $class,
        ));
    }

    /**
     * @return iterable<class-string, array{class-string}>
     */
    public static function provideEachSourceClassHasTestClassCases(): iterable
    {
        foreach (self::getSourceClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            if ($reflection->isEnum()) {
                $publicMethods = array_filter(
                    array_map(
                        static fn(\ReflectionMethod $rm): string => $rm->getName(),
                        $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                    ),
                    static fn(string $name): bool => ! \in_array($name, ['from', 'tryFrom', 'cases'], true),
                );

                if ([] === $publicMethods) {
                    continue;
                }
            }

            if ($reflection->isSubclassOf(\Throwable::class)) {
                $declaredHere = array_filter(
                    $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                    static fn(\ReflectionMethod $rm): bool => $rm->getDeclaringClass()->getName() === $reflection->getName()
                        && $rm->getName() !== '__construct',
                );

                if ([] === $declaredHere) {
                    continue;
                }
            }

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
     * @return array<class-string, list<class-string>>
     */
    private function getCoveredClassesByTest(): array
    {
        if ([] !== self::$coveredClassesByTest) {
            return self::$coveredClassesByTest;
        }

        $index = [];

        foreach ($this->getTestClasses() as $testClassName) {
            $reflection = new \ReflectionClass($testClassName);
            $covered = array_map(
                static function (\ReflectionAttribute $attribute): string {
                    $covers = $attribute->newInstance();
                    \assert($covers instanceof CoversClass);

                    return $covers->className();
                },
                $reflection->getAttributes(CoversClass::class),
            );

            if ([] !== $covered) {
                $index[$testClassName] = $covered;
            }
        }

        self::$coveredClassesByTest = $index;

        return self::$coveredClassesByTest;
    }

    /**
     * @return list<class-string<TestCase>>
     */
    private function getTestClasses(): array
    {
        if ([] !== self::$testClasses) {
            return self::$testClasses;
        }

        $directory = realpath(__DIR__.'/..');
        \assert(\is_string($directory));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $testClasses = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (
                ! $file->isFile()
                || $file->getExtension() !== 'php'
                || str_contains($file->getPath(), \DIRECTORY_SEPARATOR.'Fixtures')
                || str_contains($file->getPath(), \DIRECTORY_SEPARATOR.'data')
            ) {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1);
            $relativePath = substr($relativePath, 0, -\strlen(\DIRECTORY_SEPARATOR.$file->getBasename()));

            $testClass = \sprintf(
                'Nexus\\Mcp\\Tests\\%s%s%s',
                strtr($relativePath, \DIRECTORY_SEPARATOR, '\\'),
                '' === $relativePath ? '' : '\\',
                $file->getBasename('.php'),
            );

            if (! is_subclass_of($testClass, TestCase::class)) {
                continue;
            }

            $testClasses[] = $testClass;
        }

        sort($testClasses);
        self::$testClasses = $testClasses;

        return $testClasses;
    }
}
