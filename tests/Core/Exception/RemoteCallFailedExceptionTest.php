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

use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RemoteCallFailedException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RemoteCallFailedExceptionTest extends TestCase
{
    public function testCopiesMessageAndCodeFromTheError(): void
    {
        $exception = new RemoteCallFailedException(new InternalError(message: 'peer blew up'));

        self::assertSame('peer blew up', $exception->getMessage());
        self::assertSame(ProtocolErrorCode::InternalError->value, $exception->getCode());
    }
}
