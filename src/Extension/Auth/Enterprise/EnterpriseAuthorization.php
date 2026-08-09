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
 * Protocol vocabulary of the enterprise-managed authorization extension
 * (`io.modelcontextprotocol/enterprise-managed-authorization`, SEP-990).
 *
 * @see https://github.com/modelcontextprotocol/ext-auth/blob/main/specification/stable/enterprise-managed-authorization.mdx
 */
final readonly class EnterpriseAuthorization
{
    public const string IDENTIFIER = 'io.modelcontextprotocol/enterprise-managed-authorization';
    public const string TOKEN_EXCHANGE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';
    public const string JWT_BEARER_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    public const string ID_JAG_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id-jag';
    public const string GRANT_PROFILE = 'urn:ietf:params:oauth:grant-profile:id-jag';
    public const string JWT_TYP = 'oauth-id-jag+jwt';
}
