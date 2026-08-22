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
    private const string RESOURCE = 'https://mcp.example.com/mcp';

    public function testAValidTokenMapsTheStandardClaims(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer([
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
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['aud' => 'https://mcp.example.com/mcp']));

        self::assertNotNull($verified);
        self::assertSame(['https://mcp.example.com/mcp'], $verified->audience);
    }

    public function testNonStringAudienceEntriesAreDropped(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['aud' => ['https://mcp.example.com/mcp', 42]]));

        self::assertNotNull($verified);
        self::assertSame(['https://mcp.example.com/mcp'], $verified->audience);
    }

    public function testATokenCarryingNoAudienceIsRefused(): void
    {
        $token = $this->encodeExactly(['iss' => self::ISSUER, 'exp' => time() + 60, 'sub' => 'user-7']);

        self::assertNull($this->buildValidator()->validate($token));
    }

    #[DataProvider('provideATokenForAnotherResourceIsRefusedCases')]
    public function testATokenForAnotherResourceIsRefused(mixed $audience): void
    {
        self::assertNull($this->buildValidator()->validate($this->encodeForThisServer(['aud' => $audience])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideATokenForAnotherResourceIsRefusedCases(): iterable
    {
        yield 'another server as a string' => ['https://other.example.com/mcp'];

        yield 'another server in a list' => [['https://other.example.com/mcp', 'https://spare.example.com']];

        yield 'the same host at another path' => ['https://mcp.example.com/other'];

        yield 'a scheme downgrade' => ['http://mcp.example.com/mcp'];

        yield 'an empty list' => [[]];

        yield 'a non-string audience' => [42];
    }

    public function testAnAudienceNamingThisServerAmongOthersIsAccepted(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['aud' => ['https://other.example.com/mcp', self::RESOURCE]]));

        self::assertNotNull($verified);
    }

    public function testAnAudienceNamingThisServerInAnotherSpellingIsAccepted(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['aud' => 'HTTPS://MCP.Example.com:443/mcp']));

        self::assertNotNull($verified);
    }

    public function testAnUnusableResourceIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "not a uri" given.');

        new JwksAccessTokenValidator([self::KID => new Key(self::SECRET, 'HS256')], self::ISSUER, 'not a uri');
    }

    public function testScpAsAListIsReadAsScopes(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['scp' => ['mcp:use', '', 7, 'files:read']]));

        self::assertNotNull($verified);
        self::assertSame(['mcp:use', 'files:read'], $verified->scopes);
    }

    public function testScpAsAStringIsSplitLikeScope(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['scp' => 'mcp:use files:read']));

        self::assertNotNull($verified);
        self::assertSame(['mcp:use', 'files:read'], $verified->scopes);
    }

    public function testScopeOutranksScp(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['scope' => 'mcp:use', 'scp' => ['other']]));

        self::assertNotNull($verified);
        self::assertSame(['mcp:use'], $verified->scopes);
    }

    public function testNoScopeClaimMeansNoScopes(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['scope' => 42]));

        self::assertNotNull($verified);
        self::assertSame([], $verified->scopes);
    }

    public function testClientIdFallsBackThroughAzpClientIdAndCid(): void
    {
        $validator = $this->buildValidator();

        $azp = $validator->validate($this->encodeForThisServer(['azp' => 'from-azp', 'client_id' => 'from-client-id', 'cid' => 'from-cid']));
        $clientId = $validator->validate($this->encodeForThisServer(['client_id' => 'from-client-id', 'cid' => 'from-cid']));
        $cid = $validator->validate($this->encodeForThisServer(['cid' => 'from-cid']));
        $nonString = $validator->validate($this->encodeForThisServer(['azp' => 99]));
        $masking = $validator->validate($this->encodeForThisServer(['azp' => 99, 'client_id' => 'from-client-id']));

        self::assertSame('from-azp', $azp?->clientId);
        self::assertSame('from-client-id', $clientId?->clientId);
        self::assertSame('from-cid', $cid?->clientId);
        self::assertNull($nonString?->clientId);
        self::assertSame('from-client-id', $masking?->clientId, 'A claim naming nobody is skipped, not treated as the answer.');
    }

    public function testANonStringSubjectIsDropped(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['sub' => 12_345]));

        self::assertNotNull($verified);
        self::assertNull($verified->subject);
    }

    public function testAnEmptySubjectIsDropped(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['sub' => '']));

        self::assertNotNull($verified);
        self::assertNull($verified->subject, 'An empty subject names nobody, so it must not read as a caller identity.');
    }

    public function testAnEmptyClientIdIsDropped(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['azp' => '']));

        self::assertNotNull($verified);
        self::assertNull($verified->clientId, 'An empty client id names nobody, so it must not read as a caller identity.');
    }

    public function testAnEmptyClientIdClaimDoesNotMaskALaterOne(): void
    {
        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['azp' => '', 'client_id' => 'acme']));

        self::assertNotNull($verified);
        self::assertSame('acme', $verified->clientId);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        self::assertNull($this->buildValidator()->validate($this->encodeForThisServer(['exp' => time() - 60])));
    }

    public function testAForeignSignatureIsRefused(): void
    {
        $token = JWT::encode(['sub' => 'user-7'], 'a-different-secret-also-32-bytes-long', 'HS256', self::KID);

        self::assertNull($this->buildValidator()->validate($token));
    }

    public function testGarbageIsRefused(): void
    {
        self::assertNull($this->buildValidator()->validate('not-a-jwt'));
    }

    public function testATokenCarryingNoExpiryIsRefused(): void
    {
        $token = $this->encodeExactly(['iss' => self::ISSUER, 'aud' => self::RESOURCE, 'sub' => 'user-7']);

        self::assertNull($this->buildValidator()->validate($token));
    }

    /**
     * @param \Closure(int): (float|int|string) $spell
     */
    #[DataProvider('provideAnyNumericExpiryIsAcceptedCases')]
    public function testAnyNumericExpiryIsAccepted(\Closure $spell): void
    {
        $expiry = time() + 60;

        $verified = $this->buildValidator()->validate($this->encodeForThisServer(['exp' => $spell($expiry)]));

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
        $token = $this->encodeExactly([
            'iss' => 'https://attacker.example.test',
            'aud' => 'https://mcp.example.com/mcp',
            'exp' => time() + 60,
        ]);

        self::assertNull($this->buildValidator()->validate($token));
    }

    public function testATokenCarryingNoIssuerIsRefused(): void
    {
        $token = $this->encodeExactly(['aud' => 'https://mcp.example.com/mcp', 'exp' => time() + 60]);

        self::assertNull($this->buildValidator()->validate($token));
    }

    public function testAnEmptyExpectedIssuerIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('JWKS validator expected issuer must be a non-empty string, string given.');

        // @phpstan-ignore argument.type
        new JwksAccessTokenValidator([self::KID => new Key(self::SECRET, 'HS256')], '', self::RESOURCE);
    }

    private function buildValidator(): JwksAccessTokenValidator
    {
        return new JwksAccessTokenValidator([self::KID => new Key(self::SECRET, 'HS256')], self::ISSUER, self::RESOURCE);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function encodeForThisServer(array $claims): string
    {
        return $this->encodeExactly($claims + ['iss' => self::ISSUER, 'aud' => self::RESOURCE, 'exp' => time() + 60]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function encodeExactly(array $claims): string
    {
        return JWT::encode($claims, self::SECRET, 'HS256', self::KID);
    }
}
