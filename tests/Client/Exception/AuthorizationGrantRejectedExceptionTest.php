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

use Nexus\Mcp\Client\Exception\AuthorizationGrantRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationGrantRejectedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationGrantRejectedExceptionTest extends TestCase
{
    public function testItReadsAsATokenRequestFailure(): void
    {
        self::assertSame(
            'The token request failed with "invalid_grant": The refresh token was revoked.',
            new AuthorizationGrantRejectedException('invalid_grant', 'The refresh token was revoked.')->getMessage(),
        );
    }

    public function testItExposesTheErrorCode(): void
    {
        self::assertSame('invalid_scope', new AuthorizationGrantRejectedException('invalid_scope')->error);
    }
}
