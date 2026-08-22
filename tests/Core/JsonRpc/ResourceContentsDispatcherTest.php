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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\ResourceContentsDispatcher;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceContentsDispatcher::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceContentsDispatcherTest extends AbstractMcpTestCase
{
    public function testFromArrayDispatchesToTextResourceContents(): void
    {
        $contents = ResourceContentsDispatcher::fromArray(
            ['uri' => 'file:///x', 'text' => 'hello'],
            'embedded resource resource',
        );

        self::assertInstanceOf(TextResourceContents::class, $contents);
        self::assertSame('hello', $contents->text);
    }

    public function testFromArrayDispatchesToBlobResourceContents(): void
    {
        $contents = ResourceContentsDispatcher::fromArray(
            ['uri' => 'file:///x', 'blob' => 'aGVsbG8='],
            'embedded resource resource',
        );

        self::assertInstanceOf(BlobResourceContents::class, $contents);
        self::assertSame('aGVsbG8=', $contents->blob);
    }

    public function testFromArrayRejectsBothTextAndBlob(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('embedded resource resource data must not have both "text" and "blob".');

        ResourceContentsDispatcher::fromArray(
            ['uri' => 'file:///x', 'text' => 'hello', 'blob' => 'aGVsbG8='],
            'embedded resource resource',
        );
    }

    public function testFromArrayRejectsMissingDiscriminator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('ReadResourceResult contents data must have either "text" or "blob".');

        ResourceContentsDispatcher::fromArray(['uri' => 'file:///x'], 'ReadResourceResult contents');
    }
}
