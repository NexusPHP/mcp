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

namespace Nexus\Mcp\Extension\Auth;

use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Extension\Auth\Exception\UnsupportedGrantException;

/**
 * Holds an authorization server to the grant types it published.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414#section-2
 */
final readonly class GrantTypeAdvertisement
{
    /**
     * Refuses a grant type absent from a published list, taking a server that publishes no list on trust
     * since RFC 8414 leaves the field optional.
     *
     * @param non-empty-string $grantType
     *
     * @throws UnsupportedGrantException
     */
    public static function verify(AuthorizationServerMetadata $server, string $grantType): void
    {
        $advertised = $server->grantTypesSupported;

        if (null !== $advertised && ! \in_array($grantType, $advertised, true)) {
            throw new UnsupportedGrantException(\sprintf(
                'The authorization server "%s" does not advertise the "%s" grant type.',
                SafeDisplay::sanitiseCause($server->issuer),
                $grantType,
            ));
        }
    }
}
