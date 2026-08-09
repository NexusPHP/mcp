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

use Amp\Cancellation;

/**
 * The consumer's seam to its own sign-on.
 */
interface IdentityAssertionProviderInterface
{
    /**
     * A currently valid identity assertion for the signed-in user, called once per grant.
     */
    public function provideAssertion(Cancellation $cancellation): IdentityAssertion;
}
