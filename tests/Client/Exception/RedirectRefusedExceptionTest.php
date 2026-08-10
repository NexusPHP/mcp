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

namespace Nexus\Mcp\Tests\Client\Exception;

use Nexus\Mcp\Client\Exception\RedirectRefusedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(RedirectRefusedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class RedirectRefusedExceptionTest extends AbstractMcpTestCase
{
    public function testItNamesBothEndsOfTheRedirect(): void
    {
        self::assertSame(
            'The request to "https://auth.example.com/token" was answered from "http://127.0.0.1:6379/token" after a redirect. Credentials are never carried across one.',
            (new RedirectRefusedException('https://auth.example.com/token', 'http://127.0.0.1:6379/token'))->getMessage(),
        );
    }

    public function testBoundsAndEscapesAHostileRedirectTarget(): void
    {
        self::assertSame(
            \sprintf(
                'The request to "https://mcp.example.com" was answered from "%s..." after a redirect. Credentials are never carried across one.',
                'https://'.str_repeat('e', 245),
            ),
            (new RedirectRefusedException('https://mcp.example.com', 'https://'.str_repeat('e', 300)))->getMessage(),
        );
    }
}
