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

namespace Nexus\Mcp\Tests\Fixtures\Core;

/**
 * Generator of a throwaway P-256 signing key.
 *
 * @internal
 */
final class EcPrivateKey
{
    /**
     * @return non-empty-string
     */
    public static function generatePem(): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);

        if (
            false === $key
            || ! openssl_pkey_export($key, $pem)
            || ! \is_string($pem)
            || '' === $pem
        ) {
            throw new \RuntimeException('The EC key could not be generated.');
        }

        return $pem;
    }
}
