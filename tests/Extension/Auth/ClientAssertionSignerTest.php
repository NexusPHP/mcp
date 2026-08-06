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

namespace Nexus\Mcp\Tests\Extension\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nexus\Mcp\Extension\Auth\ClientAssertionSigner;
use Nexus\Mcp\Extension\Auth\ClientCredentials\PrivateKeyJwtCredential;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientAssertionSigner::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ClientAssertionSignerTest extends AbstractMcpTestCase
{
    public function testSignsAnAssertionNamingTheClientAsIssuerAndSubject(): void
    {
        [$privatePem, $publicPem] = self::generateKeyPair();
        $signer = new ClientAssertionSigner(new PrivateKeyJwtCredential('the-client', $privatePem, 'ES256', 'key-1'));

        $before = time();
        $assertion = $signer->signAssertion('https://auth.example.com');
        $after = time();

        $claims = (array) JWT::decode($assertion, new Key($publicPem, 'ES256'));
        self::assertSame('the-client', $claims['iss'] ?? null);
        self::assertSame('the-client', $claims['sub'] ?? null);
        self::assertSame('https://auth.example.com', $claims['aud'] ?? null);

        $issuedAt = $claims['iat'] ?? null;
        self::assertIsInt($issuedAt);
        self::assertGreaterThanOrEqual($before, $issuedAt);
        self::assertLessThanOrEqual($after, $issuedAt);
        self::assertSame($issuedAt + 300, $claims['exp'] ?? null);

        $identifier = $claims['jti'] ?? null;
        self::assertIsString($identifier);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $identifier);

        $header = self::readHeader($assertion);
        self::assertSame('ES256', $header['alg'] ?? null);
        self::assertSame('key-1', $header['kid'] ?? null);
    }

    public function testEveryAssertionCarriesAFreshIdentifier(): void
    {
        [$privatePem] = self::generateKeyPair();
        $signer = new ClientAssertionSigner(new PrivateKeyJwtCredential('the-client', $privatePem, 'ES256'));

        self::assertNotSame(
            self::readClaims($signer->signAssertion('https://auth.example.com'))['jti'] ?? null,
            self::readClaims($signer->signAssertion('https://auth.example.com'))['jti'] ?? null,
        );
    }

    public function testTheKeyIdHeaderIsLeftOffWhenTheCredentialCarriesNone(): void
    {
        [$privatePem] = self::generateKeyPair();
        $signer = new ClientAssertionSigner(new PrivateKeyJwtCredential('the-client', $privatePem, 'ES256'));

        self::assertArrayNotHasKey('kid', self::readHeader($signer->signAssertion('https://auth.example.com')));
    }

    /**
     * @return array{non-empty-string, non-empty-string}
     */
    private static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);

        if (false === $key || ! openssl_pkey_export($key, $privatePem) || ! \is_string($privatePem) || '' === $privatePem) {
            self::fail('The EC key pair could not be generated.');
        }

        $details = openssl_pkey_get_details($key);

        if (false === $details || ! isset($details['key']) || ! \is_string($details['key']) || '' === $details['key']) {
            self::fail('The EC public key could not be exported.');
        }

        return [$privatePem, $details['key']];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readHeader(string $assertion): array
    {
        [$header] = explode('.', $assertion, 2);
        $decoded = json_decode(JWT::urlsafeB64Decode($header), associative: true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            self::fail('The assertion header is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readClaims(string $assertion): array
    {
        $parts = explode('.', $assertion, 3);

        if (! isset($parts[1])) {
            self::fail('The assertion is not a JWS.');
        }

        $decoded = json_decode(JWT::urlsafeB64Decode($parts[1]), associative: true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            self::fail('The assertion payload is not a JSON object.');
        }

        return $decoded;
    }
}
