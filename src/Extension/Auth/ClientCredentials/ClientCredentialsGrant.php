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
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\GrantStrategyInterface;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Extension\Auth\ClientAssertionSigner;
use Nexus\Mcp\Extension\Auth\Exception\UnsupportedClientAuthenticationException;
use Nexus\Mcp\Extension\Auth\GrantTypeAdvertisement;

/**
 * The OAuth 2.1 client credentials grant (SEP-1046): an unattended machine-to-machine flow presenting
 * credentials registered out of band, with no user and no consent screen.
 *
 * @see https://github.com/modelcontextprotocol/ext-auth/blob/main/specification/draft/oauth-client-credentials.mdx
 */
final readonly class ClientCredentialsGrant implements GrantStrategyInterface
{
    /**
     * Signs the client assertion, or `null` when the credential authenticates with a secret.
     */
    private ?ClientAssertionSigner $signer;

    private TokenEndpointAuthMethod $authMethod;
    private ?string $clientSecret;

    /**
     * @var null|non-empty-string
     */
    private ?string $signingAlgorithm;

    public function __construct(private ClientSecretCredential|PrivateKeyJwtCredential $credential)
    {
        if ($credential instanceof PrivateKeyJwtCredential) {
            // Built here so a missing signing dependency is reported while the client is being configured,
            // rather than at the first request that needs a token.
            $this->signer = new ClientAssertionSigner($credential);
            $this->authMethod = TokenEndpointAuthMethod::PrivateKeyJwt;
            $this->clientSecret = null;
            $this->signingAlgorithm = $credential->algorithm;
        } else {
            $this->signer = null;
            $this->authMethod = TokenEndpointAuthMethod::ClientSecretBasic;
            $this->clientSecret = $credential->clientSecret;
            $this->signingAlgorithm = null;
        }
    }

    #[\Override]
    public function grant(GrantContext $context, Cancellation $cancellation): AccessToken
    {
        // This grant's own credential carries the client identity, key material included, so a second one in
        // the options would be outranked without saying so.
        if (null !== $context->options->preRegistered) {
            throw new UnsupportedClientAuthenticationException(
                'The client credentials grant authenticates with the credential it was given, so the authorization options must not carry a pre-registered one as well.',
            );
        }

        $server = $context->discovered->server;
        $this->verifyAdvertisedSupport($server);

        $parameters = [
            'grant_type' => ClientCredentials::GRANT_TYPE,
            'resource' => $context->resource->value,
        ];
        $scope = $context->scopes->toParameter();

        if (null !== $scope) {
            $parameters['scope'] = $scope;
        }

        if (null !== $this->signer) {
            $parameters['client_assertion_type'] = ClientCredentials::CLIENT_ASSERTION_TYPE;
            $parameters['client_assertion'] = $this->signer->signAssertion($server->issuer);
        }

        $registration = new ClientRegistration(
            $this->credential->clientId,
            $server->issuer,
            $this->clientSecret,
            $this->authMethod,
        );

        return $context->tokenEndpoint->requestToken($server, $registration, $parameters, $context->scopes, $cancellation);
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
            throw new UnsupportedClientAuthenticationException(\sprintf(
                'The authorization server "%s" does not advertise the "%s" token endpoint authentication method.',
                $server->issuer,
                $this->authMethod->value,
            ));
        }

        $algorithms = $server->tokenEndpointAuthSigningAlgValuesSupported;

        if (null !== $this->signingAlgorithm && null !== $algorithms && ! \in_array($this->signingAlgorithm, $algorithms, true)) {
            throw new UnsupportedClientAuthenticationException(\sprintf(
                'The authorization server "%s" does not advertise the "%s" client assertion signing algorithm.',
                $server->issuer,
                $this->signingAlgorithm,
            ));
        }

        GrantTypeAdvertisement::verify($server, ClientCredentials::GRANT_TYPE);
    }
}
