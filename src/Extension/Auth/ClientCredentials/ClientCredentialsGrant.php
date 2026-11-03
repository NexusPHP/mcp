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

use Amp\Cancellation;
use Firebase\JWT\JWT;
use Nexus\Clock\Clock;
use Nexus\Clock\SystemClock;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\GrantStrategyInterface;
use Nexus\Mcp\Client\Exception\AuthorizationServerMismatchException;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Validation\SuggestedDependencyGuard;
use Nexus\Mcp\Extension\Auth\GrantTypeAdvertisement;

/**
 * The OAuth 2.1 client credentials grant (SEP-1046), an unattended machine-to-machine flow with no user
 * and no consent screen.
 *
 * @see https://github.com/modelcontextprotocol/ext-auth/blob/main/specification/stable/oauth-client-credentials/2026-07-28/oauth-client-credentials.mdx
 */
final readonly class ClientCredentialsGrant implements GrantStrategyInterface
{
    private const int ASSERTION_LIFETIME_SECONDS = 300;

    private TokenEndpointAuthMethod $authMethod;
    private ?string $clientSecret;

    /**
     * @var null|non-empty-string
     */
    private ?string $signingAlgorithm;

    public function __construct(
        private ClientSecretCredential|PrivateKeyJwtCredential $credential,
        private Clock $clock = new SystemClock(),
    ) {
        if ($credential instanceof PrivateKeyJwtCredential) {
            SuggestedDependencyGuard::verify(self::class, JWT::class, 'firebase/php-jwt', '^7.0');

            $this->authMethod = TokenEndpointAuthMethod::PrivateKeyJwt;
            $this->clientSecret = null;
            $this->signingAlgorithm = $credential->algorithm;
        } else {
            $this->authMethod = TokenEndpointAuthMethod::ClientSecretBasic;
            $this->clientSecret = $credential->clientSecret;
            $this->signingAlgorithm = null;
        }
    }

    #[\Override]
    public function grant(GrantContext $context, Cancellation $cancellation): AccessToken
    {
        if (null !== $context->options->preRegistered) {
            throw new RuntimeException(
                'The client credentials grant has its own credential, so the options must not pre-register one.',
            );
        }

        $server = $context->discovered->server;
        $registeredIssuer = $this->credential->issuer;

        if (null !== $registeredIssuer && $registeredIssuer !== $server->issuer) {
            throw new AuthorizationServerMismatchException($registeredIssuer, $server->issuer);
        }

        $this->verifyAdvertisedSupport($server);

        $parameters = [
            'grant_type' => ClientCredentials::GRANT_TYPE,
            'resource' => $context->resource->value,
        ];
        $scope = $context->scopes->toParameter();

        if (null !== $scope) {
            $parameters['scope'] = $scope;
        }

        if ($this->credential instanceof PrivateKeyJwtCredential) {
            $parameters['client_assertion_type'] = ClientCredentials::CLIENT_ASSERTION_TYPE;
            $parameters['client_assertion'] = $this->signAssertion($this->credential, $server->issuer);
        }

        $registration = new ClientRegistration(
            $this->credential->clientId,
            $server->issuer,
            $this->clientSecret,
            $this->authMethod,
        );

        return $context->requestToken($registration, $parameters, $cancellation);
    }

    #[\Override]
    public function renewsByFreshGrant(): bool
    {
        return true;
    }

    /**
     * SEP-1046 makes the authentication-method list a mandatory discovery signal, so its absence is as
     * disqualifying as a list that omits the configured method.
     */
    private function verifyAdvertisedSupport(AuthorizationServerMetadata $server): void
    {
        $methods = $server->tokenEndpointAuthMethodsSupported;

        if (null === $methods || ! \in_array($this->authMethod->value, $methods, true)) {
            throw new RuntimeException(\sprintf(
                'The authorization server "%s" does not advertise the "%s" token endpoint authentication method.',
                SafeDisplay::sanitiseCause($server->issuer),
                $this->authMethod->value,
            ));
        }

        $algorithms = $server->tokenEndpointAuthSigningAlgValuesSupported;

        if (
            null !== $this->signingAlgorithm
            && null !== $algorithms
            && ! \in_array($this->signingAlgorithm, $algorithms, true)
        ) {
            throw new RuntimeException(\sprintf(
                'The authorization server "%s" does not advertise the "%s" client assertion signing algorithm.',
                SafeDisplay::sanitiseCause($server->issuer),
                $this->signingAlgorithm,
            ));
        }

        GrantTypeAdvertisement::verify($server, ClientCredentials::GRANT_TYPE);
    }

    /**
     * Signs an RFC 7523 JWT client assertion naming the client as both issuer and subject, presentable to `$audience`.
     *
     * @param non-empty-string $audience
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7523#section-3
     */
    private function signAssertion(PrivateKeyJwtCredential $credential, string $audience): string
    {
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
