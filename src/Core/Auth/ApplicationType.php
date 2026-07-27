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

namespace Nexus\Mcp\Core\Auth;

/**
 * The OpenID Connect application type a client declares during Dynamic Client Registration, which decides
 * the redirect URIs an OIDC-aware authorization server will accept.
 *
 * @see https://openid.net/specs/openid-connect-registration-1_0.html#ClientMetadata
 */
enum ApplicationType: string
{
    /**
     * Desktop applications, mobile apps, CLI tools, and locally-hosted applications reached over loopback.
     */
    case Native = 'native';

    /**
     * Remote browser-based applications served from a non-local host.
     */
    case Web = 'web';
}
