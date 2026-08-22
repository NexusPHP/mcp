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

use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ProtectedResourceMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProtectedResourceMetadataTest extends AbstractMcpTestCase
{
    public function testFromArrayReadsTheFullDocument(): void
    {
        $metadata = ProtectedResourceMetadata::fromArray([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => ['https://auth.example.com'],
            'scopes_supported' => ['files:read', 'files:write'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Example MCP Server',
        ]);

        self::assertSame('https://mcp.example.com/mcp', $metadata->resource->value);
        self::assertSame(['https://auth.example.com'], $metadata->authorizationServers);
        self::assertSame(['files:read', 'files:write'], $metadata->scopesSupported?->values);
        self::assertSame(['header'], $metadata->bearerMethodsSupported);
        self::assertSame('Example MCP Server', $metadata->resourceName);
    }

    public function testFromArrayLeavesOptionalFieldsNull(): void
    {
        $metadata = ProtectedResourceMetadata::fromArray([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => ['https://auth.example.com'],
        ]);

        self::assertNull($metadata->scopesSupported);
        self::assertNull($metadata->bearerMethodsSupported);
        self::assertNull($metadata->resourceName);
    }

    public function testFromArrayCanonicalisesTheResource(): void
    {
        $metadata = ProtectedResourceMetadata::fromArray([
            'resource' => 'HTTPS://MCP.Example.COM/',
            'authorization_servers' => ['https://auth.example.com'],
        ]);

        self::assertSame('https://mcp.example.com', $metadata->resource->value);
    }

    public function testFromArrayKeepsAnEmptyScopeListDistinctFromAnAbsentOne(): void
    {
        $metadata = ProtectedResourceMetadata::fromArray([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => ['https://auth.example.com'],
            'scopes_supported' => [],
        ]);

        self::assertSame([], $metadata->scopesSupported?->values);
    }

    public function testFromArrayRejectsAnAbsentResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Protected Resource Metadata must carry a "resource" value.');

        ProtectedResourceMetadata::fromArray(['authorization_servers' => ['https://auth.example.com']]);
    }

    public function testFromArrayRejectsAnAbsentAuthorizationServersField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Protected Resource Metadata must name at least one authorization server.');

        ProtectedResourceMetadata::fromArray(['resource' => 'https://mcp.example.com/mcp']);
    }

    public function testFromArrayRejectsAnEmptyAuthorizationServersField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Protected Resource Metadata must name at least one authorization server.');

        ProtectedResourceMetadata::fromArray([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => [],
        ]);
    }

    public function testToArrayEmitsOnlyTheRequiredFieldsWhenNothingElseIsSet(): void
    {
        $metadata = new ProtectedResourceMetadata(
            new ResourceIdentifier('https://mcp.example.com/mcp'),
            ['https://auth.example.com'],
        );

        self::assertSame([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => ['https://auth.example.com'],
        ], $metadata->toArray());
    }

    public function testToArrayEmitsEveryPopulatedField(): void
    {
        $metadata = new ProtectedResourceMetadata(
            new ResourceIdentifier('https://mcp.example.com/mcp'),
            ['https://auth.example.com'],
            new ScopeSet(['files:read']),
            ['header'],
            'Example MCP Server',
        );

        self::assertSame([
            'resource' => 'https://mcp.example.com/mcp',
            'authorization_servers' => ['https://auth.example.com'],
            'scopes_supported' => ['files:read'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Example MCP Server',
        ], $metadata->toArray());
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $metadata = new ProtectedResourceMetadata(
            new ResourceIdentifier('https://mcp.example.com/mcp'),
            ['https://auth.example.com', 'https://auth2.example.com'],
            new ScopeSet(['files:read', 'files:write']),
            ['header'],
            'Example MCP Server',
        );

        self::assertSame($metadata->toArray(), ProtectedResourceMetadata::fromArray($metadata->toArray())->toArray());
    }
}
