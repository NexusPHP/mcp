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
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceContents::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceContentsTest extends TestCase
{
    public function testFromDispatchesToTextResourceContents(): void
    {
        $contents = ResourceContents::from(['uri' => 'file:///x', 'text' => 'hello']);

        self::assertInstanceOf(TextResourceContents::class, $contents);
        self::assertSame('hello', $contents->text);
    }

    public function testFromDispatchesToBlobResourceContents(): void
    {
        $contents = ResourceContents::from(['uri' => 'file:///x', 'blob' => 'aGVsbG8=']);

        self::assertInstanceOf(BlobResourceContents::class, $contents);
        self::assertSame('aGVsbG8=', $contents->blob);
    }

    public function testFromRejectsBothTextAndBlob(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceContents wire data must not have both "text" and "blob".');

        ResourceContents::from(['uri' => 'file:///x', 'text' => 'hello', 'blob' => 'aGVsbG8=']);
    }

    public function testFromRejectsMissingDiscriminator(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceContents wire data must have either "text" or "blob".');

        ResourceContents::from(['uri' => 'file:///x']);
    }
}
