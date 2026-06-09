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
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\MetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceLink::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceLinkTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $link = new ResourceLink(name: 'my-link', uri: 'file:///tmp/x');

        self::assertSame('my-link', $link->name);
        self::assertSame('file:///tmp/x', $link->uri);
        self::assertNull($link->title);
        self::assertNull($link->description);
        self::assertNull($link->mimeType);
        self::assertSame([], $link->annotations->toArray());
        self::assertNull($link->size);
        self::assertNull($link->icons);
        self::assertSame([], $link->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $link = new ResourceLink(name: 'my-link', uri: 'file:///tmp/x');

        self::assertSame(
            ['name' => 'my-link', 'type' => 'resource_link', 'uri' => 'file:///tmp/x'],
            $link->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $link = new ResourceLink(
            name: 'my-link',
            uri: 'file:///tmp/x',
            title: 'My Link',
            description: 'A description.',
            mimeType: 'text/plain',
            annotations: new Annotations(audience: null, priority: 0.5),
            size: 1024.0,
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                'name' => 'my-link',
                'type' => 'resource_link',
                'uri' => 'file:///tmp/x',
                'title' => 'My Link',
                'description' => 'A description.',
                'mimeType' => 'text/plain',
                'annotations' => ['priority' => 0.5],
                'size' => 1024.0,
                'icons' => [['src' => 'https://example.com/icon.png']],
                '_meta' => ['vendor' => 'x'],
            ],
            $link->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $link = new ResourceLink(
            name: 'my-link',
            uri: 'file:///tmp/x',
            title: 'My Link',
            description: null,
            mimeType: 'text/plain',
            annotations: new Annotations(),
            size: 42.0,
            icons: null,
            meta: new MetaObject(extras: ['k' => 'v']),
        );

        self::assertSame($link->toArray(), $link->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $link = ResourceLink::fromArray([
            'type' => 'resource_link',
            'name' => 'my-link',
            'uri' => 'file:///tmp/x',
        ]);

        self::assertSame('my-link', $link->name);
        self::assertSame('file:///tmp/x', $link->uri);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $link = ResourceLink::fromArray([
            'type' => 'resource_link',
            'name' => 'my-link',
            'uri' => 'file:///tmp/x',
            'title' => 'My Link',
            'description' => 'A description.',
            'mimeType' => 'text/plain',
            'annotations' => ['priority' => 0.5],
            'size' => 1024.0,
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('My Link', $link->title);
        self::assertSame('A description.', $link->description);
        self::assertSame('text/plain', $link->mimeType);
        self::assertSame(0.5, $link->annotations->priority);
        self::assertSame(1024.0, $link->size);
        self::assertNotNull($link->icons);
        self::assertCount(1, $link->icons);
        self::assertSame('https://example.com/icon.png', $link->icons[0]->src);
        self::assertSame(['vendor' => 'x'], $link->meta->extras);
    }

    public function testFromArrayCoercesIntSize(): void
    {
        $link = ResourceLink::fromArray([
            'type' => 'resource_link',
            'name' => 'my-link',
            'uri' => 'file:///tmp/x',
            'size' => 1024,
        ]);

        self::assertSame(1024.0, $link->size);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ResourceLink(
            name: 'my-link',
            uri: 'file:///tmp/x',
            title: 'My Link',
            description: 'A description.',
            mimeType: 'text/plain',
            annotations: new Annotations(audience: null, priority: 0.5),
            size: 42.0,
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ResourceLink::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\Aresource link "name" must be 1-128 characters/');

        new ResourceLink(name: 'my link', uri: 'file:///tmp/x');
    }

    public function testConstructorRejectsUriViolatingRfc3986(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\Aresource link "uri" must be a valid RFC 3986/');

        new ResourceLink(name: 'my-link', uri: 'not-a-uri');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource link "description" must be a non-empty string or null.');

        new ResourceLink(name: 'my-link', uri: 'file:///tmp/x', title: null, description: '');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource link "mimeType" must be a non-empty string or null.');

        new ResourceLink(name: 'my-link', uri: 'file:///tmp/x', title: null, description: null, mimeType: '');
    }

    public function testConstructorRejectsNonIconElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ResourceLink(name: 'my-link', uri: 'file:///tmp/x', title: null, description: null, mimeType: null, annotations: new Annotations(), size: null, icons: [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ResourceLink::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['name' => 'my-link', 'uri' => 'file:///tmp/x'],
            'resource link missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'resource', 'name' => 'my-link', 'uri' => 'file:///tmp/x'],
            'resource link "type" must be \'resource_link\', \'resource\' given.',
        ];

        yield 'missing name' => [
            ['type' => 'resource_link', 'uri' => 'file:///tmp/x'],
            'resource link missing the required "name" key.',
        ];

        yield 'name not a string' => [
            ['type' => 'resource_link', 'name' => 1, 'uri' => 'file:///tmp/x'],
            'resource link "name" must be a string, int given.',
        ];

        yield 'missing uri' => [
            ['type' => 'resource_link', 'name' => 'my-link'],
            'resource link missing the required "uri" key.',
        ];

        yield 'uri not a string' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 1],
            'resource link "uri" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'title' => 1],
            'resource link "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'description' => 1],
            'resource link "description" must be a string or null, int given.',
        ];

        yield 'mimeType not a string' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'mimeType' => 1],
            'resource link "mimeType" must be a string or null, int given.',
        ];

        yield 'annotations not an object' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'annotations' => 'oops'],
            'resource link "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'annotations' => ['x']],
            'resource link "annotations" must be a string-keyed object.',
        ];

        yield 'size not a number' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'size' => 'oops'],
            'resource link "size" must be a number or null, string given.',
        ];

        yield 'icons not an array' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'icons' => 'oops'],
            'resource link "icons" must be a list, string given.',
        ];

        yield 'icon entry not an object' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'icons' => ['oops']],
            'each resource link "icon" must be an object, string given.',
        ];

        yield 'icon entry list-keyed' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', 'icons' => [['x']]],
            'each resource link "icon" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', '_meta' => 'oops'],
            'resource link "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['type' => 'resource_link', 'name' => 'my-link', 'uri' => 'file:///tmp/x', '_meta' => ['x']],
            'resource link "_meta" must be a string-keyed object.',
        ];
    }
}
