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

use Nexus\Mcp\Client\Exception\ClientRegistrationRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientRegistrationRejectedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientRegistrationRejectedExceptionTest extends TestCase
{
    public function testItSaysTheClientMustRegisterAgain(): void
    {
        self::assertSame(
            'The authorization server does not recognise the client identifier presented to it, so the client must register again.',
            new ClientRegistrationRejectedException()->getMessage(),
        );
    }

    public function testItCarriesTheServersDescription(): void
    {
        self::assertSame(
            'The authorization server does not recognise the client identifier presented to it, so the client must register again: The registration has lapsed.',
            new ClientRegistrationRejectedException('The registration has lapsed.')->getMessage(),
        );
    }
}
