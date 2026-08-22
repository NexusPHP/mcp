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

namespace Nexus\Mcp\Tests\Extension\Auth\Enterprise;

use Amp\Http\Client\Request;
use Amp\NullCancellation;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertion;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionExchanger;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionType;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(IdentityAssertionExchanger::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class IdentityAssertionExchangerTest extends AbstractMcpTestCase
{
    private const string IDP_ENDPOINT = 'https://idp.example.com/token';
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testExchangesTheAssertionForAnIdJag(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($this->exchangeResponse());
        $exchanger = new IdentityAssertionExchanger(self::IDP_ENDPOINT, $http, 'the-idp-client');

        $idJag = $exchanger->exchangeForGrant(
            new IdentityAssertion('the-id-token', IdentityAssertionType::IdToken),
            self::ISSUER,
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );

        self::assertSame('the-id-jag', $idJag);

        $request = $http->readRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::IDP_ENDPOINT, (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));
        self::assertSame([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:id-jag',
            'subject_token' => 'the-id-token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'audience' => self::ISSUER,
            'resource' => self::RESOURCE,
            'client_id' => 'the-idp-client',
        ], $this->readForm($request));
    }

    public function testTheClientIdentifierIsLeftOffWhenNoneIsConfigured(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($this->exchangeResponse());

        (new IdentityAssertionExchanger(self::IDP_ENDPOINT, $http))->exchangeForGrant(
            new IdentityAssertion('the-saml-refresh-token', IdentityAssertionType::RefreshToken),
            self::ISSUER,
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );

        self::assertSame([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:id-jag',
            'subject_token' => 'the-saml-refresh-token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:refresh_token',
            'audience' => self::ISSUER,
            'resource' => self::RESOURCE,
        ], $this->readForm($http->readRequest()));
    }

    public function testARefusedExchangeSurfacesTheError(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(
            ['error' => 'invalid_grant', 'error_description' => 'The assertion has expired.'],
            400,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The enterprise IdP refused the token exchange with "invalid_grant": The assertion has expired.');

        $this->exchange($http);
    }

    public function testARefusalWithoutADescriptionStillNamesTheError(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['error' => 'invalid_target'], 400);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The enterprise IdP refused the token exchange with "invalid_target".');

        $this->exchange($http);
    }

    public function testARefusalWhoseBodyIsNotJsonNamesTheStatus(): void
    {
        $http = (new RecordingHttpClient())->willChallenge(502, 'Bearer', '<html>Bad gateway</html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The enterprise IdP answered 502 with a body that is not a JSON object.');

        $this->exchange($http);
    }

    public function testASuccessWhoseBodyIsNotJsonIsReportedAsMalformed(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson('<html>All good, honest</html>');

        $this->expectException(MalformedAuthorizationResponseException::class);

        $this->exchange($http);
    }

    public function testAWrongIssuedTokenTypeIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($this->exchangeResponse([
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The enterprise IdP issued a "urn:ietf:params:oauth:token-type:access_token" token where an ID-JAG was requested.');

        $this->exchange($http);
    }

    public function testAHostileIssuedTokenTypeIsBoundedAndEscapedInTheRefusal(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($this->exchangeResponse([
            'issued_token_type' => str_repeat('u', 200)."\x1b",
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The enterprise IdP issued a "%s..." token where an ID-JAG was requested.',
            str_repeat('u', 77),
        ));

        $this->exchange($http);
    }

    public function testACleartextIdpEndpointIsRefused(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the IdP token endpoint "http://idp.example.com/token" is not an absolute HTTPS URL.');

        new IdentityAssertionExchanger('http://idp.example.com/token', new RecordingHttpClient());
    }

    public function testACleartextLoopbackIdpEndpointIsRefusedByDefault(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the IdP token endpoint "http://127.0.0.1:1/token" is not an absolute HTTPS URL.');

        new IdentityAssertionExchanger('http://127.0.0.1:1/token', new RecordingHttpClient());
    }

    private function exchange(RecordingHttpClient $http): string
    {
        return (new IdentityAssertionExchanger(self::IDP_ENDPOINT, $http))->exchangeForGrant(
            new IdentityAssertion('the-id-token', IdentityAssertionType::IdToken),
            self::ISSUER,
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function exchangeResponse(array $overrides = []): array
    {
        return [
            'access_token' => 'the-id-jag',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:id-jag',
            'token_type' => 'N_A',
            ...$overrides,
        ];
    }

    /**
     * @return array<array-key, string>
     */
    private function readForm(Request $request): array
    {
        parse_str(buffer($request->getBody()->getContent()), $parsed);

        $parameters = [];

        foreach ($parsed as $name => $value) {
            if (\is_string($value)) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }
}
