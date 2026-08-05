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
 * Pre-registered client identity presented with `private_key_jwt` authentication: a signed JWT client
 * assertion in place of a shared secret.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7523#section-2.2
 */
final readonly class PrivateKeyJwtCredential
{
    /**
     * @param non-empty-string      $clientId
     * @param non-empty-string      $privateKeyPem The signing key in PEM form
     * @param non-empty-string      $algorithm     The JWS algorithm registered for the client, e.g. `ES256`
     * @param null|non-empty-string $keyId         The `kid` stamped on the assertion header, when the server keys by one
     */
    public function __construct(
        public string $clientId,
        public string $privateKeyPem,
        public string $algorithm,
        public ?string $keyId = null,
    ) {
        Assert::that($clientId)->isNonEmptyString('"clientId" must be a non-empty string.');
        Assert::that($privateKeyPem)->isNonEmptyString('"privateKeyPem" must be a non-empty string.');
        Assert::that($algorithm)->isNonEmptyString('"algorithm" must be a non-empty string.');
        Assert::that($keyId)->nullOr()->isNonEmptyString('"keyId" must be a non-empty string or null.');
    }
}
