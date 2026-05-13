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
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceTemplate::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceTemplateTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $template = new ResourceTemplate('my-template', 'file:///tmp/{name}');

        self::assertSame('my-template', $template->name);
        self::assertSame('file:///tmp/{name}', $template->uriTemplate);
        self::assertNull($template->title);
        self::assertNull($template->description);
        self::assertNull($template->mimeType);
        self::assertNull($template->annotations);
        self::assertNull($template->icons);
        self::assertSame([], $template->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $template = new ResourceTemplate('my-template', 'file:///tmp/{name}');

        self::assertSame(
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}'],
            $template->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $template = new ResourceTemplate(
            'my-template',
            'file:///tmp/{name}',
            'My Template',
            'A description.',
            'text/plain',
            new Annotations(null, 0.5),
            [new Icon('https://example.com/icon.png')],
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'name' => 'my-template',
                'uriTemplate' => 'file:///tmp/{name}',
                'title' => 'My Template',
                'description' => 'A description.',
                'mimeType' => 'text/plain',
                'annotations' => ['priority' => 0.5],
                'icons' => [['src' => 'https://example.com/icon.png']],
                '_meta' => ['vendor' => 'x'],
            ],
            $template->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $template = new ResourceTemplate(
            'my-template',
            'file:///tmp/{name}',
            'My Template',
            null,
            'text/plain',
            null,
            null,
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($template->toArray(), $template->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $template = ResourceTemplate::fromArray([
            'name' => 'my-template',
            'uriTemplate' => 'file:///tmp/{name}',
        ]);

        self::assertSame('my-template', $template->name);
        self::assertSame('file:///tmp/{name}', $template->uriTemplate);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $template = ResourceTemplate::fromArray([
            'name' => 'my-template',
            'uriTemplate' => 'file:///tmp/{name}',
            'title' => 'My Template',
            'description' => 'A description.',
            'mimeType' => 'text/plain',
            'annotations' => ['priority' => 0.5],
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('My Template', $template->title);
        self::assertSame('A description.', $template->description);
        self::assertSame('text/plain', $template->mimeType);
        self::assertNotNull($template->annotations);
        self::assertSame(0.5, $template->annotations->priority);
        self::assertNotNull($template->icons);
        self::assertCount(1, $template->icons);
        self::assertSame('https://example.com/icon.png', $template->icons[0]->src);
        self::assertSame(['vendor' => 'x'], $template->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ResourceTemplate(
            'my-template',
            'file:///tmp/{name}',
            'My Template',
            'A description.',
            'text/plain',
            new Annotations(null, 0.5),
            [new Icon('https://example.com/icon.png')],
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ResourceTemplate::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResourceTemplate name must be 1-128 characters/');

        new ResourceTemplate('my template', 'file:///tmp/{name}');
    }

    public function testConstructorRejectsEmptyUriTemplate(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceTemplate URI template must be a non-empty string.');

        new ResourceTemplate('my-template', '');
    }

    public function testConstructorRejectsInvalidUriTemplate(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResourceTemplate URI template must be a valid RFC 6570/');

        new ResourceTemplate('my-template', 'not-a-template');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceTemplate description must be a non-empty string or null.');

        new ResourceTemplate('my-template', 'file:///tmp/{name}', null, '');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceTemplate mimeType must be a non-empty string or null.');

        new ResourceTemplate('my-template', 'file:///tmp/{name}', null, null, '');
    }

    public function testConstructorRejectsNonIconElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ResourceTemplate('my-template', 'file:///tmp/{name}', null, null, null, null, [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ResourceTemplate::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            ['uriTemplate' => 'file:///tmp/{name}'],
            'ResourceTemplate data missing "name".',
        ];

        yield 'missing uriTemplate' => [
            ['name' => 'my-template'],
            'ResourceTemplate data missing "uriTemplate".',
        ];

        yield 'name not a string' => [
            ['name' => 1, 'uriTemplate' => 'file:///tmp/{name}'],
            'ResourceTemplate "name" must be a string, int given.',
        ];

        yield 'uriTemplate not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 1],
            'ResourceTemplate "uriTemplate" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'title' => 1],
            'ResourceTemplate "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'description' => 1],
            'ResourceTemplate "description" must be a string or null, int given.',
        ];

        yield 'mimeType not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'mimeType' => 1],
            'ResourceTemplate "mimeType" must be a string or null, int given.',
        ];

        yield 'annotations not an object' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'annotations' => 'oops'],
            'ResourceTemplate "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'annotations' => ['x']],
            'ResourceTemplate "annotations" must be a string-keyed object.',
        ];

        yield 'icons not an array' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'icons' => 'oops'],
            'ResourceTemplate "icons" must be a list, string given.',
        ];

        yield 'icon entry not an object' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'icons' => ['oops']],
            'ResourceTemplate icon entry must be an object, string given.',
        ];

        yield 'icon entry list-keyed' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'icons' => [['x']]],
            'ResourceTemplate icon entry must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', '_meta' => 'oops'],
            'ResourceTemplate "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', '_meta' => ['x']],
            'ResourceTemplate "_meta" must be a string-keyed object.',
        ];
    }
}
