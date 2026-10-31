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

namespace Nexus\Mcp\Tests\Server\Discovery;

use Nexus\Mcp\Server\Discovery\DocBlockNameResolver;
use Nexus\Mcp\Server\Discovery\DocBlockTypeResolver;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\Names\ImportedNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DocBlockNameResolver::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DocBlockNameResolverTest extends AbstractMcpTestCase
{
    private const string FIXTURES = 'Nexus\Mcp\Tests\Fixtures\Server\Discovery';

    private static int $sourceCount = 0;

    #[DataProvider('provideQualifiesTypeCases')]
    public function testQualifiesType(string $class, string $method, string $type, string $expected): void
    {
        if (str_contains($class, 'Names\First') || str_contains($class, 'Names\Second')) {
            require_once __DIR__.'/../../Fixtures/Server/Discovery/Names/MultipleNamespaces.php';
        }

        $node = (new DocBlockTypeResolver())->parseNativeType($type);

        (new DocBlockNameResolver())->qualify($node, new \ReflectionMethod($class, $method));

        self::assertSame($expected, (string) $node);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideQualifiesTypeCases(): iterable
    {
        $imported = ImportedNames::class;
        $first = self::named('Names\First\FirstScope');
        $second = self::named('Names\Second\SecondScope');

        yield 'imported class' => [$imported, 'resolve', 'list<BackedIntEnum>', self::listOf('BackedIntEnum')];

        yield 'aliased import' => [$imported, 'resolve', 'list<Colour>', self::listOf('BackedStringEnum')];

        yield 'second import of the file' => [$imported, 'resolve', 'list<PureEnum>', self::listOf('PureEnum')];

        yield 'second aliased import of the file' => [$imported, 'resolve', 'list<Point>', self::listOf('Coordinate')];

        yield 'class of the declaring namespace' => [
            $imported,
            'resolve',
            'list<LocalEnum>',
            self::listOf('Names\LocalEnum'),
        ];

        yield 'fully qualified name' => [
            $imported,
            'resolve',
            'list<\\'.self::named('BackedIntEnum').'>',
            'list<\\'.self::named('BackedIntEnum').'>',
        ];

        yield 'unknown name' => [$imported, 'resolve', 'list<Missing>', 'list<Missing>'];

        yield 'builtin type' => [$imported, 'resolve', 'list<string>', 'list<string>'];

        yield 'shape key named like an import' => [
            $imported,
            'resolve',
            'array{Colour: Colour}',
            'array{Colour: '.self::named('BackedStringEnum').'}',
        ];

        yield 'object shape key named like an import' => [
            $imported,
            'resolve',
            'object{Colour: Colour}',
            'object{Colour: '.self::named('BackedStringEnum').'}',
        ];

        yield 'nested in a union' => [
            $imported,
            'resolve',
            'array<string, BackedIntEnum|null>',
            'array<string, ('.self::named('BackedIntEnum').' | null)>',
        ];

        yield 'method declared by a trait uses the trait file imports' => [
            $imported,
            'declaredByTrait',
            'list<Colour>',
            'list<Colour>',
        ];

        yield 'method declared by a trait uses the trait namespace' => [
            $imported,
            'declaredByTrait',
            'list<LocalEnum>',
            self::listOf('Names\LocalEnum'),
        ];

        yield 'first of several namespaces' => [
            $first,
            'declare',
            'list<BackedIntEnum>',
            self::listOf('BackedIntEnum'),
        ];

        yield 'first namespace has no import of the second' => [
            $first,
            'declare',
            'list<BackedStringEnum>',
            'list<BackedStringEnum>',
        ];

        yield 'second of several namespaces' => [
            $second,
            'declare',
            'list<BackedStringEnum>',
            self::listOf('BackedStringEnum'),
        ];

        yield 'second namespace has no import of the first' => [
            $second,
            'declare',
            'list<BackedIntEnum>',
            'list<BackedIntEnum>',
        ];
    }

    public function testResolvesAgainstTheGlobalNamespaceWhenTheSourceIsUnreadable(): void
    {
        $closure = eval('return static function (): void {};');
        self::assertInstanceOf(\Closure::class, $closure);

        $node = (new DocBlockTypeResolver())->parseNativeType('list<ArrayObject>');

        (new DocBlockNameResolver())->qualify($node, new \ReflectionFunction($closure));

        self::assertSame('list<ArrayObject>', (string) $node);
    }

    public function testIgnoresFunctionAndConstantImports(): void
    {
        $type = 'array{0: aFunction, 1: aConstant, 2: another, 3: third}';

        self::assertSame($type, $this->qualifyAgainstSource(
            <<<'PHP'
                use function %1$s\BackedIntEnum as aFunction;
                use const %1$s\BackedStringEnum as aConstant;
                use %1$s\{function PureEnum as another, const Coordinate as third};
                PHP,
            $type,
        ));
    }

    public function testResolvesGroupedImports(): void
    {
        self::assertSame(
            \sprintf('array{0: %1$s\BackedIntEnum, 1: %1$s\Coordinate, 2: %1$s\PureEnum}', self::FIXTURES),
            $this->qualifyAgainstSource(
                'use %1$s\{BackedIntEnum, Coordinate as Point, PureEnum};',
                'array{0: BackedIntEnum, 1: Point, 2: PureEnum}',
            ),
        );
    }

    public function testResolvesAnImportedNamespacePrefix(): void
    {
        self::assertSame(
            self::listOf('BackedIntEnum'),
            $this->qualifyAgainstSource('use %1$s as Fixtures;', 'list<Fixtures\BackedIntEnum>'),
        );
    }

    public function testResolvesImportsWithALeadingSeparator(): void
    {
        self::assertSame(
            self::listOf('BackedIntEnum'),
            $this->qualifyAgainstSource('use \%1$s\BackedIntEnum;', 'list<BackedIntEnum>'),
        );
    }

    public function testASeveralClauseImportRegistersEachClause(): void
    {
        self::assertSame(
            \sprintf('array{0: %1$s\BackedIntEnum, 1: %1$s\PureEnum}', self::FIXTURES),
            $this->qualifyAgainstSource(
                'use %1$s\BackedIntEnum, %1$s\PureEnum;',
                'array{0: BackedIntEnum, 1: PureEnum}',
            ),
        );
    }

    public function testAClosureUseInsideAMethodIsNotAnImport(): void
    {
        self::assertSame(
            'list<BackedIntEnum>',
            $this->qualifyAgainstSource(
                '',
                'list<BackedIntEnum>',
                'public function declare(): void { $x = 1; $f = function () use ($x): void {}; }',
            ),
        );
    }

    public function testASourceWithoutANamespaceResolvesAgainstTheGlobalNamespace(): void
    {
        self::assertSame(
            'list<ArrayObject>',
            $this->qualifyAgainstSource('', 'list<ArrayObject>', namespaced: false),
        );
    }

    public function testAnInterfaceImportIsQualified(): void
    {
        self::assertSame(
            'list<Countable>',
            $this->qualifyAgainstSource('use Countable as Thing;', 'list<Thing>', namespaced: false),
        );
    }

    public function testAClassImportIsQualified(): void
    {
        self::assertSame(
            'list<ArrayObject>',
            $this->qualifyAgainstSource('use ArrayObject as Bag;', 'list<Bag>', namespaced: false),
        );
    }

    public function testAnUppercaseAliasKeywordIsRead(): void
    {
        self::assertSame(
            self::listOf('BackedIntEnum'),
            $this->qualifyAgainstSource('use %1$s\BackedIntEnum AS Number;', 'list<Number>'),
        );
    }

    public function testANamespaceOnTheLineOfTheMethodApplies(): void
    {
        $file = $this->writeSource(\sprintf(
            <<<'PHP'
                <?php namespace NamesFixture\Inline; use %1$s\BackedIntEnum as Inline; final class Holder { public function declare(): void {} }
                PHP,
            self::FIXTURES,
        ));

        try {
            require_once $file;

            $node = (new DocBlockTypeResolver())->parseNativeType('list<Inline>');
            $method = new \ReflectionMethod('NamesFixture\Inline\Holder', 'declare');

            (new DocBlockNameResolver())->qualify($node, $method);

            self::assertSame(self::listOf('BackedIntEnum'), (string) $node);
        } finally {
            unlink($file);
        }
    }

    public function testAnImportAfterAClassIsRead(): void
    {
        $file = $this->writeSource(\sprintf(
            <<<'PHP'
                <?php
                namespace NamesFixture\Trailing;
                final class First {}
                use %1$s\BackedIntEnum as Late;
                final class Second { public function declare(): void {} }

                PHP,
            self::FIXTURES,
        ));

        try {
            require_once $file;

            $node = (new DocBlockTypeResolver())->parseNativeType('list<Late>');
            $method = new \ReflectionMethod('NamesFixture\Trailing\Second', 'declare');

            (new DocBlockNameResolver())->qualify($node, $method);

            self::assertSame(self::listOf('BackedIntEnum'), (string) $node);
        } finally {
            unlink($file);
        }
    }

    public function testAnInterpolatedExpressionDoesNotUnbalanceTheNesting(): void
    {
        $file = $this->writeSource(\sprintf(
            <<<'PHP'
                <?php
                namespace NamesFixture\Interpolated;
                final class First { public function render(string $a): string { return "{$a}"; } }
                use %1$s\BackedIntEnum as Late;
                final class Second { public function declare(): void {} }

                PHP,
            self::FIXTURES,
        ));

        try {
            require_once $file;

            $node = (new DocBlockTypeResolver())->parseNativeType('list<Late>');
            $method = new \ReflectionMethod('NamesFixture\Interpolated\Second', 'declare');

            (new DocBlockNameResolver())->qualify($node, $method);

            self::assertSame(self::listOf('BackedIntEnum'), (string) $node);
        } finally {
            unlink($file);
        }
    }

    public function testReadsEachSourceFileOnce(): void
    {
        $file = $this->writeSource(\sprintf(
            <<<'PHP'
                <?php
                namespace NamesFixture\Cached;
                use %1$s\BackedIntEnum as Cached;
                final class Holder { public function declare(): void {} }

                PHP,
            self::FIXTURES,
        ));

        try {
            require_once $file;

            $resolver = new DocBlockNameResolver();
            $method = new \ReflectionMethod('NamesFixture\Cached\Holder', 'declare');
            $first = (new DocBlockTypeResolver())->parseNativeType('list<Cached>');
            $second = (new DocBlockTypeResolver())->parseNativeType('list<Cached>');

            $resolver->qualify($first, $method);
            file_put_contents($file, <<<'PHP'
                <?php
                namespace NamesFixture\Cached;

                PHP);
            $resolver->qualify($second, $method);

            self::assertSame(self::listOf('BackedIntEnum'), (string) $first);
            self::assertSame((string) $first, (string) $second);
        } finally {
            unlink($file);
        }
    }

    private static function named(string $short): string
    {
        return self::FIXTURES.'\\'.$short;
    }

    private static function listOf(string $short): string
    {
        return 'list<'.self::named($short).'>';
    }

    private function writeSource(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'names');
        self::assertIsString($file);
        file_put_contents($file, $code);

        return $file;
    }

    private function qualifyAgainstSource(
        string $imports,
        string $type,
        string $body = 'public function declare(): void {}',
        bool $namespaced = true,
    ): string {
        $class = 'Holder'.++self::$sourceCount;
        $file = $this->writeSource(\sprintf(
            <<<'PHP'
                <?php
                %s
                %s
                final class %s { %s }

                PHP,
            $namespaced ? 'namespace NamesFixture;' : '',
            \sprintf($imports, self::FIXTURES),
            $class,
            $body,
        ));

        try {
            require_once $file;

            $node = (new DocBlockTypeResolver())->parseNativeType($type);
            $method = new \ReflectionMethod($namespaced ? 'NamesFixture\\'.$class : $class, 'declare');

            (new DocBlockNameResolver())->qualify($node, $method);

            return (string) $node;
        } finally {
            unlink($file);
        }
    }
}
