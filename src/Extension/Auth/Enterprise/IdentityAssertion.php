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

namespace Nexus\Mcp\Extension\Auth\Enterprise;

use Nexus\Assert\Assert;

/**
 * The identity assertion the client's own sign-on produced, offered to the enterprise IdP as the subject
 * token of the RFC 8693 exchange.
 */
final readonly class IdentityAssertion
{
    /**
     * @param non-empty-string $token
     */
    public function __construct(
        public string $token,
        public IdentityAssertionType $type,
    ) {
        Assert::that($token)->isNonEmptyString('"token" must be a non-empty string.');
    }
}
