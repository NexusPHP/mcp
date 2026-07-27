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

use Nexus\Mcp\Client\Exception\InsufficientScopeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InsufficientScopeException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class InsufficientScopeExceptionTest extends TestCase
{
    public function testMessageNamesTheScopesTheChallengeAskedFor(): void
    {
        self::assertSame(
            'The MCP server requires the scope "files:write files:admin", which the token does not carry.',
            new InsufficientScopeException(['files:write', 'files:admin'])->getMessage(),
        );
    }

    public function testMessageSaysSoWhenTheChallengeNamedNoScope(): void
    {
        self::assertSame(
            'The MCP server requires a scope the token does not carry, and named none.',
            new InsufficientScopeException([])->getMessage(),
        );
    }

    public function testItExposesTheRequiredScopes(): void
    {
        self::assertSame(['files:write'], new InsufficientScopeException(['files:write'])->required);
    }
}
