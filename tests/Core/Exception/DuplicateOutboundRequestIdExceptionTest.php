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

namespace Nexus\Mcp\Tests\Core\Exception;

use Nexus\Mcp\Core\Exception\DuplicateOutboundRequestIdException;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DuplicateOutboundRequestIdException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class DuplicateOutboundRequestIdExceptionTest extends TestCase
{
    public function testMessageEmitsTheExportedId(): void
    {
        self::assertSame(
            'Outbound request id 7 is already pending. The id-generation strategy must produce unique ids per in-flight request.',
            new DuplicateOutboundRequestIdException(new RequestId(7))->getMessage(),
        );
    }
}
