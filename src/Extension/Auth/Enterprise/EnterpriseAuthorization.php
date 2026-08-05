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
    /**
     * The extension identifier used in capability negotiation.
     */
    public const string IDENTIFIER = 'io.modelcontextprotocol/enterprise-managed-authorization';

    /**
     * The RFC 8693 grant type of the token exchange at the enterprise IdP.
     */
    public const string TOKEN_EXCHANGE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    /**
     * The RFC 7523 grant type that redeems the ID-JAG at the resource authorization server.
     */
    public const string JWT_BEARER_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /**
     * The token type the enterprise IdP is asked for, and must answer with, in the token exchange.
     */
    public const string ID_JAG_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id-jag';

    /**
     * The authorization grant profile a resource authorization server advertises to declare ID-JAG support.
     */
    public const string GRANT_PROFILE = 'urn:ietf:params:oauth:grant-profile:id-jag';

    /**
     * The JOSE `typ` header of an Identity Assertion JWT Authorization Grant.
     */
    public const string JWT_TYP = 'oauth-id-jag+jwt';
}
