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
use PHPUnit\Framework\Attributes\DataProvider;
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
            1_800_000_000,
            ['files:read'],
            'the-subject',
            'the-client',
        );

        self::assertSame(['https://mcp.test/mcp'], $token->audience);
        self::assertSame(1_800_000_000, $token->expiresAt);
        self::assertSame(['files:read'], $token->scopes);
        self::assertSame('the-subject', $token->subject);
        self::assertSame('the-client', $token->clientId);
    }

    public function testATokenNeedsOnlyItsAudienceAndExpiry(): void
    {
        $token = new VerifiedAccessToken(['https://mcp.test/mcp'], 1_800_000_000);

        self::assertSame(1_800_000_000, $token->expiresAt);
        self::assertSame([], $token->scopes);
        self::assertNull($token->subject);
        self::assertNull($token->clientId);
    }

    #[DataProvider('provideANonPositiveExpiryIsRefusedCases')]
    public function testANonPositiveExpiryIsRefused(int $expiresAt, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type
        new VerifiedAccessToken(['https://mcp.test/mcp'], $expiresAt);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideANonPositiveExpiryIsRefusedCases(): iterable
    {
        yield 'zero' => [0, 'Verified access token expiry must be a positive Unix timestamp, 0 given.'];

        yield 'negative' => [-1, 'Verified access token expiry must be a positive Unix timestamp, -1 given.'];
    }
}
