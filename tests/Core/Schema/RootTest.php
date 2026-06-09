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
        $root = new Root(uri: 'file:///x');

        self::assertSame('file:///x', $root->uri);
        self::assertNull($root->name);
        self::assertSame([], $root->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $root = new Root(uri: 'file:///x');

        self::assertSame(['uri' => 'file:///x'], $root->toArray());
    }

    public function testToArrayWithName(): void
    {
        $root = new Root(uri: 'file:///x', name: 'project');

        self::assertSame(
            ['uri' => 'file:///x', 'name' => 'project'],
            $root->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $root = new Root(uri: 'file:///x', name: null, meta: new MetaObject(extras: ['vendor' => 'x']));

        self::assertSame(
            ['uri' => 'file:///x', '_meta' => ['vendor' => 'x']],
            $root->toArray(),
        );
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $root = new Root(uri: 'file:///x', name: null, meta: new MetaObject(extras: []));

        self::assertSame(['uri' => 'file:///x'], $root->toArray());
    }

    public function testToArrayKeyOrder(): void
    {
        $root = new Root(uri: 'file:///x', name: 'project', meta: new MetaObject(extras: ['k' => 'v']));

        self::assertSame(
            ['uri', 'name', '_meta'],
            array_keys($root->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $root = new Root(uri: 'file:///x', name: 'project', meta: new MetaObject(extras: ['k' => 'v']));

        self::assertSame($root->toArray(), $root->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $root = Root::fromArray(['uri' => 'file:///x']);

        self::assertSame('file:///x', $root->uri);
        self::assertNull($root->name);
        self::assertSame([], $root->meta->toArray());
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
        self::assertSame(['vendor' => 'x'], $root->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Root(uri: 'file:///x', name: 'project', meta: new MetaObject(extras: ['vendor' => 'x']));

        $rebuilt = Root::fromArray($original->toArray());

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

        Root::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [
            [],
            'root missing the required "uri" key.',
        ];

        yield 'uri not a string' => [
            ['uri' => 1],
            'root "uri" must be a string, int given.',
        ];

        yield 'uri does not start with file://' => [
            ['uri' => 'https://example.com'],
            'root "uri" must start with \'file://\', got \'https://example.com\'.',
        ];

        yield 'name not a string' => [
            ['uri' => 'file:///x', 'name' => 1],
            'root "name" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['uri' => 'file:///x', '_meta' => 'oops'],
            'root "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['uri' => 'file:///x', '_meta' => ['x']],
            'root "_meta" must be a string-keyed object.',
        ];
    }

    public function testConstructorRejectsNonFileUri(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('root "uri" must start with \'file://\', got \'https://example.com\'.');

        new Root(uri: 'https://example.com');
    }
}
