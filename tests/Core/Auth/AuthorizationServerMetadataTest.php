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

namespace Nexus\Mcp\Tests\Core\Auth;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AuthorizationServerMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class AuthorizationServerMetadataTest extends AbstractMcpTestCase
{
    public function testFromArrayReadsTheFullDocument(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'registration_endpoint' => 'https://auth.example.com/register',
            'scopes_supported' => ['files:read', 'offline_access'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true,
            'token_endpoint_auth_methods_supported' => ['private_key_jwt', 'client_secret_basic'],
            'token_endpoint_auth_signing_alg_values_supported' => ['ES256'],
            'grant_types_supported' => ['authorization_code', 'client_credentials'],
            'authorization_grant_profiles_supported' => ['urn:ietf:params:oauth:grant-profile:id-jag'],
        ]);

        self::assertSame('https://auth.example.com', $metadata->issuer);
        self::assertSame('https://auth.example.com/authorize', $metadata->authorizationEndpoint);
        self::assertSame('https://auth.example.com/token', $metadata->tokenEndpoint);
        self::assertSame('https://auth.example.com/register', $metadata->registrationEndpoint);
        self::assertSame(['files:read', 'offline_access'], $metadata->scopesSupported?->values);
        self::assertSame(['S256'], $metadata->codeChallengeMethodsSupported);
        self::assertTrue($metadata->authorizationResponseIssParameterSupported);
        self::assertTrue($metadata->clientIdMetadataDocumentSupported);
        self::assertSame(['private_key_jwt', 'client_secret_basic'], $metadata->tokenEndpointAuthMethodsSupported);
        self::assertSame(['ES256'], $metadata->tokenEndpointAuthSigningAlgValuesSupported);
        self::assertSame(['authorization_code', 'client_credentials'], $metadata->grantTypesSupported);
        self::assertSame(['urn:ietf:params:oauth:grant-profile:id-jag'], $metadata->authorizationGrantProfilesSupported);
    }

    public function testFromArrayLeavesEveryOptionalFieldNull(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray(['issuer' => 'https://auth.example.com']);

        self::assertSame('https://auth.example.com', $metadata->issuer);
        self::assertNull($metadata->authorizationEndpoint);
        self::assertNull($metadata->tokenEndpoint);
        self::assertNull($metadata->registrationEndpoint);
        self::assertNull($metadata->scopesSupported);
        self::assertNull($metadata->codeChallengeMethodsSupported);
        self::assertNull($metadata->authorizationResponseIssParameterSupported);
        self::assertNull($metadata->clientIdMetadataDocumentSupported);
        self::assertNull($metadata->tokenEndpointAuthMethodsSupported);
        self::assertNull($metadata->tokenEndpointAuthSigningAlgValuesSupported);
        self::assertNull($metadata->grantTypesSupported);
        self::assertNull($metadata->authorizationGrantProfilesSupported);
    }

    public function testFromArrayKeepsAFalseFlagDistinctFromAnAbsentOne(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'authorization_response_iss_parameter_supported' => false,
            'client_id_metadata_document_supported' => false,
        ]);

        self::assertFalse($metadata->authorizationResponseIssParameterSupported);
        self::assertFalse($metadata->clientIdMetadataDocumentSupported);
    }

    public function testFromArrayIgnoresUnknownFields(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'dpop_signing_alg_values_supported' => ['ES256'],
        ]);

        self::assertSame('https://auth.example.com', $metadata->issuer);
    }

    public function testFromArrayRejectsAnAbsentIssuer(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Authorization Server Metadata must carry a "issuer" value.');

        AuthorizationServerMetadata::fromArray([]);
    }

    public function testFromArrayRejectsAMistypedEndpoint(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Authorization Server Metadata "token_endpoint" must be a non-empty string, int given.');

        AuthorizationServerMetadata::fromArray([
            'issuer' => 'https://auth.example.com',
            'token_endpoint' => 42,
        ]);
    }
}
