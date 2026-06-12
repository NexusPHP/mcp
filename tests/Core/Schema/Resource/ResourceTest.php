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
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
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
        $resource = new Resource(name: 'my-resource', uri: 'file:///x');

        self::assertSame('my-resource', $resource->name);
        self::assertSame('file:///x', $resource->uri);
        self::assertNull($resource->title);
        self::assertNull($resource->description);
        self::assertNull($resource->mimeType);
        self::assertSame([], $resource->annotations->toArray());
        self::assertNull($resource->size);
        self::assertNull($resource->icons);
        self::assertSame([], $resource->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $resource = new Resource(name: 'my-resource', uri: 'file:///x');

        self::assertSame(
            ['name' => 'my-resource', 'uri' => 'file:///x'],
            $resource->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $resource = new Resource(
            name: 'my-resource',
            uri: 'file:///x',
            title: 'My Resource',
            description: 'A description.',
            mimeType: 'text/plain',
            annotations: new Annotations(priority: 0.5),
            size: 1024.0,
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new MetaObject(extras: ['vendor' => 'x']),
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
            name: 'my-resource',
            uri: 'file:///x',
            title: 'My Resource',
            mimeType: 'text/plain',
            size: 42.0,
            meta: new MetaObject(extras: ['k' => 'v']),
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
        self::assertSame(0.5, $resource->annotations->priority);
        self::assertSame(1024.0, $resource->size);
        self::assertNotNull($resource->icons);
        self::assertCount(1, $resource->icons);
        self::assertSame('https://example.com/icon.png', $resource->icons[0]->src);
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
            name: 'my-resource',
            uri: 'file:///x',
            title: 'My Resource',
            description: 'A description.',
            mimeType: 'text/plain',
            annotations: new Annotations(priority: 0.5),
            size: 42.0,
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = Resource::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\Aresource "name" must be 1-128 characters/');

        new Resource(name: 'my resource', uri: 'file:///x');
    }

    public function testConstructorRejectsUriViolatingRfc3986(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\Aresource "uri" must be a valid RFC 3986/');

        new Resource(name: 'my-resource', uri: 'not-a-uri');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource "description" must be a non-empty string or null.');

        new Resource(name: 'my-resource', uri: 'file:///x', description: '');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource "mimeType" must be a non-empty string or null.');

        new Resource(name: 'my-resource', uri: 'file:///x', mimeType: '');
    }

    public function testConstructorRejectsNonIconElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new Resource(name: 'my-resource', uri: 'file:///x', icons: [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        Resource::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            ['uri' => 'file:///x'],
            'resource missing the required "name" key.',
        ];

        yield 'missing uri' => [
            ['name' => 'my-resource'],
            'resource missing the required "uri" key.',
        ];

        yield 'name not a string' => [
            ['name' => 1, 'uri' => 'file:///x'],
            'resource "name" must be a string, int given.',
        ];

        yield 'uri not a string' => [
            ['name' => 'my-resource', 'uri' => 1],
            'resource "uri" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'title' => 1],
            'resource "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'description' => 1],
            'resource "description" must be a string or null, int given.',
        ];

        yield 'mimeType not a string' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'mimeType' => 1],
            'resource "mimeType" must be a string or null, int given.',
        ];

        yield 'annotations not an object' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'annotations' => 'oops'],
            'resource "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'annotations' => ['x']],
            'resource "annotations" must be a string-keyed object.',
        ];

        yield 'size not a number' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'size' => 'oops'],
            'resource "size" must be a number or null, string given.',
        ];

        yield 'icons not an array' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'icons' => 'oops'],
            'resource "icons" must be a list, string given.',
        ];

        yield 'icon entry not an object' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'icons' => ['oops']],
            'each resource "icon" must be an object, string given.',
        ];

        yield 'icon entry list-keyed' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', 'icons' => [['x']]],
            'each resource "icon" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', '_meta' => 'oops'],
            'resource "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'my-resource', 'uri' => 'file:///x', '_meta' => ['x']],
            'resource "_meta" must be a string-keyed object.',
        ];
    }
}
