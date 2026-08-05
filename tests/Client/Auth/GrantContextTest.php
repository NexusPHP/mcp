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

use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\DiscoveredResource;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GrantContext::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class GrantContextTest extends TestCase
{
    public function testItCarriesEveryCollaborator(): void
    {
        $http = new RecordingHttpClient();
        $resource = new ResourceIdentifier('https://mcp.example.com/mcp');
        $discovered = new DiscoveredResource(
            new ProtectedResourceMetadata($resource, ['https://auth.example.com']),
            new AuthorizationServerMetadata('https://auth.example.com'),
        );
        $scopes = new ScopeSet(['files:read']);
        $options = new AuthorizationOptions('Example MCP Client');
        $registrar = new ClientRegistrar($http, new InMemoryClientRegistrationStore());
        $tokenEndpoint = new TokenEndpoint($http);
        $logger = new ArrayLogger();

        $context = new GrantContext($discovered, $resource, $scopes, $options, $registrar, $tokenEndpoint, $http, $logger);

        self::assertSame($discovered, $context->discovered);
        self::assertSame($resource, $context->resource);
        self::assertSame($scopes, $context->scopes);
        self::assertSame($options, $context->options);
        self::assertSame($registrar, $context->registrar);
        self::assertSame($tokenEndpoint, $context->tokenEndpoint);
        self::assertSame($http, $context->httpClient);
        self::assertSame($logger, $context->logger);
    }
}
