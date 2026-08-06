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

namespace Nexus\Mcp\Tests\Core\Auth;

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(VerifiedAccessToken::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class VerifiedAccessTokenTest extends AbstractMcpTestCase
{
    public function testItCarriesEveryVerifiedField(): void
    {
        $token = new VerifiedAccessToken(
            ['https://mcp.test/mcp'],
            ['files:read'],
            'the-subject',
            'the-client',
            1_800_000_000,
        );

        self::assertSame(['https://mcp.test/mcp'], $token->audience);
        self::assertSame(['files:read'], $token->scopes);
        self::assertSame('the-subject', $token->subject);
        self::assertSame('the-client', $token->clientId);
        self::assertSame(1_800_000_000, $token->expiresAt);
    }

    public function testATokenNeedsOnlyItsAudience(): void
    {
        $token = new VerifiedAccessToken(['https://mcp.test/mcp']);

        self::assertSame([], $token->scopes);
        self::assertNull($token->subject);
        self::assertNull($token->clientId);
        self::assertNull($token->expiresAt);
    }
}
