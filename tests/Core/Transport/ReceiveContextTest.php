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

namespace Nexus\Mcp\Tests\Core\Transport;

use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReceiveContext::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ReceiveContextTest extends AbstractMcpTestCase
{
    public function testDefaultsToNoOriginatingRequest(): void
    {
        self::assertNull((new ReceiveContext())->request);
    }

    public function testCarriesTheOriginatingRequest(): void
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/');

        self::assertSame($request, (new ReceiveContext($request))->request);
    }
}
