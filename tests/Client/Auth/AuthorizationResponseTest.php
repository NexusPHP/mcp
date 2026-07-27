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

namespace Nexus\Mcp\Tests\Client\Auth;

use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\AuthorizationResponse;
use Nexus\Mcp\Client\Auth\PkcePair;
use Nexus\Mcp\Client\Exception\AuthorizationDeniedException;
use Nexus\Mcp\Client\Exception\InvalidAuthorizationResponseException;
use Nexus\Mcp\Core\Auth\ScopeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationResponse::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationResponseTest extends TestCase
{
    private const string ISSUER = 'https://auth.example.com';
    private const string STATE = 'the-recorded-state';

    public function testReadCodeReturnsTheCode(): void
    {
        $code = AuthorizationResponse::readCode(
            self::buildRedirect(),
            self::buildCallback(['code' => 'abc123', 'state' => self::STATE]),
        );

        self::assertSame('abc123', $code);
    }

    public function testReadCodeAcceptsAMatchingIssuer(): void
    {
        $code = AuthorizationResponse::readCode(
            self::buildRedirect(issuerParameterRequired: true),
            self::buildCallback(['code' => 'abc123', 'state' => self::STATE, 'iss' => self::ISSUER]),
        );

        self::assertSame('abc123', $code);
    }

    public function testReadCodeAcceptsAMatchingIssuerTheServerDidNotAdvertise(): void
    {
        $code = AuthorizationResponse::readCode(
            self::buildRedirect(),
            self::buildCallback(['code' => 'abc123', 'state' => self::STATE, 'iss' => self::ISSUER]),
        );

        self::assertSame('abc123', $code);
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('provideReadCodeRejectsAStateMismatchCases')]
    public function testReadCodeRejectsAStateMismatch(array $parameters): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because its "state" does not echo the authorization request.');

        AuthorizationResponse::readCode(self::buildRedirect(), self::buildCallback($parameters));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideReadCodeRejectsAStateMismatchCases(): iterable
    {
        yield 'a different state' => [['code' => 'abc123', 'state' => 'another-state']];

        yield 'an absent state' => [['code' => 'abc123']];

        yield 'an empty state' => [['code' => 'abc123', 'state' => '']];
    }

    public function testReadCodeRejectsAnAbsentIssuerTheServerAdvertises(): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because it omits the "iss" the authorization server advertises that it emits.');

        AuthorizationResponse::readCode(
            self::buildRedirect(issuerParameterRequired: true),
            self::buildCallback(['code' => 'abc123', 'state' => self::STATE]),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('provideReadCodeRejectsAnIssuerMismatchCases')]
    public function testReadCodeRejectsAnIssuerMismatch(array $parameters, bool $issuerParameterRequired): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because its "iss" names an authorization server other than the one the request was sent to.');

        AuthorizationResponse::readCode(self::buildRedirect($issuerParameterRequired), self::buildCallback($parameters));
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function provideReadCodeRejectsAnIssuerMismatchCases(): iterable
    {
        yield 'a different issuer when the parameter is advertised' => [
            ['code' => 'abc123', 'state' => self::STATE, 'iss' => 'https://attacker.example'],
            true,
        ];

        yield 'a different issuer when the parameter is not advertised' => [
            ['code' => 'abc123', 'state' => self::STATE, 'iss' => 'https://attacker.example'],
            false,
        ];

        yield 'a trailing slash is not normalised away' => [
            ['code' => 'abc123', 'state' => self::STATE, 'iss' => 'https://auth.example.com/'],
            false,
        ];

        yield 'host case is not folded' => [
            ['code' => 'abc123', 'state' => self::STATE, 'iss' => 'https://AUTH.example.com'],
            false,
        ];
    }

    public function testReadCodeRejectsAMismatchedIssuerBeforeSurfacingTheError(): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because its "iss" names an authorization server other than the one the request was sent to.');

        AuthorizationResponse::readCode(
            self::buildRedirect(),
            self::buildCallback([
                'state' => self::STATE,
                'iss' => 'https://attacker.example',
                'error' => 'access_denied',
                'error_description' => 'Attacker-supplied text that must not surface.',
            ]),
        );
    }

    public function testReadCodeSurfacesTheErrorWithItsDescription(): void
    {
        $this->expectException(AuthorizationDeniedException::class);
        $this->expectExceptionMessageIs('The authorization server denied the request with "access_denied": The user refused consent.');

        AuthorizationResponse::readCode(
            self::buildRedirect(),
            self::buildCallback([
                'state' => self::STATE,
                'error' => 'access_denied',
                'error_description' => 'The user refused consent.',
            ]),
        );
    }

    public function testReadCodeSurfacesAnErrorWithNoDescription(): void
    {
        $this->expectException(AuthorizationDeniedException::class);
        $this->expectExceptionMessageIs('The authorization server denied the request with "invalid_scope".');

        AuthorizationResponse::readCode(
            self::buildRedirect(),
            self::buildCallback(['state' => self::STATE, 'error' => 'invalid_scope']),
        );
    }

    public function testTheDeniedExceptionExposesTheErrorCode(): void
    {
        try {
            AuthorizationResponse::readCode(
                self::buildRedirect(),
                self::buildCallback(['state' => self::STATE, 'error' => 'invalid_scope']),
            );
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('invalid_scope', $e->error);

            return;
        }

        self::fail('Expected the authorization response to be denied.');
    }

    public function testReadCodeRejectsAResponseWithNoCode(): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because it carries no authorization code.');

        AuthorizationResponse::readCode(self::buildRedirect(), self::buildCallback(['state' => self::STATE]));
    }

    private static function buildRedirect(bool $issuerParameterRequired = false): AuthorizationRedirect
    {
        return new AuthorizationRedirect(
            'https://auth.example.com/authorize?response_type=code',
            self::STATE,
            self::ISSUER,
            $issuerParameterRequired,
            PkcePair::fromVerifier('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
            new ScopeSet(),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function buildCallback(array $parameters): AuthorizationCallback
    {
        return new AuthorizationCallback('http://localhost:3000/callback?'.http_build_query($parameters));
    }
}
