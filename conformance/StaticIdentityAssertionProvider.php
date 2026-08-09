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

use Amp\Cancellation;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertion;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionProviderInterface;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionType;

/**
 * Identity assertion provider fixed to the ID token the referee provisioned.
 */
final readonly class StaticIdentityAssertionProvider implements IdentityAssertionProviderInterface
{
    /**
     * @param non-empty-string $idToken
     */
    public function __construct(private string $idToken)
    {
    }

    #[Override]
    public function provideAssertion(Cancellation $cancellation): IdentityAssertion
    {
        return new IdentityAssertion($this->idToken, IdentityAssertionType::IdToken);
    }
}
