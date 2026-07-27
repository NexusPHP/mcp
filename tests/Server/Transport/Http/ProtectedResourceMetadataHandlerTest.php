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

namespace Nexus\Mcp\Tests\Server\Transport\Http;

use Nexus\Mcp\Server\Transport\Http\ProtectedResourceMetadataHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
#[CoversClass(ProtectedResourceMetadataHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ProtectedResourceMetadataHandlerTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.test/mcp';
    private const string ISSUER = 'https://auth.test';

    public function testItServesTheRequiredFields(): void
    {
        $response = self::handler()->handle(new Psr17Factory()->createServerRequest('GET', self::RESOURCE));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'resource' => self::RESOURCE,
            'authorization_servers' => [self::ISSUER],
            'bearer_methods_supported' => ['header'],
        ], self::readDocument($response));
    }

    public function testItServesTheOptionalFields(): void
    {
        $response = self::handler(['files:read', 'files:write'], 'Example MCP Server')
            ->handle(new Psr17Factory()->createServerRequest('GET', self::RESOURCE))
        ;

        self::assertSame([
            'resource' => self::RESOURCE,
            'authorization_servers' => [self::ISSUER],
            'scopes_supported' => ['files:read', 'files:write'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Example MCP Server',
        ], self::readDocument($response));
    }

    public function testItCanonicalisesTheResource(): void
    {
        $handler = new ProtectedResourceMetadataHandler(
            'HTTPS://MCP.Test/',
            [self::ISSUER],
            new Psr17Factory(),
            new Psr17Factory(),
        );

        $response = $handler->handle(new Psr17Factory()->createServerRequest('GET', self::RESOURCE));

        self::assertSame('https://mcp.test', self::readDocument($response)['resource'] ?? null);
    }

    public function testItLeavesSlashesUnescaped(): void
    {
        $response = self::handler()->handle(new Psr17Factory()->createServerRequest('GET', self::RESOURCE));

        self::assertStringContainsString('"resource":"https://mcp.test/mcp"', (string) $response->getBody());
    }

    #[DataProvider('provideARequestThatIsNotAGetIsRefusedCases')]
    public function testARequestThatIsNotAGetIsRefused(string $method): void
    {
        $response = self::handler()->handle(new Psr17Factory()->createServerRequest($method, self::RESOURCE));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame('', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideARequestThatIsNotAGetIsRefusedCases(): iterable
    {
        yield 'POST' => ['POST'];

        yield 'DELETE' => ['DELETE'];

        yield 'HEAD' => ['HEAD'];
    }

    /**
     * @param list<non-empty-string> $scopesSupported
     * @param ?non-empty-string      $resourceName
     */
    private static function handler(array $scopesSupported = [], ?string $resourceName = null): ProtectedResourceMetadataHandler
    {
        $factory = new Psr17Factory();

        return new ProtectedResourceMetadataHandler(
            self::RESOURCE,
            [self::ISSUER],
            $factory,
            $factory,
            $scopesSupported,
            $resourceName,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function readDocument(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }
}
