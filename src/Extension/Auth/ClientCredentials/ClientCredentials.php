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

namespace Nexus\Mcp\Extension\Auth\ClientCredentials;

/**
 * Protocol vocabulary of the OAuth client credentials extension
 * (`io.modelcontextprotocol/oauth-client-credentials`, SEP-1046).
 *
 * @see https://github.com/modelcontextprotocol/ext-auth/blob/main/specification/draft/oauth-client-credentials.mdx
 */
final readonly class ClientCredentials
{
    public const string IDENTIFIER = 'io.modelcontextprotocol/oauth-client-credentials';
    public const string GRANT_TYPE = 'client_credentials';
    public const string CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
}
