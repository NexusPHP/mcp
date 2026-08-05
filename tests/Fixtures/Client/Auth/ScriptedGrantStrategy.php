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

namespace Nexus\Mcp\Tests\Fixtures\Client\Auth;

use Amp\Cancellation;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\GrantStrategyInterface;

/**
 * Grant-strategy double that records each context it was handed and answers from a queue of canned tokens.
 *
 * @internal
 */
final class ScriptedGrantStrategy implements GrantStrategyInterface
{
    /**
     * @var list<GrantContext>
     */
    public private(set) array $contexts = [];

    /**
     * @var list<AccessToken>
     */
    private array $tokens;

    public function __construct(private readonly bool $renewsByFreshGrant, AccessToken ...$tokens)
    {
        $this->tokens = array_values($tokens);
    }

    #[\Override]
    public function grant(GrantContext $context, Cancellation $cancellation): AccessToken
    {
        $this->contexts[] = $context;

        return array_shift($this->tokens) ?? throw new \OutOfBoundsException('No token was scripted for this grant.');
    }

    #[\Override]
    public function renewsByFreshGrant(): bool
    {
        return $this->renewsByFreshGrant;
    }

    /**
     * The recorded context at `$index`.
     */
    public function readContext(int $index = 0): GrantContext
    {
        return $this->contexts[$index] ?? throw new \OutOfBoundsException(\sprintf('No context was recorded at index %d.', $index));
    }
}
