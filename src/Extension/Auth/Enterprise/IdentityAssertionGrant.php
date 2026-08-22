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

namespace Nexus\Mcp\Extension\Auth\Enterprise;

use Amp\Cancellation;
use Nexus\Assert\Assert;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\GrantStrategyInterface;
use Nexus\Mcp\Client\Auth\SecureEndpoint;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Extension\Auth\GrantTypeAdvertisement;
use Psr\Log\LoggerInterface;

/**
 * The enterprise-managed authorization grant (SEP-990), a two-legged token exchange with no user redirect.
 *
 * @see https://github.com/modelcontextprotocol/ext-auth/blob/main/specification/stable/enterprise-managed-authorization.mdx
 */
final readonly class IdentityAssertionGrant implements GrantStrategyInterface
{
    private SecureEndpoint $secureEndpoint;

    /**
     * @param non-empty-string      $idpTokenEndpoint
     * @param null|non-empty-string $idpClientId
     * @param bool                  $allowInsecureLoopback Admits an IdP reached over cleartext HTTP on a loopback host, which the spec does not exempt. For local development and conformance runs, never production
     */
    public function __construct(
        private string $idpTokenEndpoint,
        private IdentityAssertionProviderInterface $assertions,
        private ?string $idpClientId = null,
        bool $allowInsecureLoopback = false,
    ) {
        Assert::that($idpTokenEndpoint)->isNonEmptyString('"idpTokenEndpoint" must be a non-empty string.');
        Assert::that($idpClientId)->nullOr()->isNonEmptyString('"idpClientId" must be a non-empty string or null.');

        $this->secureEndpoint = new SecureEndpoint($allowInsecureLoopback);
        $this->secureEndpoint->verifyAuthorizationServerUrl($idpTokenEndpoint, 'IdP token endpoint');
    }

    #[\Override]
    public function grant(GrantContext $context, Cancellation $cancellation): AccessToken
    {
        $server = $context->discovered->server;
        $this->verifyAdvertisedSupport($server, $context->logger);
        $registration = $this->resolveRegistration($context, $cancellation);

        $idJag = (new IdentityAssertionExchanger(
            $this->idpTokenEndpoint,
            $context->httpClient,
            $this->idpClientId,
            $context->options->timeout,
            $this->secureEndpoint,
        ))->exchangeForGrant(
            $this->assertions->provideAssertion($cancellation),
            $server->issuer,
            $context->resource,
            $cancellation,
        );

        $parameters = [
            'grant_type' => EnterpriseAuthorization::JWT_BEARER_GRANT_TYPE,
            'assertion' => $idJag,
            'resource' => $context->resource->value,
        ];
        $scope = $context->scopes->toParameter();

        if (null !== $scope) {
            $parameters['scope'] = $scope;
        }

        return $context->requestToken($registration, $parameters, $cancellation);
    }

    #[\Override]
    public function renewsByFreshGrant(): bool
    {
        return true;
    }

    /**
     * Holds the authorization server to the grant profile it published, and takes ID-JAG support on trust
     * where it publishes no profile list at all.
     */
    private function verifyAdvertisedSupport(AuthorizationServerMetadata $server, LoggerInterface $logger): void
    {
        GrantTypeAdvertisement::verify($server, EnterpriseAuthorization::JWT_BEARER_GRANT_TYPE);

        $profiles = $server->authorizationGrantProfilesSupported;

        if (null === $profiles) {
            $logger->info('The authorization server {issuer} publishes no authorization grant profiles, so ID-JAG support is taken on trust.', [
                'issuer' => SafeDisplay::sanitiseCause($server->issuer),
            ]);

            return;
        }

        if (! \in_array(EnterpriseAuthorization::GRANT_PROFILE, $profiles, true)) {
            throw new RuntimeException(\sprintf(
                'The authorization server "%s" does not advertise the "%s" authorization grant profile.',
                SafeDisplay::sanitiseCause($server->issuer),
                EnterpriseAuthorization::GRANT_PROFILE,
            ));
        }
    }

    /**
     * SEP-990 authenticates at the resource authorization server with credentials registered out of band or
     * a Client ID Metadata Document, never Dynamic Client Registration, so the registrar only runs once one
     * of the two is configured.
     */
    private function resolveRegistration(GrantContext $context, Cancellation $cancellation): ClientRegistration
    {
        $server = $context->discovered->server;
        $options = $context->options;

        if (null === $options->preRegistered && null === $options->clientIdMetadataDocumentUrl) {
            throw new RuntimeException(
                'Enterprise-managed authorization needs pre-registered credentials or a Client ID Metadata Document URL, and the authorization options carry neither.',
            );
        }

        if (null === $options->preRegistered && true !== $server->clientIdMetadataDocumentSupported) {
            throw new RuntimeException(\sprintf(
                'The authorization server "%s" does not support Client ID Metadata Documents.',
                SafeDisplay::sanitiseCause($server->issuer),
            ));
        }

        return $context->resolveRegistration($cancellation);
    }
}
