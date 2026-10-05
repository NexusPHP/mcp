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

use Nexus\Assert\Assert;

/**
 * Pre-registered client credentials presented with `client_secret_basic` authentication.
 *
 * @see https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1-13#section-2.4.1
 */
final readonly class ClientSecretCredential
{
    /**
     * @param non-empty-string      $clientId
     * @param non-empty-string      $clientSecret
     * @param null|non-empty-string $issuer       The authorization server that the credential was registered with, or null to leave it unbound
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public ?string $issuer = null,
    ) {
        Assert::that($clientId)->isNonEmptyString('"clientId" must be a non-empty string.');
        Assert::that($clientSecret)->isNonEmptyString('"clientSecret" must be a non-empty string.');
        Assert::that($issuer)->nullOr()->isNonEmptyString('"issuer" must be a non-empty string or null.');
    }
}
