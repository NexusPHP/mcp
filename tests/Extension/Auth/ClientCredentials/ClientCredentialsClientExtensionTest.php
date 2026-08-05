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

namespace Nexus\Mcp\Tests\Extension\Auth\ClientCredentials;

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsClientExtension;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(ClientCredentialsClientExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ClientCredentialsClientExtensionTest extends TestCase
{
    public function testDeclaresTheOfficialIdentifierWithEmptySettings(): void
    {
        $extension = new ClientCredentialsClientExtension();

        self::assertSame('io.modelcontextprotocol/oauth-client-credentials', $extension->getIdentifier());
        self::assertSame([], $extension->getSettings());
        self::assertSame([], $extension->getRequests());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getRequestHandlers());
        self::assertSame([], $extension->getNotificationHandlers());
        self::assertSame([], $extension->getOutboundRequests());
    }

    public function testStampsTheDeclarationOntoEveryRequest(): void
    {
        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new ClientCredentialsClientExtension())
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertStringContainsString(
            '"extensions":{"io.modelcontextprotocol/oauth-client-credentials":{}}',
            json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);
        $call->await();
        $transport->close();
    }
}
