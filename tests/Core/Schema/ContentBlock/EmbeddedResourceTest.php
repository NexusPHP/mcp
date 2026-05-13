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

namespace Nexus\Mcp\Tests\Core\Schema\ContentBlock;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EmbeddedResource::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EmbeddedResourceTest extends TestCase
{
    public function testConstructionMinimalText(): void
    {
        $embedded = new EmbeddedResource(new TextResourceContents('file:///x', 'hello'));

        self::assertSame('file:///x', $embedded->resource->uri);
        self::assertNull($embedded->annotations);
        self::assertSame([], $embedded->meta->toArray());
    }

    public function testConstructionMinimalBlob(): void
    {
        $embedded = new EmbeddedResource(new BlobResourceContents('file:///x', 'aGVsbG8='));

        self::assertSame('file:///x', $embedded->resource->uri);
    }

    public function testToArrayWithText(): void
    {
        $embedded = new EmbeddedResource(new TextResourceContents('file:///x', 'hello'));

        self::assertSame(
            [
                'resource' => ['uri' => 'file:///x', 'text' => 'hello'],
                'type' => 'resource',
            ],
            $embedded->toArray(),
        );
    }

    public function testToArrayWithBlob(): void
    {
        $embedded = new EmbeddedResource(new BlobResourceContents('file:///x', 'aGVsbG8='));

        self::assertSame(
            [
                'resource' => ['uri' => 'file:///x', 'blob' => 'aGVsbG8='],
                'type' => 'resource',
            ],
            $embedded->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $embedded = new EmbeddedResource(
            new TextResourceContents('file:///x', 'hello'),
            new Annotations(null, 0.5),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'resource' => ['uri' => 'file:///x', 'text' => 'hello'],
                'type' => 'resource',
                'annotations' => ['priority' => 0.5],
                '_meta' => ['vendor' => 'x'],
            ],
            $embedded->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $embedded = new EmbeddedResource(
            new TextResourceContents('file:///x', 'hello'),
            null,
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($embedded->toArray(), $embedded->jsonSerialize());
    }

    public function testFromArrayParsesText(): void
    {
        $embedded = EmbeddedResource::fromArray([
            'type' => 'resource',
            'resource' => ['uri' => 'file:///x', 'text' => 'hello'],
        ]);

        self::assertInstanceOf(TextResourceContents::class, $embedded->resource);
        self::assertSame('file:///x', $embedded->resource->uri);
        self::assertSame('hello', $embedded->resource->text);
    }

    public function testFromArrayParsesBlob(): void
    {
        $embedded = EmbeddedResource::fromArray([
            'type' => 'resource',
            'resource' => ['uri' => 'file:///x', 'blob' => 'aGVsbG8='],
        ]);

        self::assertInstanceOf(BlobResourceContents::class, $embedded->resource);
        self::assertSame('aGVsbG8=', $embedded->resource->blob);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $embedded = EmbeddedResource::fromArray([
            'type' => 'resource',
            'resource' => ['uri' => 'file:///x', 'text' => 'hello'],
            'annotations' => ['priority' => 0.5],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertNotNull($embedded->annotations);
        self::assertSame(0.5, $embedded->annotations->priority);
        self::assertSame(['vendor' => 'x'], $embedded->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new EmbeddedResource(
            new TextResourceContents('file:///x', 'hello'),
            new Annotations(null, 0.5),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = EmbeddedResource::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        EmbeddedResource::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['resource' => ['uri' => 'file:///x', 'text' => 'hello']],
            'EmbeddedResource data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'text', 'resource' => ['uri' => 'file:///x', 'text' => 'hello']],
            'EmbeddedResource "type" must be "resource", \'text\' given.',
        ];

        yield 'missing resource' => [
            ['type' => 'resource'],
            'EmbeddedResource data missing "resource".',
        ];

        yield 'resource not an object' => [
            ['type' => 'resource', 'resource' => 'oops'],
            'EmbeddedResource "resource" must be an object, string given.',
        ];

        yield 'resource list-keyed' => [
            ['type' => 'resource', 'resource' => ['x']],
            'EmbeddedResource "resource" must be a string-keyed object.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x', 'text' => 'hello'], 'annotations' => 'oops'],
            'EmbeddedResource "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x', 'text' => 'hello'], 'annotations' => ['x']],
            'EmbeddedResource "annotations" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x', 'text' => 'hello'], '_meta' => 'oops'],
            'EmbeddedResource "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x', 'text' => 'hello'], '_meta' => ['x']],
            'EmbeddedResource "_meta" must be a string-keyed object.',
        ];
    }
}
