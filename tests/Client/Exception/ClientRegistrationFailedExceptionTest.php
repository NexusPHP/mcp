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

use Nexus\Mcp\Client\Exception\ClientRegistrationFailedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientRegistrationFailedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientRegistrationFailedExceptionTest extends AbstractMcpTestCase
{
    public function testMessageEndsAtTheErrorCodeWhenThereIsNoDescription(): void
    {
        self::assertSame(
            'Dynamic Client Registration failed with "invalid_redirect_uri".',
            (new ClientRegistrationFailedException('invalid_redirect_uri'))->getMessage(),
        );
    }

    public function testMessageAppendsTheDescription(): void
    {
        self::assertSame(
            'Dynamic Client Registration failed with "invalid_redirect_uri": Loopback is not permitted.',
            (new ClientRegistrationFailedException('invalid_redirect_uri', 'Loopback is not permitted.'))->getMessage(),
        );
    }

    public function testItExposesTheErrorCode(): void
    {
        self::assertSame('invalid_redirect_uri', (new ClientRegistrationFailedException('invalid_redirect_uri'))->error);
    }
}
