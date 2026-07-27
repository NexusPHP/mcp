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

namespace Nexus\Mcp\Tests\Fixtures\Server\Auth;

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;

/**
 * Token validator double that recognises exactly the tokens it was given and records what it was asked about.
 *
 * @internal
 */
final class ScriptedAccessTokenValidator implements AccessTokenValidatorInterface
{
    /**
     * @var list<string>
     */
    public private(set) array $presented = [];

    /**
     * @param array<string, VerifiedAccessToken> $tokens Recognised tokens, keyed by their bearer value
     */
    public function __construct(private readonly array $tokens = [])
    {
    }

    #[\Override]
    public function validate(string $token): ?VerifiedAccessToken
    {
        $this->presented[] = $token;

        return $this->tokens[$token] ?? null;
    }
}
