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

namespace Nexus\Mcp\Tests\Server\Attribute;

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AsResource::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AsResourceTest extends AbstractMcpTestCase
{
    public function testDefaultsToNullExceptUri(): void
    {
        $resource = new AsResource(uri: 'file:///data.txt');

        self::assertSame('file:///data.txt', $resource->uri);
        self::assertNull($resource->name);
        self::assertNull($resource->title);
        self::assertNull($resource->description);
        self::assertNull($resource->mimeType);
        self::assertNull($resource->annotations);
        self::assertNull($resource->size);
        self::assertNull($resource->icons);
        self::assertNull($resource->meta);
    }

    public function testStoresAllValues(): void
    {
        $annotations = new Annotations(priority: 0.5);
        $icon = new Icon(src: 'https://example.test/icon.svg');

        $resource = new AsResource(
            uri: 'file:///data.txt',
            name: 'data',
            title: 'Data file',
            description: 'A text file.',
            mimeType: 'text/plain',
            annotations: $annotations,
            size: 1024,
            icons: [$icon],
            meta: ['vendor' => 'acme'],
        );

        self::assertSame('file:///data.txt', $resource->uri);
        self::assertSame('data', $resource->name);
        self::assertSame('Data file', $resource->title);
        self::assertSame('A text file.', $resource->description);
        self::assertSame('text/plain', $resource->mimeType);
        self::assertSame($annotations, $resource->annotations);
        self::assertSame(1024, $resource->size);
        self::assertSame([$icon], $resource->icons);
        self::assertSame(['vendor' => 'acme'], $resource->meta);
    }
}
