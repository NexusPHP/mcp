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

use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TransportAlreadyStartedException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TransportAlreadyStartedExceptionTest extends AbstractMcpTestCase
{
    public function testRendersTransportClassIntoMessage(): void
    {
        $e = new TransportAlreadyStartedException(\stdClass::class);

        self::assertSame('stdClass has already been started.', $e->getMessage());
    }
}
