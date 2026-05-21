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
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
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
        $this->expectExceptionMessage('Client information must be set before build() via setClientInfo().');

        new ClientBuilder()->build();
    }

    public function testSetClientInfoIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setClientInfo('demo', '1.0.0');

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

    public function testBuildDefaultsToIncrementingIntegerRequestIdFactoryWhenNoneIsSet(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $sentRequest);
        self::assertSame(1, $sentRequest->id->id, 'Default factory must start the counter at 1.');

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);
        $deferred->await();
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

        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $sentRequest);
        self::assertSame($uuid, $sentRequest->id->id);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $uuid,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);
        $deferred->await();
    }
}
