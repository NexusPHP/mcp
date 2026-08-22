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

use Amp\CancelledException;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Extension\StubClientExtension;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\DiscoverLookalikeRequest;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\ProgressLookalikeNotification;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestSecondNotification;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(ClientBuilder::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientBuilderTest extends AbstractMcpTestCase
{
    public function testBuildWithoutClientInfoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Client information must be set before build() via setClientInfo().');

        (new ClientBuilder())->build();
    }

    /**
     * @param \Closure(ClientBuilder): ClientBuilder $mutate
     */
    #[DataProvider('provideEveryRegistrationIsRefusedAfterBuildCases')]
    public function testEveryRegistrationIsRefusedAfterBuild(\Closure $mutate): void
    {
        $builder = (new ClientBuilder())->setClientInfo('demo', '1.0.0');
        $builder->build();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('This builder has already been built. Construct a new ClientBuilder for another client.');

        $mutate($builder);
    }

    /**
     * @return iterable<string, array{\Closure(ClientBuilder): ClientBuilder}>
     */
    public static function provideEveryRegistrationIsRefusedAfterBuildCases(): iterable
    {
        yield 'setClientInfo' => [static fn(ClientBuilder $b): ClientBuilder => $b->setClientInfo('x', '1.0.0')];

        yield 'setClientCapabilities' => [static fn(ClientBuilder $b): ClientBuilder => $b->setClientCapabilities(new ClientCapabilities())];

        yield 'setLogger' => [static fn(ClientBuilder $b): ClientBuilder => $b->setLogger(new ArrayLogger())];

        yield 'setRequestTimeout' => [static fn(ClientBuilder $b): ClientBuilder => $b->setRequestTimeout(1.0)];

        yield 'setMaxRequestTimeout' => [static fn(ClientBuilder $b): ClientBuilder => $b->setMaxRequestTimeout(1.0)];

        yield 'setRetryLostRequests' => [static fn(ClientBuilder $b): ClientBuilder => $b->setRetryLostRequests(true)];

        yield 'setMaxInFlightDispatches' => [static fn(ClientBuilder $b): ClientBuilder => $b->setMaxInFlightDispatches(2)];

        yield 'setRequestIdFactory' => [static fn(ClientBuilder $b): ClientBuilder => $b->setRequestIdFactory(static fn(): int => 1)];

        yield 'setProgressTokenFactory' => [static fn(ClientBuilder $b): ClientBuilder => $b->setProgressTokenFactory(static fn(): int => 1)];

        yield 'setMetaExtrasFactory' => [static fn(ClientBuilder $b): ClientBuilder => $b->setMetaExtrasFactory(static fn(): array => [])];

        yield 'addRequestHandler' => [static fn(ClientBuilder $b): ClientBuilder => $b->addRequestHandler(TestRequest::class, new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult()))];

        yield 'addNotificationHandler' => [static fn(ClientBuilder $b): ClientBuilder => $b->addNotificationHandler(ProgressNotification::class, new ClosureNotificationHandler(static function (): void {}))];

        yield 'enableExtension' => [static fn(ClientBuilder $b): ClientBuilder => $b->enableExtension(new StubClientExtension(identifier: 'com.example/feature'))];
    }

    public function testBuildingTwiceIsRefused(): void
    {
        $builder = (new ClientBuilder())->setClientInfo('demo', '1.0.0');
        $builder->build();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('This builder has already been built. Construct a new ClientBuilder for another client.');

        $builder->build();
    }

    public function testSetClientInfoIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setClientInfo('demo', '1.0.0');
        self::assertSame($builder, $returned);
    }

    public function testSetClientInfoRefusesANonConservativeIconSrc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('clientInfo "icons.src" must be an HTTP/HTTPS URL or a data: URI with base64-encoded data, \'ftp://example.com/icon.png\' given.');

        (new ClientBuilder())->setClientInfo('demo', '1.0.0', icons: [new Icon(src: 'ftp://example.com/icon.png')]);
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

    public function testSetRequestTimeoutIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setRequestTimeout(30.0);

        self::assertSame($builder, $returned);
    }

    public function testSetMaxRequestTimeoutIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setMaxRequestTimeout(30.0);

        self::assertSame($builder, $returned);
    }

    public function testSetRetryLostRequestsIsFluent(): void
    {
        $builder = new ClientBuilder();

        self::assertSame($builder, $builder->setRetryLostRequests(true));
        self::assertSame($builder, $builder->setRetryLostRequests(false));
    }

    #[DataProvider('provideRejectsANonPositiveRequestTimeoutCases')]
    public function testRejectsANonPositiveRequestTimeout(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^The request timeout must be positive or null, /');

        (new ClientBuilder())->setRequestTimeout($seconds);
    }

    #[DataProvider('provideRejectsANonPositiveRequestTimeoutCases')]
    public function testRejectsANonPositiveMaxRequestTimeout(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^The maximum request timeout must be positive or null, /');

        (new ClientBuilder())->setMaxRequestTimeout($seconds);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideRejectsANonPositiveRequestTimeoutCases(): iterable
    {
        yield 'zero' => [0.0];

        yield 'negative' => [-1.0];
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

    public function testSetMetaExtrasFactoryIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setMetaExtrasFactory(static fn(): array => []);

        self::assertSame($builder, $returned);
    }

    public function testStampMetaCarriesNoExtrasWhenNoFactoryIsSet(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        self::assertSame([], $client->stampMeta()->extras);
    }

    public function testStampMetaCallsTheMetaExtrasFactoryOncePerRequest(): void
    {
        $calls = 0;
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setMetaExtrasFactory(static function () use (&$calls): array {
                return ['traceparent' => \sprintf('00-%032d-%016d-01', ++$calls, $calls)];
            })
            ->build()
        ;

        $first = $client->stampMeta();
        $second = $client->stampMeta();

        self::assertSame(['traceparent' => \sprintf('00-%032d-%016d-01', 1, 1)], $first->extras);
        self::assertSame(['traceparent' => \sprintf('00-%032d-%016d-01', 2, 2)], $second->extras);
        self::assertStringContainsString(
            '"traceparent":"00-00000000000000000000000000000001-0000000000000001-01"',
            json_encode($first, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testStampMetaDropsEveryLifecycleKeyTheMetaExtrasFactoryReturns(): void
    {
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-a1b2c3d4e5f60718-01';
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setMetaExtrasFactory(static fn(): array => [
                RequestMetaObject::PROTOCOL_VERSION_KEY => 'bogus',
                RequestMetaObject::CLIENT_INFO_KEY => 'bogus',
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => 'bogus',
                RequestMetaObject::LOG_LEVEL_KEY => 'bogus',
                'progressToken' => 'bogus',
                'traceparent' => $traceparent,
            ])
            ->build()
        ;

        $stamped = $client->stampMeta();
        $meta = $stamped->toArray();

        self::assertSame(['traceparent' => $traceparent], $stamped->extras);
        self::assertArrayNotHasKey(RequestMetaObject::LOG_LEVEL_KEY, $meta);
        self::assertArrayNotHasKey('progressToken', $meta);
        self::assertArrayHasKey(RequestMetaObject::PROTOCOL_VERSION_KEY, $meta);
        self::assertArrayHasKey(RequestMetaObject::CLIENT_INFO_KEY, $meta);
        self::assertArrayHasKey(RequestMetaObject::CLIENT_CAPABILITIES_KEY, $meta);
        self::assertSame(ProtocolVersion::LATEST_VERSION, $meta[RequestMetaObject::PROTOCOL_VERSION_KEY]);
        self::assertSame(['name' => 'demo', 'version' => '1.0.0'], $meta[RequestMetaObject::CLIENT_INFO_KEY]);
        self::assertSame([], $meta[RequestMetaObject::CLIENT_CAPABILITIES_KEY]);
    }

    public function testStampMetaKeepsTheProgressTokenOverTheMetaExtrasFactory(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setMetaExtrasFactory(static fn(): array => ['progressToken' => 'bogus'])
            ->build()
        ;

        $meta = $client->stampMeta(new ProgressToken('tok'))->toArray();

        self::assertArrayHasKey('progressToken', $meta);
        self::assertSame('tok', $meta['progressToken']);
    }

    public function testAddRequestHandlerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->addRequestHandler(TestRequest::class, new ClosureRequestHandler(static fn() => throw new \RuntimeException('not used')));

        self::assertSame($builder, $returned);
    }

    public function testAddRequestHandlerKeepsTheRegistryClassForASpecMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Request method "server/discover" is defined by the MCP specification and keeps its registry envelope class, \'Nexus\\\\Mcp\\\\Tests\\\\Fixtures\\\\Core\\\\DiscoverLookalikeRequest\' given.',
        );

        (new ClientBuilder())->addRequestHandler(DiscoverLookalikeRequest::class, new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));
    }

    public function testAddNotificationHandlerKeepsTheRegistryClassForASpecMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Notification method "notifications/progress" is defined by the MCP specification and keeps its registry envelope class, \'Nexus\\\\Mcp\\\\Tests\\\\Fixtures\\\\Core\\\\ProgressLookalikeNotification\' given.',
        );

        (new ClientBuilder())->addNotificationHandler(ProgressLookalikeNotification::class, new ClosureNotificationHandler(
            static function (): void {},
        ));
    }

    public function testEnableExtensionAdvertisesTheCapabilityOnTheStampedMeta(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(identifier: 'com.example/feature'))
            ->build()
        ;

        $meta = $client->stampMeta();

        self::assertSame(['com.example/feature' => []], $meta->clientCapabilities->extensions);
        self::assertStringContainsString(
            '"extensions":{"com.example/feature":{}}',
            json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testEnableExtensionMergesOverManuallySetCapabilitiesInEitherOrder(): void
    {
        $expected = [
            'com.example/manual' => ['mode' => 'safe'],
            'com.example/feature' => [],
        ];

        $enableFirst = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(identifier: 'com.example/feature'))
            ->setClientCapabilities(new ClientCapabilities(extensions: ['com.example/manual' => ['mode' => 'safe']]))
            ->build()
        ;
        $setFirst = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setClientCapabilities(new ClientCapabilities(extensions: ['com.example/manual' => ['mode' => 'safe']]))
            ->enableExtension(new StubClientExtension(identifier: 'com.example/feature'))
            ->build()
        ;

        self::assertSame($expected, $enableFirst->stampMeta()->clientCapabilities->extensions);
        self::assertSame($expected, $setFirst->stampMeta()->clientCapabilities->extensions);
    }

    public function testBuildRejectsAnIdentifierDeclaredManuallyAndEnabled(): void
    {
        $builder = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setClientCapabilities(new ClientCapabilities(extensions: ['com.example/feature' => []]))
            ->enableExtension(new StubClientExtension(identifier: 'com.example/feature'))
        ;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" is declared more than once.');

        $builder->build();
    }

    public function testEnableExtensionRejectsAnOutboundSpecMethod(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the request method "tools/call" already owned by the MCP specification.',
        );

        (new ClientBuilder())->enableExtension(new StubClientExtension(
            identifier: 'com.example/feature',
            outboundRequests: ['tools/call'],
        ));
    }

    public function testEnableExtensionRejectsAnOutboundMethodAnotherExtensionOwns(): void
    {
        $builder = (new ClientBuilder())->enableExtension(new StubClientExtension(
            identifier: 'com.example/feature',
            outboundRequests: ['acme/lookup'],
        ));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/other" cannot claim the request method "acme/lookup" already owned by extension "com.example/feature".',
        );

        $builder->enableExtension(new StubClientExtension(
            identifier: 'com.example/other',
            outboundRequests: ['acme/lookup'],
        ));
    }

    public function testEnableExtensionRejectsAnInboundMethodABuilderHandlerOwns(): void
    {
        $builder = (new ClientBuilder())->addRequestHandler(TestRequest::class, new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the request method "tests/test-request" already owned by a builder-registered handler.',
        );

        $builder->enableExtension(new StubClientExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            )],
        ));
    }

    public function testEnableExtensionRejectsANotificationMethodABuilderHandlerOwns(): void
    {
        $builder = (new ClientBuilder())->addNotificationHandler(TestNotification::class, new ClosureNotificationHandler(
            static function (): void {},
        ));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the notification method "tests/test-notification" already owned by a builder-registered handler.',
        );

        $builder->enableExtension(new StubClientExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => new ClosureNotificationHandler(
                static function (): void {},
            )],
        ));
    }

    public function testAddRequestHandlerRejectsAMethodAnExtensionOwns(): void
    {
        $builder = (new ClientBuilder())->enableExtension(new StubClientExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            )],
        ));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'A builder-registered handler cannot claim the request method "tests/test-request" already owned by extension "com.example/feature".',
        );

        $builder->addRequestHandler(TestRequest::class, new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));
    }

    public function testAddNotificationHandlerRejectsAMethodAnExtensionOwns(): void
    {
        $builder = (new ClientBuilder())->enableExtension(new StubClientExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => new ClosureNotificationHandler(
                static function (): void {},
            )],
        ));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'A builder-registered handler cannot claim the notification method "tests/test-notification" already owned by extension "com.example/feature".',
        );

        $builder->addNotificationHandler(TestNotification::class, new ClosureNotificationHandler(
            static function (): void {},
        ));
    }

    public function testSetMaxInFlightDispatchesRejectsANonPositiveCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Maximum in-flight dispatches must be a positive integer or null, 0 given.');

        (new ClientBuilder())->setMaxInFlightDispatches(0);
    }

    public function testTheDefaultInFlightCapAdmitsExactlyItsBoundaryAndShedsTheNext(): void
    {
        $transport = new RecordingTransport();
        $this->connectServableClient(new ClientBuilder(), $transport);

        for ($id = 1; $id <= 1_024; ++$id) {
            $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $id, 'method' => TestRequest::getMethod()]);
        }

        self::assertCount(0, $transport->sent, 'Nothing up to the cap may be shed.');

        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 1_025, 'method' => TestRequest::getMethod()]);

        self::assertCount(1, $transport->sent);
        $shed = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $shed);
        self::assertSame(1_025, $shed->id?->id);
        self::assertSame(SdkErrorCode::Overloaded->value, $shed->error->code);

        EventLoop::run();
    }

    public function testANullInFlightCapLiftsTheDefault(): void
    {
        $transport = new RecordingTransport();
        $this->connectServableClient((new ClientBuilder())->setMaxInFlightDispatches(null), $transport);

        for ($id = 1; $id <= 1_025; ++$id) {
            $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $id, 'method' => TestRequest::getMethod()]);
        }

        self::assertCount(0, $transport->sent, 'A lifted cap must shed nothing.');

        EventLoop::run();
    }

    public function testExtensionRequestHandlersServeInboundRequests(): void
    {
        $marker = new EmptyResult();
        $secondMarker = new EmptyResult();

        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                requests: [TestRequest::class],
                requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(static fn(): EmptyResult => $marker)],
            ))
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/other',
                requests: [TestClientRequest::class],
                requestHandlers: [TestClientRequest::getMethod() => new ClosureRequestHandler(static fn(): EmptyResult => $secondMarker)],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => TestRequest::getMethod(),
        ]);
        $transport->nextSend()->await();

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => TestClientRequest::getMethod(),
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]);
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $first = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $first);
        self::assertSame(11, $first->id->id);
        self::assertSame($marker, $first->result);
        $second = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $second);
        self::assertSame(12, $second->id->id);
        self::assertSame($secondMarker, $second->result);
    }

    public function testExtensionNotificationHandlersReceiveTheirNotifications(): void
    {
        $received = [];

        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                notifications: [TestNotification::class],
                notificationHandlers: [TestNotification::getMethod() => new ClosureNotificationHandler(
                    static function () use (&$received): void {
                        $received[] = 'feature';
                    },
                )],
            ))
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/other',
                notifications: [TestSecondNotification::class],
                notificationHandlers: [TestSecondNotification::getMethod() => new ClosureNotificationHandler(
                    static function () use (&$received): void {
                        $received[] = 'other';
                    },
                )],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => TestNotification::getMethod(),
        ]);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => TestSecondNotification::getMethod(),
        ]);
        $client->disconnect();

        self::assertSame(['feature', 'other'], $received);
    }

    public function testAddNotificationHandlerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->addNotificationHandler(
            CancelledNotification::class,
            new ClosureNotificationHandler(static function (): void {}),
        );

        self::assertSame($builder, $returned);
    }

    public function testBuildWiresRegisteredRequestHandlersIntoTheInboundDispatcher(): void
    {
        $marker = new EmptyResult();

        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->addRequestHandler(DiscoverRequest::class, new ClosureRequestHandler(static fn(): EmptyResult => $marker))
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

    public function testBuildParsesAndDispatchesAVendorRequestMethod(): void
    {
        $marker = new EmptyResult();

        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->addRequestHandler(TestRequest::class, new ClosureRequestHandler(static fn(): EmptyResult => $marker))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => TestRequest::getMethod(),
        ]);
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $response = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $response);
        self::assertSame(9, $response->id->id);
        self::assertSame($marker, $response->result);
    }

    public function testBuildParsesAndDispatchesAVendorNotificationMethod(): void
    {
        $received = 0;

        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->addNotificationHandler(TestNotification::class, new ClosureNotificationHandler(
                static function () use (&$received): void {
                    ++$received;
                },
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => TestNotification::getMethod(),
        ]);
        $client->disconnect();

        self::assertSame(1, $received);
    }

    public function testAnInboundCancellationStopsARequestTheClientIsServing(): void
    {
        $seen = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->addRequestHandler(DiscoverRequest::class, new ClosureRequestHandler(
                static function ($request, AbstractContext $context) use (&$seen): EmptyResult {
                    try {
                        delay(1.0, cancellation: $context->cancellation);
                    } catch (CancelledException) {
                        $seen[] = 'cancelled';
                    }

                    return new EmptyResult();
                },
            ))
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
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 7],
        ]);
        $client->disconnect();

        self::assertSame(['cancelled'], $seen, 'A peer cancelling a request the client serves must reach the handler.');
        self::assertSame([], $transport->sent, 'The spec forbids answering a request once its cancellation was requested.');
    }

    public function testACustomCancelledNotificationHandlerReplacesTheBuiltInOne(): void
    {
        $seen = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->addNotificationHandler(CancelledNotification::class, new ClosureNotificationHandler(
                static function () use (&$seen): void {
                    $seen[] = 'custom';
                },
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 7],
        ]);
        $client->disconnect();

        self::assertSame(['custom'], $seen);
    }

    public function testBuildDefaultsToIncrementingIntegerRequestIdFactoryWhenNoneIsSet(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);
        self::assertSame(1, $sentRequest->id->id, 'Default factory must start the counter at 1.');

        $transport->emitMessage($this->discoverResponse(1, ['tools' => []]));
        $deferred->await();

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

        $client = (new ClientBuilder())
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

        $transport->emitMessage($this->discoverResponse($uuid));
        $deferred->await();
    }

    public function testBuildDefaultsToIncrementingProgressTokenFactoryWhenNoneIsSet(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $handshake = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $discoverRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $discoverRequest);
        $transport->emitMessage($this->discoverResponse($discoverRequest->id->id, ['tools' => []]));
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
     * Connects a client that serves `TestRequest`, so an inbound one occupies a dispatch slot.
     */
    private function connectServableClient(ClientBuilder $builder, RecordingTransport $transport): void
    {
        $builder
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                requests: [TestRequest::class],
                requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
            ))
            ->build()
            ->connect($transport)
        ;
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed>
     */
    private function discoverResponse(int|string $id, array $capabilities = []): array
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
