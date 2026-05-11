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
use Nexus\Mcp\Core\Schema\Meta;
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
        $result = new ListRootsResult([new Root('file:///x')]);

        self::assertCount(1, $result->roots);
        self::assertNull($result->meta);
    }

    public function testConstructionAcceptsEmptyRootsList(): void
    {
        $result = new ListRootsResult([]);

        self::assertSame([], $result->roots);
    }

    public function testConstructorRejectsNonListRoots(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ListRootsResult roots must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new ListRootsResult([5 => new Root('file:///x')]);
    }

    public function testConstructorRejectsNonRootElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListRootsResult([42]);
    }

    public function testToArrayEmitsRoots(): void
    {
        $result = new ListRootsResult([
            new Root('file:///a', 'project-a'),
            new Root('file:///b'),
        ]);

        self::assertSame(
            [
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
            [new Root('file:///x')],
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'roots' => [['uri' => 'file:///x']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListRootsResult(
            [new Root('file:///x', 'project')],
            new Meta(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyRoots(): void
    {
        $result = ListRootsResult::fromArray(['roots' => []]);

        self::assertSame([], $result->roots);
        self::assertNull($result->meta);
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
        self::assertNotNull($result->meta);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListRootsResult(
            [new Root('file:///x', 'project', new Meta(['k' => 'v']))],
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = ListRootsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListRootsResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing roots' => [
            [],
            'ListRootsResult wire data missing "roots".',
        ];

        yield 'roots not an array' => [
            ['roots' => 'oops'],
            'ListRootsResult wire "roots" must be a list, string given.',
        ];

        yield 'root entry not an object' => [
            ['roots' => ['oops']],
            'ListRootsResult wire root entry must be an object, string given.',
        ];

        yield 'root entry list-keyed' => [
            ['roots' => [['x']]],
            'ListRootsResult wire root entry must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['roots' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['roots' => [], '_meta' => ['x']],
            'Result "_meta" must be a string-keyed object.',
        ];
    }
}
