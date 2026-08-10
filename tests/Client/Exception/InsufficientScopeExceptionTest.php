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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InsufficientScopeException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class InsufficientScopeExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheScopesTheChallengeAskedFor(): void
    {
        self::assertSame(
            'The MCP server requires the scope "files:write files:admin".',
            (new InsufficientScopeException(['files:write', 'files:admin']))->getMessage(),
        );
    }

    public function testMessageSaysSoWhenTheChallengeNamedNoScope(): void
    {
        self::assertSame(
            'The MCP server answered insufficient_scope without naming a scope.',
            (new InsufficientScopeException([]))->getMessage(),
        );
    }

    public function testItExposesTheRequiredScopes(): void
    {
        self::assertSame(['files:write'], (new InsufficientScopeException(['files:write']))->required);
    }

    public function testBoundsAndEscapesAHostileScopeList(): void
    {
        self::assertSame(
            \sprintf('The MCP server requires the scope "%s...".', str_repeat('s', 253)),
            (new InsufficientScopeException([str_repeat('s', 300)."\x1b"]))->getMessage(),
        );
    }

    public function testTheRequiredScopesArePreservedUnsanitised(): void
    {
        $scope = str_repeat('s', 300)."\x1b";

        self::assertSame([$scope], (new InsufficientScopeException([$scope]))->required);
    }

    public function testMessageDistinguishesAChallengeWhoseScopesWereAllDropped(): void
    {
        self::assertSame(
            'The MCP server named only scopes that are not RFC 6749 scope-tokens, so none can be requested.',
            (new InsufficientScopeException([], named: true))->getMessage(),
        );
    }
}
