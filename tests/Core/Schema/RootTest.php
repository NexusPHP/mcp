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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Root;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Root::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RootTest extends TestCase
{
    public function testConstructionDefaultsNameAndMetaToNull(): void
    {
        $root = new Root('file:///x');

        self::assertSame('file:///x', $root->uri);
        self::assertNull($root->name);
        self::assertNull($root->meta);
    }

    public function testToArrayMinimal(): void
    {
        $root = new Root('file:///x');

        self::assertSame(['uri' => 'file:///x'], $root->toArray());
    }

    public function testToArrayWithName(): void
    {
        $root = new Root('file:///x', 'project');

        self::assertSame(
            ['uri' => 'file:///x', 'name' => 'project'],
            $root->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $root = new Root('file:///x', null, new MetaObject(['vendor' => 'x']));

        self::assertSame(
            ['uri' => 'file:///x', '_meta' => ['vendor' => 'x']],
            $root->toArray(),
        );
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $root = new Root('file:///x', null, new MetaObject([]));

        self::assertSame(['uri' => 'file:///x'], $root->toArray());
    }

    public function testToArrayKeyOrder(): void
    {
        $root = new Root('file:///x', 'project', new MetaObject(['k' => 'v']));

        self::assertSame(
            ['uri', 'name', '_meta'],
            array_keys($root->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $root = new Root('file:///x', 'project', new MetaObject(['k' => 'v']));

        self::assertSame($root->toArray(), $root->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $root = Root::fromArray(['uri' => 'file:///x']);

        self::assertSame('file:///x', $root->uri);
        self::assertNull($root->name);
        self::assertNull($root->meta);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $root = Root::fromArray([
            'uri' => 'file:///x',
            'name' => 'project',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('file:///x', $root->uri);
        self::assertSame('project', $root->name);
        self::assertNotNull($root->meta);
        self::assertSame(['vendor' => 'x'], $root->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Root('file:///x', 'project', new MetaObject(['vendor' => 'x']));

        $rebuilt = Root::fromArray($original->toArray());

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

        Root::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing uri' => [
            [],
            'Root wire data missing "uri".',
        ];

        yield 'uri not a string' => [
            ['uri' => 1],
            'Root wire "uri" must be a string, int given.',
        ];

        yield 'uri does not start with file://' => [
            ['uri' => 'https://example.com'],
            'Root URI must start with \'file://\', got \'https://example.com\'.',
        ];

        yield 'name not a string' => [
            ['uri' => 'file:///x', 'name' => 1],
            'Root wire "name" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['uri' => 'file:///x', '_meta' => 'oops'],
            'Root "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['uri' => 'file:///x', '_meta' => ['x']],
            'Root "_meta" must be a string-keyed object.',
        ];
    }

    public function testConstructorRejectsNonFileUri(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Root URI must start with \'file://\', got \'https://example.com\'.');

        new Root('https://example.com');
    }
}
