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

use Firebase\JWT\JWT;
use Nexus\Clock\Clock;
use Nexus\Clock\SystemClock;
use Nexus\Mcp\Core\Validation\SuggestedDependencyGuard;

/**
 * Signs the RFC 7523 JWT client assertion of a `private_key_jwt` credential.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7523#section-3
 */
final readonly class ClientAssertionSigner
{
    private const int ASSERTION_LIFETIME_SECONDS = 300;

    public function __construct(private Clock $clock = new SystemClock())
    {
    }

    /**
     * @param non-empty-string $audience
     */
    public function sign(PrivateKeyJwtCredential $credential, string $audience): string
    {
        SuggestedDependencyGuard::verify(self::class, JWT::class, 'firebase/php-jwt', '^7.0');

        $issuedAt = $this->clock->now()->getTimestamp();

        return JWT::encode([
            'iss' => $credential->clientId,
            'sub' => $credential->clientId,
            'aud' => $audience,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::ASSERTION_LIFETIME_SECONDS,
        ], $credential->privateKeyPem, $credential->algorithm, $credential->keyId);
    }
}
