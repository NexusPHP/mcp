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
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Nexus\Mcp\Client\Auth\JsonHttpExchange;
use Nexus\Mcp\Client\Auth\SecureEndpoint;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Core\Auth\MetadataReader;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Extension\Auth\Exception\IdentityAssertionExchangeFailedException;

/**
 * The RFC 8693 token exchange at the enterprise IdP: an identity assertion in, an ID-JAG out.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8693#section-2
 */
final readonly class IdentityAssertionExchanger
{
    private const string LABEL = 'Token exchange response';

    private JsonHttpExchange $exchange;

    /**
     * @param non-empty-string      $tokenEndpoint The enterprise IdP's token endpoint
     * @param null|non-empty-string $clientId      The client's identifier at the enterprise IdP, sent when given
     */
    public function __construct(
        private string $tokenEndpoint,
        DelegateHttpClient $client,
        private ?string $clientId = null,
        float $timeout = 10.0,
        bool $allowInsecureLoopback = false,
    ) {
        SecureEndpoint::verifyAuthorizationServerUrl($tokenEndpoint, 'IdP token endpoint', $allowInsecureLoopback);
        $this->exchange = new JsonHttpExchange($client, $timeout);
    }

    /**
     * The ID-JAG the enterprise IdP issued for the assertion.
     *
     * @param non-empty-string $audience The resource authorization server's issuer identifier
     *
     * @return non-empty-string
     */
    public function exchangeForGrant(
        IdentityAssertion $assertion,
        string $audience,
        ResourceIdentifier $resource,
        Cancellation $cancellation,
    ): string {
        $parameters = [
            'grant_type' => EnterpriseAuthorization::TOKEN_EXCHANGE_GRANT_TYPE,
            'requested_token_type' => EnterpriseAuthorization::ID_JAG_TOKEN_TYPE,
            'subject_token' => $assertion->token,
            'subject_token_type' => $assertion->type->value,
            'audience' => $audience,
            'resource' => $resource->value,
        ];

        if (null !== $this->clientId) {
            $parameters['client_id'] = $this->clientId;
        }

        $request = new Request($this->tokenEndpoint, 'POST', http_build_query($parameters));
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');

        [$status, $payload] = $this->exchange->send($request, $cancellation);

        try {
            $data = JsonHttpExchange::decode($payload, 'IdP token endpoint');
        } catch (MalformedAuthorizationResponseException $e) {
            if (HttpStatus::Ok->value === $status) {
                throw $e;
            }

            throw new IdentityAssertionExchangeFailedException(\sprintf(
                'The enterprise IdP answered %d with a body that is not a JSON object.',
                $status,
            ));
        }

        if ($status >= HttpStatus::BadRequest->value) {
            $error = MetadataReader::readErrorField($data, 'error', self::LABEL) ?? 'invalid_request';
            $description = MetadataReader::readErrorField($data, 'error_description', self::LABEL);

            throw new IdentityAssertionExchangeFailedException(\sprintf(
                'The enterprise IdP refused the token exchange with "%s"%s',
                $error,
                null === $description ? '.' : \sprintf(': %s', $description),
            ));
        }

        $issuedType = MetadataReader::readRequiredString($data, 'issued_token_type', self::LABEL);

        if (EnterpriseAuthorization::ID_JAG_TOKEN_TYPE !== $issuedType) {
            throw new IdentityAssertionExchangeFailedException(\sprintf(
                'The enterprise IdP issued a "%s" token where an ID-JAG was requested.',
                $issuedType,
            ));
        }

        return MetadataReader::readRequiredString($data, 'access_token', self::LABEL);
    }
}
