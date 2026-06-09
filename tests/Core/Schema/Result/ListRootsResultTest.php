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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListRootsResult;
use Nexus\Mcp\Core\Schema\Root;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListRootsResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListRootsResultTest extends TestCase
{
    public function testConstructionDefaultsMetaToNull(): void
    {
        $result = new ListRootsResult(roots: [new Root(uri: 'file:///x')]);

        self::assertCount(1, $result->roots);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsEmptyRootsList(): void
    {
        $result = new ListRootsResult(roots: []);

        self::assertSame([], $result->roots);
    }

    public function testConstructorRejectsNonListRoots(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.roots" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ListRootsResult(roots: [5 => new Root(uri: 'file:///x')]);
    }

    public function testConstructorRejectsNonRootElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListRootsResult(roots: [42]);
    }

    public function testToArrayEmitsRoots(): void
    {
        $result = new ListRootsResult(roots: [
            new Root(uri: 'file:///a', name: 'project-a'),
            new Root(uri: 'file:///b'),
        ]);

        self::assertSame(
            [
                'resultType' => 'complete',
                'roots' => [
                    ['uri' => 'file:///a', 'name' => 'project-a'],
                    ['uri' => 'file:///b'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesMetaWhenPresent(): void
    {
        $result = new ListRootsResult(
            roots: [new Root(uri: 'file:///x')],
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'roots' => [['uri' => 'file:///x']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListRootsResult(
            roots: [new Root(uri: 'file:///x', name: 'project')],
            meta: new MetaObject(extras: ['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyRoots(): void
    {
        $result = ListRootsResult::fromArray(['roots' => []]);

        self::assertSame([], $result->roots);
        self::assertSame([], $result->meta->toArray());
    }

    public function testFromArrayParsesRootsAndMeta(): void
    {
        $result = ListRootsResult::fromArray([
            'roots' => [
                ['uri' => 'file:///a', 'name' => 'a'],
                ['uri' => 'file:///b'],
            ],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->roots);
        self::assertSame('file:///a', $result->roots[0]->uri);
        self::assertSame('a', $result->roots[0]->name);
        self::assertSame('file:///b', $result->roots[1]->uri);
        self::assertNull($result->roots[1]->name);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListRootsResult(
            roots: [new Root(uri: 'file:///x', name: 'project', meta: new MetaObject(extras: ['k' => 'v']))],
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ListRootsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListRootsResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing roots' => [
            [],
            '"result" missing the required "roots" key.',
        ];

        yield 'roots not an array' => [
            ['roots' => 'oops'],
            '"result.roots" must be a list, string given.',
        ];

        yield 'root entry not an object' => [
            ['roots' => ['oops']],
            'each "result.root" must be an object, string given.',
        ];

        yield 'root entry list-keyed' => [
            ['roots' => [['x']]],
            'each "result.root" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['roots' => [], '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['roots' => [], '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
