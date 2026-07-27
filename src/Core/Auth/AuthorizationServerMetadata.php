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

namespace Nexus\Mcp\Core\Auth;

/**
 * The metadata document an authorization server publishes, as served by either OAuth 2.0 Authorization
 * Server Metadata or OpenID Connect Discovery.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414#section-2
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html#ProviderMetadata
 */
final readonly class AuthorizationServerMetadata
{
    private const string LABEL = 'Authorization Server Metadata';

    /**
     * @param non-empty-string        $issuer
     * @param ?non-empty-string       $authorizationEndpoint
     * @param ?non-empty-string       $tokenEndpoint
     * @param ?non-empty-string       $registrationEndpoint
     * @param ?list<non-empty-string> $codeChallengeMethodsSupported
     */
    public function __construct(
        public string $issuer,
        public ?string $authorizationEndpoint = null,
        public ?string $tokenEndpoint = null,
        public ?string $registrationEndpoint = null,
        public ?ScopeSet $scopesSupported = null,
        public ?array $codeChallengeMethodsSupported = null,
        public ?bool $authorizationResponseIssParameterSupported = null,
        public ?bool $clientIdMetadataDocumentSupported = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scopes = MetadataReader::readStringList($data, 'scopes_supported', self::LABEL);

        return new self(
            MetadataReader::readRequiredString($data, 'issuer', self::LABEL),
            MetadataReader::readString($data, 'authorization_endpoint', self::LABEL),
            MetadataReader::readString($data, 'token_endpoint', self::LABEL),
            MetadataReader::readString($data, 'registration_endpoint', self::LABEL),
            null === $scopes ? null : new ScopeSet($scopes),
            MetadataReader::readStringList($data, 'code_challenge_methods_supported', self::LABEL),
            MetadataReader::readBool($data, 'authorization_response_iss_parameter_supported', self::LABEL),
            MetadataReader::readBool($data, 'client_id_metadata_document_supported', self::LABEL),
        );
    }
}
