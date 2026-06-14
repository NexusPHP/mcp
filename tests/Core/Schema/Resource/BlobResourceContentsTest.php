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

namespace Nexus\Mcp\Tests\Core\Schema\Resource;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BlobResourceContents::class)]
#[CoversClass(ResourceContents::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class BlobResourceContentsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $contents = new BlobResourceContents(uri: 'file:///x', blob: 'aGVsbG8=');

        self::assertSame('file:///x', $contents->uri);
        self::assertSame('aGVsbG8=', $contents->blob);
        self::assertNull($contents->mimeType);
        self::assertSame([], $contents->meta->toArray());
    }

    public function testConstructionWithAllFields(): void
    {
        $contents = new BlobResourceContents(
            uri: 'file:///x',
            blob: 'aGVsbG8=',
            mimeType: 'application/octet-stream',
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame('application/octet-stream', $contents->mimeType);
        self::assertSame(['vendor' => 'x'], $contents->meta->extras);
    }

    public function testToArrayMinimal(): void
    {
        $contents = new BlobResourceContents(uri: 'file:///x', blob: 'aGVsbG8=');

        self::assertSame(
            ['uri' => 'file:///x', 'blob' => 'aGVsbG8='],
            $contents->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $contents = new BlobResourceContents(
            uri: 'file:///x',
            blob: 'aGVsbG8=',
            mimeType: 'application/octet-stream',
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                'uri' => 'file:///x',
                'blob' => 'aGVsbG8=',
                'mimeType' => 'application/octet-stream',
                '_meta' => ['vendor' => 'x'],
            ],
            $contents->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $contents = new BlobResourceContents(uri: 'file:///x', blob: 'aGVsbG8=', mimeType: 'application/octet-stream');

        self::assertSame($contents->toArray(), $contents->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $contents = BlobResourceContents::fromArray(['uri' => 'file:///x', 'blob' => 'aGVsbG8=']);

        self::assertSame('file:///x', $contents->uri);
        self::assertSame('aGVsbG8=', $contents->blob);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $contents = BlobResourceContents::fromArray([
            'uri' => 'file:///x',
            'blob' => 'aGVsbG8=',
            'mimeType' => 'application/octet-stream',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('application/octet-stream', $contents->mimeType);
        self::assertSame(['vendor' => 'x'], $contents->meta->extras);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new BlobResourceContents(
            uri: 'file:///x',
            blob: 'aGVsbG8=',
            mimeType: 'application/octet-stream',
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame($original->toArray(), BlobResourceContents::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        BlobResourceContents::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [
            ['blob' => 'aGVsbG8='],
            'blob resource contents is missing the required "uri" key.',
        ];

        yield 'uri not a string' => [
            ['uri' => 1, 'blob' => 'aGVsbG8='],
            'blob resource contents "uri" must be a string, int given.',
        ];

        yield 'missing blob' => [
            ['uri' => 'file:///x'],
            'blob resource contents is missing the required "blob" key.',
        ];

        yield 'blob not a string' => [
            ['uri' => 'file:///x', 'blob' => 1],
            'blob resource contents "blob" must be a string, int given.',
        ];

        yield 'mimeType not a string' => [
            ['uri' => 'file:///x', 'blob' => 'aGVsbG8=', 'mimeType' => 1],
            'blob resource contents "mimeType" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['uri' => 'file:///x', 'blob' => 'aGVsbG8=', '_meta' => 'oops'],
            'blob resource contents "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['uri' => 'file:///x', 'blob' => 'aGVsbG8=', '_meta' => ['x']],
            'blob resource contents "_meta" must be a string-keyed object.',
        ];
    }
}
