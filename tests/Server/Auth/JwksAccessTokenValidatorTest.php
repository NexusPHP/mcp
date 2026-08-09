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

namespace Nexus\Mcp\Tests\Server\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nexus\Mcp\Server\Auth\JwksAccessTokenValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(JwksAccessTokenValidator::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class JwksAccessTokenValidatorTest extends AbstractMcpTestCase
{
    private const string SECRET = 'a-shared-test-secret-that-is-32b-plus';
    private const string KID = 'test-key';
    private const string ISSUER = 'https://idp.example.test';

    public function testAValidTokenMapsTheStandardClaims(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry([
            'aud' => ['https://mcp.example.com/mcp', 'https://spare.example.com'],
            'scope' => 'mcp:use files:read',
            'sub' => 'user-7',
            'azp' => 'client-1',
            'exp' => time() + 60,
        ]));

        self::assertNotNull($verified);
        self::assertSame(['https://mcp.example.com/mcp', 'https://spare.example.com'], $verified->audience);
        self::assertSame(['mcp:use', 'files:read'], $verified->scopes);
        self::assertSame('user-7', $verified->subject);
        self::assertSame('client-1', $verified->clientId);
        self::assertGreaterThan(time(), $verified->expiresAt);
    }

    public function testAStringAudienceBecomesASingleEntryList(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['aud' => 'https://mcp.example.com/mcp']));

        self::assertNotNull($verified);
        self::assertSame(['https://mcp.example.com/mcp'], $verified->audience);
    }

    public function testNonStringAudienceEntriesAreDropped(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['aud' => ['https://mcp.example.com/mcp', 42]]));

        self::assertNotNull($verified);
        self::assertSame(['https://mcp.example.com/mcp'], $verified->audience);
    }

    public function testAMissingAudienceIsEmpty(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['sub' => 'user-7']));

        self::assertNotNull($verified);
        self::assertSame([], $verified->audience);
        self::assertNull($verified->clientId);
    }

    public function testScpAsAListIsReadAsScopes(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['scp' => ['mcp:use', '', 7, 'files:read']]));

        self::assertNotNull($verified);
        self::assertSame(['mcp:use', 'files:read'], $verified->scopes);
    }

    public function testScpAsAStringIsSplitLikeScope(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['scp' => 'mcp:use files:read']));

        self::assertNotNull($verified);
        self::assertSame(['mcp:use', 'files:read'], $verified->scopes);
    }

    public function testScopeOutranksScp(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['scope' => 'mcp:use', 'scp' => ['other']]));

        self::assertNotNull($verified);
        self::assertSame(['mcp:use'], $verified->scopes);
    }

    public function testNoScopeClaimMeansNoScopes(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['scope' => 42]));

        self::assertNotNull($verified);
        self::assertSame([], $verified->scopes);
    }

    public function testClientIdFallsBackThroughAzpClientIdAndCid(): void
    {
        $validator = self::validator();

        $azp = $validator->validate(self::encodeWithIssuerAndExpiry(['azp' => 'from-azp', 'client_id' => 'from-client-id', 'cid' => 'from-cid']));
        $clientId = $validator->validate(self::encodeWithIssuerAndExpiry(['client_id' => 'from-client-id', 'cid' => 'from-cid']));
        $cid = $validator->validate(self::encodeWithIssuerAndExpiry(['cid' => 'from-cid']));
        $nonString = $validator->validate(self::encodeWithIssuerAndExpiry(['azp' => 99]));

        self::assertSame('from-azp', $azp?->clientId);
        self::assertSame('from-client-id', $clientId?->clientId);
        self::assertSame('from-cid', $cid?->clientId);
        self::assertNull($nonString?->clientId);
    }

    public function testANonStringSubjectIsDropped(): void
    {
        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['sub' => 12_345]));

        self::assertNotNull($verified);
        self::assertNull($verified->subject);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        self::assertNull(self::validator()->validate(self::encodeWithIssuerAndExpiry(['exp' => time() - 60])));
    }

    public function testAForeignSignatureIsRefused(): void
    {
        $token = JWT::encode(['sub' => 'user-7'], 'a-different-secret-also-32-bytes-long', 'HS256', self::KID);

        self::assertNull(self::validator()->validate($token));
    }

    public function testGarbageIsRefused(): void
    {
        self::assertNull(self::validator()->validate('not-a-jwt'));
    }

    public function testATokenCarryingNoExpiryIsRefused(): void
    {
        $token = self::encodeExactly(['iss' => self::ISSUER, 'sub' => 'user-7']);

        self::assertNull(self::validator()->validate($token));
    }

    /**
     * @param \Closure(int): (float|int|string) $spell
     */
    #[DataProvider('provideAnyNumericExpiryIsAcceptedCases')]
    public function testAnyNumericExpiryIsAccepted(\Closure $spell): void
    {
        $expiry = time() + 60;

        $verified = self::validator()->validate(self::encodeWithIssuerAndExpiry(['exp' => $spell($expiry)]));

        self::assertNotNull($verified);
        self::assertSame($expiry, $verified->expiresAt);
    }

    /**
     * The expiry is spelled from a base the test resolves, since one baked in here would age past
     * the token's lifetime on a slow run.
     *
     * @return iterable<string, array{0: \Closure(int): (float|int|string)}>
     */
    public static function provideAnyNumericExpiryIsAcceptedCases(): iterable
    {
        yield 'integer' => [static fn(int $expiry): int => $expiry];

        yield 'fractional' => [static fn(int $expiry): float => $expiry + 0.75];

        yield 'numeric string' => [static fn(int $expiry): string => (string) $expiry];
    }

    public function testATokenFromAnotherIssuerIsRefused(): void
    {
        $token = self::encodeExactly([
            'iss' => 'https://attacker.example.test',
            'aud' => 'https://mcp.example.com/mcp',
            'exp' => time() + 60,
        ]);

        self::assertNull(self::validator()->validate($token));
    }

    public function testATokenCarryingNoIssuerIsRefused(): void
    {
        $token = self::encodeExactly(['aud' => 'https://mcp.example.com/mcp', 'exp' => time() + 60]);

        self::assertNull(self::validator()->validate($token));
    }

    public function testAnEmptyExpectedIssuerIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('JWKS validator expected issuer must be a non-empty string, string given.');

        // @phpstan-ignore argument.type
        new JwksAccessTokenValidator([self::KID => new Key(self::SECRET, 'HS256')], '');
    }

    private static function validator(): JwksAccessTokenValidator
    {
        return new JwksAccessTokenValidator([self::KID => new Key(self::SECRET, 'HS256')], self::ISSUER);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function encodeWithIssuerAndExpiry(array $claims): string
    {
        return self::encodeExactly($claims + ['iss' => self::ISSUER, 'exp' => time() + 60]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function encodeExactly(array $claims): string
    {
        return JWT::encode($claims, self::SECRET, 'HS256', self::KID);
    }
}
