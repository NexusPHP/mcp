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
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceTemplate::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceTemplateTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $template = new ResourceTemplate(name: 'my-template', uriTemplate: 'file:///tmp/{name}');

        self::assertSame('my-template', $template->name);
        self::assertSame('file:///tmp/{name}', $template->uriTemplate);
        self::assertNull($template->title);
        self::assertNull($template->description);
        self::assertNull($template->mimeType);
        self::assertSame([], $template->annotations->toArray());
        self::assertNull($template->icons);
        self::assertSame([], $template->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $template = new ResourceTemplate(name: 'my-template', uriTemplate: 'file:///tmp/{name}');

        self::assertSame(
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}'],
            $template->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $template = new ResourceTemplate(
            name: 'my-template',
            uriTemplate: 'file:///tmp/{name}',
            title: 'My Template',
            description: 'A description.',
            mimeType: 'text/plain',
            annotations: new Annotations(priority: 0.5),
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new PayloadMetaObject(extras: ['vendor' => 'x']),
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
            name: 'my-template',
            uriTemplate: 'file:///tmp/{name}',
            title: 'My Template',
            mimeType: 'text/plain',
            meta: new PayloadMetaObject(extras: ['k' => 'v']),
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
        self::assertSame(0.5, $template->annotations->priority);
        self::assertNotNull($template->icons);
        self::assertCount(1, $template->icons);
        self::assertSame('https://example.com/icon.png', $template->icons[0]->src);
        self::assertSame(['vendor' => 'x'], $template->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ResourceTemplate(
            name: 'my-template',
            uriTemplate: 'file:///tmp/{name}',
            title: 'My Template',
            description: 'A description.',
            mimeType: 'text/plain',
            annotations: new Annotations(priority: 0.5),
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new PayloadMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ResourceTemplate::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorAcceptsANameOutsideTheSdksPreferredCharset(): void
    {
        $template = new ResourceTemplate(name: 'Project Files', uriTemplate: 'file:///tmp/{name}');

        self::assertSame('Project Files', $template->name);
    }

    public function testConstructorRejectsEmptyUriTemplate(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource template "uriTemplate" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ResourceTemplate(name: 'my-template', uriTemplate: '');
    }

    public function testConstructorRejectsInvalidUriTemplate(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\Aresource template "uriTemplate" must be a valid RFC 6570/');

        new ResourceTemplate(name: 'my-template', uriTemplate: 'not-a-template');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource template "description" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ResourceTemplate(name: 'my-template', uriTemplate: 'file:///tmp/{name}', description: '');
    }

    public function testConstructorRejectsEmptyMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource template "mimeType" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ResourceTemplate(name: 'my-template', uriTemplate: 'file:///tmp/{name}', mimeType: '');
    }

    public function testConstructorRejectsNonIconElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ResourceTemplate(name: 'my-template', uriTemplate: 'file:///tmp/{name}', icons: [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ResourceTemplate::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            ['uriTemplate' => 'file:///tmp/{name}'],
            'resource template is missing the required "name" key.',
        ];

        yield 'missing uriTemplate' => [
            ['name' => 'my-template'],
            'resource template is missing the required "uriTemplate" key.',
        ];

        yield 'name not a string' => [
            ['name' => 1, 'uriTemplate' => 'file:///tmp/{name}'],
            'resource template "name" must be a non-empty string, int given.',
        ];

        yield 'uriTemplate not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 1],
            'resource template "uriTemplate" must be a non-empty string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'title' => 1],
            'resource template "title" must be a non-empty string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'description' => 1],
            'resource template "description" must be a non-empty string or null, int given.',
        ];

        yield 'mimeType not a string' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'mimeType' => 1],
            'resource template "mimeType" must be a non-empty string or null, int given.',
        ];

        yield 'annotations not an object' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'annotations' => 'oops'],
            'resource template "annotations" must be an object, string given.',
        ];

        yield 'annotations list-keyed' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'annotations' => ['x']],
            'resource template "annotations" must be a string-keyed object.',
        ];

        yield 'icons not an array' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'icons' => 'oops'],
            'resource template "icons" must be a list, string given.',
        ];

        yield 'icon entry not an object' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'icons' => ['oops']],
            'each resource template "icons" must be an object, string given.',
        ];

        yield 'icon entry list-keyed' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', 'icons' => [['x']]],
            'each resource template "icons" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', '_meta' => 'oops'],
            'resource template "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'my-template', 'uriTemplate' => 'file:///tmp/{name}', '_meta' => ['x']],
            'resource template "_meta" must be a string-keyed object.',
        ];
    }
}
