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
 * The client authentication methods an MCP client offers at an authorization server's token endpoint.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414#section-2
 */
enum TokenEndpointAuthMethod: string
{
    /**
     * Client identifier and secret in an HTTP Basic `Authorization` header.
     */
    case ClientSecretBasic = 'client_secret_basic';

    /**
     * Client identifier and secret in the request body.
     */
    case ClientSecretPost = 'client_secret_post';

    /**
     * Public client, authenticating with its client identifier alone.
     */
    case None = 'none';
}
