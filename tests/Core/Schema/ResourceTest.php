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
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Resource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Resource::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $resource = new Resource('my-resource', 'file:///x');

        self::assertSame('my-resource', $resource->name);
        self::assertSame('file:///x', $resource->uri);
        self::assertNull($resource->title);
        self::assertNull($resource->description);
        self::assertNull($resource->mimeType);
        self::assertNull($resource->annotations);
        self::assertNull($resource->size);
        self::assertNull($resource->icons);
        self::assertNull($resource->meta);
    }

    public function testToArrayMinimal(): void
    {
        $resource = new Resource('my-resource', 'file:///x');

        self::assertSame(
            ['name' => 'my-resource', 'uri' => 'file:///x'],
            $resource->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $resource = new Resource(
            'my-resource',
            'file:///x',
            'My Resource',
            'A description.',
            'text/plain',
            new Annotations(null, 0.5),
            1024.0,
            [new Icon('https://example.com/icon.png')],
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'name' => 'my-resource',
                'uri' => 'file:///x',
                'title' => 'My Resource',
                'description' => 'A description.',
                'mimeType' => 'text/plain',
                'annotations' => ['priority' => 0.5],
                'size' => 1024.0,
                'icons' => [['src' => 'https://example.com/icon.png']],
                '_meta' => ['vendor' => 'x'],
            ],
            $resource->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $resource = new Resource(
            'my-resource',
            'file:///x',
            'My Resource',
            null,
            'text/plain',
            null,
            42.0,
            null,
            new Meta(['k' => 'v']),
        );

        self::assertSame($resource->toArray(), $resource->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $resource = Resource::fromArray(['name' => 'my-resource', 'uri' => 'file:///x']);

        self::assertSame('my-resource', $resource->name);
        self::assertSame('file:///x', $resource->uri);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $resource = Resource::fromArray([
            'name' => 'my-resource',
            'uri' => 'file:///x',
            'title' => 'My Resource',
            'description' => 'A description.',
            'mimeType' => 'text/plain',
            'annotations' => ['priority' => 0.5],
            'size' => 1024.0,
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('My Resource', $resource->title);
        self::assertSame('A description.', $resource->description);
        self::assertSame('text/plain', $resource->mimeType);
        self::assertNotNull($resource->annotations);
        self::assertSame(0.5, $resource->annotations->priority);
        self::assertSame(1024.0, $resource->size);
        self::assertNotNull($resource->icons);
        self::assertCount(1, $resource->icons);
        self::assertSame('https://example.com/icon.png', $resource->icons[0]->src);
        self::assertNotNull($resource->meta);
        self::assertSame(['vendor' => 'x'], $resource->meta->extras);
    }

    public function testFromArrayCoercesIntSize(): void
    {
        $resource = Resource::fromArray([
            'name' => 'my-resource',
            'uri' => 'file:///x',
            'size' => 1024,
        ]);

        self::assertSame(1024.0, $resource->size);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Resource(
            'my-resource',
            'file:///x',
            'My Resource',
            'A description.',
            'text/plain',
            new Annotations(null, 0.5),
            42.0,
            [new Icon('https://example.com/icon.png')],
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = Resource::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResource name must be 1-128 characters/');

        new Resource('my resource', 'file:///x');
    }

    public function testConstructorRejectsUriViolatingRfc3986(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResource URI must be a valid RFC 3986/');

        new Resource('my-resource', 'not-a-uri');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Resource description must be a non-empty string or null.');

        new Resource('my-resource', 'file:///x', null, '');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Resource mimeType must be a non-empty string or null.');

        new Resource('my-resource', 'file:///x', null, null, '');
    }

    public function testConstructorRejectsNonIconElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new Resource('my-resource', 'file:///x', null, null, null, null, null, [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        Resource::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing name' => [
            ['uri' => 'file:///x'],
            'Resource wire data missing "name".',
        ];

        yield 'missing uri' => [
            ['name' => 'my-resource'],
            'Resource wire data missing "uri".',
        ];

        yield 'name not a string' => [
            ['name' => 1, 'uri' => 'file:///x'],
            'Resource wire "name" must be a string, int given.',
        ];

        yield 'uri not a string' => [
            ['name' => 'my-resource', 'uri' => 1],
            'Resource wire "uri" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'title' => 1],
            'Resource wire "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'description' => 1],
            'Resource wire "description" must be a string or null, int given.',
        ];

        yield 'mimeType not a string' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'mimeType' => 1],
            'Resource wire "mimeType" must be a string or null, int given.',
        ];

        yield 'annotations not an object' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'annotations' => 'oops'],
            'Resource wire "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'annotations' => ['x']],
            'Resource wire "annotations" must be a string-keyed object.',
        ];

        yield 'size not a number' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'size' => 'oops'],
            'Resource wire "size" must be a number or null, string given.',
        ];

        yield 'icons not an array' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'icons' => 'oops'],
            'Resource wire "icons" must be an array, string given.',
        ];

        yield 'icon entry not an object' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'icons' => ['oops']],
            'Resource wire icon entry must be an object, string given.',
        ];

        yield 'icon entry list-keyed' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'icons' => [['x']]],
            'Resource wire icon entry must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', '_meta' => 'oops'],
            'Resource "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', '_meta' => ['x']],
            'Resource "_meta" must be a string-keyed object.',
        ];
    }
}
