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

namespace Nexus\Mcp\Tests\Extension\Apps\Client;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Extension\Apps\Client\AppsClientExtension;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(AppsClientExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class AppsClientExtensionTest extends AbstractMcpTestCase
{
    public function testDeclaresTheOfficialIdentifierWithTheDefaultMimeType(): void
    {
        $extension = new AppsClientExtension();

        self::assertSame('io.modelcontextprotocol/ui', $extension->getIdentifier());
        self::assertSame(['mimeTypes' => ['text/html;profile=mcp-app']], $extension->getSettings());
        self::assertSame([], $extension->getRequests());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getRequestHandlers());
        self::assertSame([], $extension->getNotificationHandlers());
        self::assertSame([], $extension->getOutboundRequests());
    }

    public function testDeclaresCustomMimeTypes(): void
    {
        $extension = new AppsClientExtension(mimeTypes: ['text/html;profile=mcp-app', 'text/html']);

        self::assertSame(['mimeTypes' => ['text/html;profile=mcp-app', 'text/html']], $extension->getSettings());
    }

    public function testRejectsANonListMimeTypeArray(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"mimeTypes" must be a list, array given.');

        // @phpstan-ignore argument.type
        new AppsClientExtension(mimeTypes: ['html' => 'text/html;profile=mcp-app']);
    }

    public function testRejectsAnEmptyMimeTypeList(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"mimeTypes" must not be empty.');

        new AppsClientExtension(mimeTypes: []);
    }

    public function testRejectsAMalformedMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "mimeTypes" must be a non-empty string, string given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new AppsClientExtension(mimeTypes: ['']);
    }

    public function testStampsTheDeclarationOntoEveryRequest(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new AppsClientExtension())
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
            '"extensions":{"io.modelcontextprotocol/ui":{"mimeTypes":["text/html;profile=mcp-app"]}}',
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
