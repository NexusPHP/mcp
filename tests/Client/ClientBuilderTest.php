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

namespace Nexus\Mcp\Tests\Client;

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(ClientBuilder::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientBuilderTest extends TestCase
{
    public function testBuildWithoutClientInfoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Client information must be set before build() via setClientInfo().');

        new ClientBuilder()->build();
    }

    public function testSetClientInfoIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setClientInfo('demo', '1.0.0');

        self::assertSame($builder, $returned);
    }

    public function testSetClientCapabilitiesIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setClientCapabilities(new ClientCapabilities());

        self::assertSame($builder, $returned);
    }

    public function testSetLoggerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setLogger(new ArrayLogger());

        self::assertSame($builder, $returned);
    }

    public function testSetRequestIdFactoryIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setRequestIdFactory(static fn(): int => 42);

        self::assertSame($builder, $returned);
    }

    public function testSetProgressTokenFactoryIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setProgressTokenFactory(static fn(): string => 'tok');

        self::assertSame($builder, $returned);
    }

    public function testAddRequestHandlerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->addRequestHandler('vendor/custom', new ClosureRequestHandler(static fn() => throw new \RuntimeException('not used')));

        self::assertSame($builder, $returned);
    }

    public function testAddNotificationHandlerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->addNotificationHandler(
            'notifications/cancelled',
            new ClosureNotificationHandler(static fn() => null),
        );

        self::assertSame($builder, $returned);
    }

    public function testBuildWiresRegisteredRequestHandlersIntoTheInboundDispatcher(): void
    {
        $marker = new EmptyResult();

        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->addRequestHandler('server/discover', new ClosureRequestHandler(static fn(): EmptyResult => $marker))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]);
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $response = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $response);
        self::assertSame(7, $response->id->id);
        self::assertSame($marker, $response->result);
    }

    public function testBuildDefaultsToIncrementingIntegerRequestIdFactoryWhenNoneIsSet(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);
        self::assertSame(1, $sentRequest->id->id, 'Default factory must start the counter at 1.');

        $transport->emitMessage(self::discoverResponse(1, ['tools' => []]));
        $deferred->await();

        // A second factory-minted id must increment, not reset to 1. Guards against an
        // arrow-function factory whose by-value counter capture never persists.
        $list = async(static fn() => $client->listTools());
        $transport->nextSend()->await();
        self::assertCount(2, $transport->sent);
        $listRequest = $transport->sent[1]['message'];
        self::assertInstanceOf(ListToolsRequest::class, $listRequest);
        self::assertSame(2, $listRequest->id->id, 'The factory must increment across calls.');

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $listRequest->id->id,
            'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);
        $list->await();
    }

    public function testBuildPropagatesTheCustomRequestIdFactoryToTheClient(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): string => $uuid)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);
        self::assertSame($uuid, $sentRequest->id->id);

        $transport->emitMessage(self::discoverResponse($uuid));
        $deferred->await();
    }

    public function testBuildDefaultsToIncrementingProgressTokenFactoryWhenNoneIsSet(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $handshake = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $discoverRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $discoverRequest);
        $transport->emitMessage(self::discoverResponse($discoverRequest->id->id, ['tools' => []]));
        $handshake->await();

        $onProgress = static function (float $progress, ?float $total, ?string $message): void {};

        $first = async(static fn() => $client->callTool('a', null, $onProgress));
        $transport->nextSend()->await();
        self::assertCount(2, $transport->sent);
        $firstRequest = $transport->sent[1]['message'];
        self::assertInstanceOf(CallToolRequest::class, $firstRequest);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $firstRequest->id->id,
            'result' => ['content' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);
        $first->await();

        $second = async(static fn() => $client->callTool('b', null, $onProgress));
        $transport->nextSend()->await();
        self::assertCount(3, $transport->sent);
        $secondRequest = $transport->sent[2]['message'];
        self::assertInstanceOf(CallToolRequest::class, $secondRequest);
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $secondRequest->id->id, 'result' => ['content' => []]]);
        $second->await();

        self::assertSame('progress-1', $firstRequest->params->meta->progressToken?->token);
        self::assertSame('progress-2', $secondRequest->params->meta->progressToken?->token);
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed>
     */
    private static function discoverResponse(int|string $id, array $capabilities = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'supportedVersions' => [ProtocolVersion::LATEST_VERSION],
                'capabilities' => $capabilities,
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ];
    }
}
