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
final class TestCodeTest extends TestCase
{
    private const array RECOGNISED_GROUP_NAMES = [
        'auto-review',
        'client-tests',
        'core-tests',
        'server-tests',
        'stan',
        'unit-tests',
    ];

    /**
     * @var list<class-string<TestCase>>
     */
    private static array $testClasses = [];

    /**
     * @var array<string, array{class-string<TestCase>, non-empty-string}>
     */
    private static array $dataProviderMethods = [];

    /**
     * @param class-string<TestCase> $class
     */
    #[DataProvider('provideTestClassCases')]
    public function testEachTestClassIsFinalAndInternal(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertTrue($reflection->isFinal(), \sprintf('Test class "%s" should be final.', $class));

        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment, \sprintf('Test class "%s" is missing a class-level PHPDoc.', $class));
        self::assertStringContainsString('@internal', $docComment, \sprintf('Test class "%s" should be marked as @internal.', $class));
    }

    /**
     * @param class-string<TestCase> $class
     */
    #[DataProvider('provideTestClassCases')]
    public function testEachTestClassUsesRecognisedGroupsOnly(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Group::class);

        self::assertNotEmpty($attributes, \sprintf('Test class "%s" is missing a #[Group] attribute.', $class));

        $unrecognised = array_diff(
            array_map(static function (\ReflectionAttribute $attribute): string {
                $group = $attribute->newInstance();
                \assert($group instanceof Group);

                return $group->name();
            }, $attributes),
            self::RECOGNISED_GROUP_NAMES,
        );

        self::assertEmpty($unrecognised, \sprintf(
            "Test class \"%s\" has unrecognised #[Group] attribute%s:\n%s\nExpected one of: '%s'.",
            $class,
            \count($unrecognised) > 1 ? 's' : '',
            implode("\n", array_map(
                static fn(string $name): string => \sprintf('  * #[Group(\'%s\')]', $name),
                $unrecognised,
            )),
            implode('\', \'', self::RECOGNISED_GROUP_NAMES),
        ));
    }

    /**
     * Verify each test class declares `#[CoversClass]` for its primary covered
     * class and every in-package ancestor up the inheritance chain.
     *
     * The primary covered class is derived from the test class name by stripping
     * `Nexus\Mcp\Tests\` → `Nexus\Mcp\` and removing the trailing `Test` suffix.
     * Tests that intentionally cover nothing must opt out with `#[CoversNothing]`.
     *
     * @param class-string<TestCase> $class
     */
    #[DataProvider('provideTestClassCases')]
    public function testEachTestClassCoversChainUpToInPackageBases(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        if ([] !== $reflection->getAttributes(CoversNothing::class)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $coversAttributes = $reflection->getAttributes(CoversClass::class);
        self::assertNotEmpty($coversAttributes, \sprintf(
            'Test class "%s" must declare at least one #[CoversClass] (or opt out with #[CoversNothing]).',
            $class,
        ));

        $covered = array_map(static function (\ReflectionAttribute $attribute): string {
            $covers = $attribute->newInstance();
            \assert($covers instanceof CoversClass);

            return $covers->className();
        }, $coversAttributes);

        $primary = str_replace('Nexus\\Mcp\\Tests\\', 'Nexus\\Mcp\\', substr($class, 0, -\strlen('Test')));

        self::assertTrue(class_exists($primary) || interface_exists($primary), \sprintf(
            'Cannot derive primary covered class for test "%s"; expected "%s" to exist.',
            $class,
            $primary,
        ));

        $expected = [$primary];
        $parent = new \ReflectionClass($primary)->getParentClass();

        while (false !== $parent) {
            if (str_starts_with($parent->getNamespaceName(), 'Nexus\\Mcp\\')) {
                $expected[] = $parent->getName();
            }

            $parent = $parent->getParentClass();
        }

        $missing = array_values(array_diff($expected, $covered));
        self::assertEmpty($missing, \sprintf(
            "Test class \"%s\" is missing #[CoversClass] for in-package ancestor%s:\n%s",
            $class,
            \count($missing) > 1 ? 's' : '',
            implode("\n", array_map(
                static fn(string $name): string => \sprintf('  * #[CoversClass(\\%s::class)]', $name),
                $missing,
            )),
        ));

        $unexpected = array_values(array_diff($covered, $expected));
        self::assertEmpty($unexpected, \sprintf(
            "Test class \"%s\" declares unexpected #[CoversClass] attribute%s outside its inheritance chain:\n%s",
            $class,
            \count($unexpected) > 1 ? 's' : '',
            implode("\n", array_map(
                static fn(string $name): string => \sprintf('  * #[CoversClass(\\%s::class)]', $name),
                $unexpected,
            )),
        ));
    }

    /**
     * @return iterable<class-string<TestCase>, array{class-string<TestCase>}>
     */
    public static function provideTestClassCases(): iterable
    {
        foreach (self::getTestClasses() as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string<TestCase> $testClassName
     */
    #[DataProvider('provideDataProviderMethodCases')]
    public function testDataProvidersAreCorrectlyNamed(string $testClassName, string $dataProviderMethod): void
    {
        self::assertMatchesRegularExpression('/^provide[A-Z]\S+Cases$/', $dataProviderMethod, \sprintf(
            'Data provider "%s::%s()" must match `/^provide[A-Z]\\S+Cases$/`.',
            $testClassName,
            $dataProviderMethod,
        ));
    }

    /**
     * @param class-string<TestCase> $testClassName
     */
    #[DataProvider('provideDataProviderMethodCases')]
    public function testDataProvidersDeclareIterableReturnTypeWithShape(string $testClassName, string $dataProviderMethod): void
    {
        if (str_ends_with($testClassName, 'TypeInferenceTest')) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $reflection = new \ReflectionMethod($testClassName, $dataProviderMethod);
        $returnType = $reflection->getReturnType();

        self::assertTrue(
            $returnType instanceof \ReflectionNamedType && 'iterable' === $returnType->getName(),
            \sprintf('Data provider "%s::%s()" must declare `iterable` as method return type.', $testClassName, $dataProviderMethod),
        );

        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment, \sprintf(
            'Data provider "%s::%s()" must have a PHPDoc declaring a typed `@return iterable<...>`.',
            $testClassName,
            $dataProviderMethod,
        ));

        self::assertMatchesRegularExpression(
            '/@return iterable<(?:class-)?string(?:<\S+>)?, array\{/',
            $docComment,
            \sprintf(
                '`@return` PHPDoc of data provider "%s::%s()" must be an iterable of named array shape (e.g. `iterable<string, array{string}>`).',
                $testClassName,
                $dataProviderMethod,
            ),
        );
    }

    /**
     * @return iterable<string, array{class-string<TestCase>, non-empty-string}>
     */
    public static function provideDataProviderMethodCases(): iterable
    {
        if ([] === self::$dataProviderMethods) {
            foreach (self::getTestClasses() as $testClassName) {
                $reflection = new \ReflectionClass($testClassName);

                $providers = array_filter(
                    $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                    static fn(\ReflectionMethod $method): bool => $method->isStatic()
                        && $method->getDeclaringClass()->getName() === $reflection->getName()
                        && str_starts_with($method->getName(), 'provide'),
                );

                foreach ($providers as $method) {
                    self::$dataProviderMethods[$testClassName.'::'.$method->getName()] = [
                        $testClassName,
                        $method->getName(),
                    ];
                }
            }
        }

        yield from self::$dataProviderMethods;
    }

    /**
     * @return list<class-string<TestCase>>
     */
    private static function getTestClasses(): array
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
                || 'php' !== $file->getExtension()
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
