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

/**
 * The RFC 8693 subject token types an identity assertion is exchanged as.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8693#section-3
 */
enum IdentityAssertionType: string
{
    /**
     * An OpenID Connect ID token from the client's own sign-on.
     */
    case IdToken = 'urn:ietf:params:oauth:token-type:id_token';

    /**
     * A refresh token, which a SAML assertion is first exchanged for.
     */
    case RefreshToken = 'urn:ietf:params:oauth:token-type:refresh_token';
}
