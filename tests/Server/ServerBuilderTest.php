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

namespace Nexus\Mcp\Tests\Server;

use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Server\Completion\RecordingCompletionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(ServerBuilder::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerBuilderTest extends TestCase
{
    public function testBuildFailsWithoutServerInfo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Server info must be set before build() via setServerInfo().');

        Server::builder()->build();
    }

    public function testSetServerInfoRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Implementation name must be a non-empty string.');

        Server::builder()->setServerInfo('', '1.0.0');
    }

    public function testSetServerInfoRejectsEmptyVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Implementation version must be a non-empty string.');

        Server::builder()->setServerInfo('demo', '');
    }

    public function testSetInstructionsRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Server instructions must be a non-empty string or null.');

        Server::builder()->setInstructions('');
    }

    public function testBuilderEntryPointsBothProduceServers(): void
    {
        $fromStatic = Server::builder()->setServerInfo('demo', '1.0.0')->build();
        $fromCtor = new ServerBuilder()->setServerInfo('demo', '1.0.0')->build();

        self::assertNotSame($fromStatic, $fromCtor);
    }

    public function testInitializeOnEmptyServerAdvertisesLoggingOnly(): void
    {
        $result = $this->initializeResultFor(Server::builder()->setServerInfo('demo', '1.0.0')->build());

        self::assertSame([], $result->capabilities->logging);
        self::assertNull($result->capabilities->tools);
        self::assertNull($result->capabilities->prompts);
        self::assertNull($result->capabilities->resources);
        self::assertNull($result->capabilities->completions);
    }

    public function testCapabilitiesIncludeToolsWhenToolRegistered(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool('echo', ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult([new TextContent('echo')]),
            )
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->tools);
    }

    public function testCapabilitiesIncludePromptsWhenPromptRegistered(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addPrompt(
                new Prompt('hello'),
                static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult([]),
            )
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->prompts);
    }

    public function testCapabilitiesIncludeResourcesWhenResourceRegistered(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource('cfg', 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult([new TextResourceContents($uri, 'data')]),
            )
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->resources);
    }

    public function testCapabilitiesIncludeResourcesWhenResourceTemplateRegistered(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addResourceTemplate(
                new ResourceTemplate('files', 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            )
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->resources);
    }

    public function testCapabilitiesIncludeCompletionsWhenCompletionStoreSet(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->setCompletionStore(new RecordingCompletionStore(new CompleteResult(['values' => []])))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->completions);
    }

    public function testReplacingBothToolsHandlersEnablesToolsCapability(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('tools/list', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->replaceRequestHandler('tools/call', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->tools);
    }

    /**
     * @param non-empty-string $onlyMethod
     */
    #[DataProvider('provideReplacingOnlyOneToolHandlerDoesNotAdvertiseToolsCapabilityCases')]
    public function testReplacingOnlyOneToolHandlerDoesNotAdvertiseToolsCapability(string $onlyMethod): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler($onlyMethod, new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertNull($result->capabilities->tools);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideReplacingOnlyOneToolHandlerDoesNotAdvertiseToolsCapabilityCases(): iterable
    {
        yield 'tools/call' => ['tools/call'];

        yield 'tools/list' => ['tools/list'];
    }

    public function testReplacingBothPromptsHandlersEnablesPromptsCapability(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('prompts/list', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->replaceRequestHandler('prompts/get', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->prompts);
    }

    /**
     * @param non-empty-string $onlyMethod
     */
    #[DataProvider('provideReplacingOnlyOnePromptHandlerDoesNotAdvertisePromptsCapabilityCases')]
    public function testReplacingOnlyOnePromptHandlerDoesNotAdvertisePromptsCapability(string $onlyMethod): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler($onlyMethod, new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertNull($result->capabilities->prompts);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideReplacingOnlyOnePromptHandlerDoesNotAdvertisePromptsCapabilityCases(): iterable
    {
        yield 'prompts/get' => ['prompts/get'];

        yield 'prompts/list' => ['prompts/list'];
    }

    public function testReplacingBothResourceHandlersEnablesResourcesCapability(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('resources/list', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->replaceRequestHandler('resources/read', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->resources);
    }

    /**
     * @param non-empty-string $onlyMethod
     */
    #[DataProvider('provideReplacingOnlyOneResourceHandlerDoesNotAdvertiseResourcesCapabilityCases')]
    public function testReplacingOnlyOneResourceHandlerDoesNotAdvertiseResourcesCapability(string $onlyMethod): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler($onlyMethod, new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertNull($result->capabilities->resources);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideReplacingOnlyOneResourceHandlerDoesNotAdvertiseResourcesCapabilityCases(): iterable
    {
        yield 'resources/list' => ['resources/list'];

        yield 'resources/read' => ['resources/read'];

        yield 'resources/templates/list (alone)' => ['resources/templates/list'];
    }

    public function testReplacingCompletionMethodEnablesCompletionsCapability(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('completion/complete', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame([], $result->capabilities->completions);
    }

    public function testInstructionsArePropagatedToInitializeResult(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->setInstructions('Greet the user warmly.')
            ->build()
        ;

        $result = $this->initializeResultFor($server);

        self::assertSame('Greet the user warmly.', $result->instructions);
    }

    public function testServerInfoMatchesNameAndVersion(): void
    {
        $server = Server::builder()->setServerInfo('demo-srv', '2.3.4')->build();

        $result = $this->initializeResultFor($server);

        self::assertSame('demo-srv', $result->serverInfo->name);
        self::assertSame('2.3.4', $result->serverInfo->version);
    }

    public function testRegisteredToolFlowsThroughBuiltServer(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool('echo', ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult([new TextContent('echo')]),
            )
            ->build()
        ;

        $result = $this->dispatchAfterInitialize($server, 'tools/list');

        self::assertInstanceOf(ListToolsResult::class, $result);
        self::assertCount(1, $result->tools);
        self::assertSame('echo', $result->tools[0]->name);
    }

    public function testRegisteredPromptFlowsThroughBuiltServer(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addPrompt(
                new Prompt('hello'),
                static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult([]),
            )
            ->build()
        ;

        $result = $this->dispatchAfterInitialize($server, 'prompts/list');

        self::assertInstanceOf(ListPromptsResult::class, $result);
        self::assertCount(1, $result->prompts);
        self::assertSame('hello', $result->prompts[0]->name);
    }

    public function testRegisteredResourceFlowsThroughBuiltServer(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource('cfg', 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult([new TextResourceContents($uri, 'data')]),
            )
            ->build()
        ;

        $result = $this->dispatchAfterInitialize($server, 'resources/list');

        self::assertInstanceOf(ListResourcesResult::class, $result);
        self::assertCount(1, $result->resources);
        self::assertSame('file:///etc/cfg', $result->resources[0]->uri);
    }

    public function testRegisteredResourceAndTemplateBothFlowThroughBuiltServer(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource('cfg', 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult([new TextResourceContents($uri, 'static')]),
            )
            ->addResourceTemplate(
                new ResourceTemplate('files', 'file:///{path}'),
                static fn(string $uri, array $bindings, $ctx): ReadResourceResult => new ReadResourceResult([new TextResourceContents($uri, 'templated:'.($bindings['path'] ?? 'missing'))]),
            )
            ->build()
        ;

        $listResult = $this->dispatchAfterInitialize($server, 'resources/list');
        self::assertInstanceOf(ListResourcesResult::class, $listResult);
        self::assertCount(1, $listResult->resources);

        $staticRead = $this->dispatchAfterInitialize($server, 'resources/read', ['uri' => 'file:///etc/cfg']);
        self::assertInstanceOf(ReadResourceResult::class, $staticRead);
        $staticEntry = $staticRead->contents[0] ?? null;

        if (! $staticEntry instanceof TextResourceContents) {
            self::fail('Expected first static read entry to be TextResourceContents.');
        }

        self::assertSame('static', $staticEntry->text);

        $templatedRead = $this->dispatchAfterInitialize($server, 'resources/read', ['uri' => 'file:///other']);
        self::assertInstanceOf(ReadResourceResult::class, $templatedRead);
        $templatedEntry = $templatedRead->contents[0] ?? null;

        if (! $templatedEntry instanceof TextResourceContents) {
            self::fail('Expected first templated read entry to be TextResourceContents.');
        }

        self::assertSame('templated:other', $templatedEntry->text);
    }

    public function testRegisteredResourceTemplateFlowsThroughBuiltServer(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addResourceTemplate(
                new ResourceTemplate('files', 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            )
            ->build()
        ;

        $result = $this->dispatchAfterInitialize($server, 'resources/templates/list');

        self::assertInstanceOf(ListResourceTemplatesResult::class, $result);
        self::assertCount(1, $result->resourceTemplates);
        self::assertSame('file:///{path}', $result->resourceTemplates[0]->uriTemplate);
    }

    public function testAddResourceTemplateRejectsUnsupportedTemplateAtRegistration(): void
    {
        $builder = Server::builder()->setServerInfo('demo', '1.0.0');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must use only RFC 6570 Level 1 simple-name expressions/');

        $builder->addResourceTemplate(
            new ResourceTemplate('files', 'file:///{+path}'),
            static fn(): never => throw new \LogicException('unreachable'),
        );
    }

    public function testReplaceRequestHandlerOverridesBuiltinAndIsDispatched(): void
    {
        $invoked = 0;
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('ping', new ClosureRequestHandler(
                static function () use (&$invoked): EmptyResult {
                    ++$invoked;

                    return new EmptyResult();
                },
            ))
            ->build()
        ;

        $this->dispatchAfterInitialize($server, 'ping');

        self::assertSame(1, $invoked);
    }

    public function testDefaultLoggingSetLevelHandlerReturnsEmptyResult(): void
    {
        $server = Server::builder()->setServerInfo('demo', '1.0.0')->build();

        $result = $this->dispatchAfterInitialize($server, 'logging/setLevel', ['level' => 'debug']);

        self::assertInstanceOf(EmptyResult::class, $result);
    }

    public function testLoggingSetLevelMutatesTheThresholdConsultedByContextLog(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool('emit', ['type' => 'object']),
                static function (?array $args, ServerContext $ctx): CallToolResult {
                    $ctx->log(LoggingLevel::Debug, 'debug-message');

                    return new CallToolResult([new TextContent('ok')]);
                },
            )
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        $initSent = $transport->nextSend();
        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                ],
            ]);
        });
        $initSent->await();

        $firstCallSent = $transport->nextSend();
        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'emit'],
            ]);
        });
        $firstCallSent->await();

        $setLevelSent = $transport->nextSend();
        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'logging/setLevel',
                'params' => ['level' => 'debug'],
            ]);
        });
        $setLevelSent->await();

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => ['name' => 'emit'],
            ]);
            $transport->close();
        });

        $serverRun->await();

        $debugLogsBefore = 0;
        $debugLogsAfter = 0;
        $crossedSetLevel = false;

        foreach ($transport->sent as $entry) {
            $msg = $entry['message'];

            if ($msg instanceof JsonRpcResultResponse && 3 === $msg->id->id) {
                $crossedSetLevel = true;

                continue;
            }

            if ($msg instanceof LoggingMessageNotification && LoggingLevel::Debug === $msg->params->level) {
                if ($crossedSetLevel) {
                    ++$debugLogsAfter;
                } else {
                    ++$debugLogsBefore;
                }
            }
        }

        self::assertSame(0, $debugLogsBefore, 'Debug log was emitted before setLevel(debug). The default Info threshold should have dropped it.');
        self::assertSame(1, $debugLogsAfter, 'Debug log was not emitted after setLevel(debug). The gate wired into ServerContext is not the one mutated by the handler.');
    }

    public function testAddRequestHandlerAcceptsVendorExtensionMethod(): void
    {
        $this->expectNotToPerformAssertions();

        Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addRequestHandler('acme/snapshot', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('provideAddRequestHandlerRejectsReservedSpecMethodCases')]
    public function testAddRequestHandlerRejectsReservedSpecMethod(string $method): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(
            'Request method "%s" is reserved by the MCP specification. Use replaceRequestHandler() to attach a handler to it.',
            $method,
        ));

        Server::builder()->addRequestHandler($method, new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAddRequestHandlerRejectsReservedSpecMethodCases(): iterable
    {
        yield 'completion/complete' => ['completion/complete'];

        yield 'elicitation/create' => ['elicitation/create'];

        yield 'initialize' => ['initialize'];

        yield 'logging/setLevel' => ['logging/setLevel'];

        yield 'ping' => ['ping'];

        yield 'prompts/get' => ['prompts/get'];

        yield 'prompts/list' => ['prompts/list'];

        yield 'resources/list' => ['resources/list'];

        yield 'resources/read' => ['resources/read'];

        yield 'resources/subscribe' => ['resources/subscribe'];

        yield 'resources/templates/list' => ['resources/templates/list'];

        yield 'resources/unsubscribe' => ['resources/unsubscribe'];

        yield 'roots/list' => ['roots/list'];

        yield 'sampling/createMessage' => ['sampling/createMessage'];

        yield 'tasks/cancel' => ['tasks/cancel'];

        yield 'tasks/get' => ['tasks/get'];

        yield 'tasks/list' => ['tasks/list'];

        yield 'tasks/result' => ['tasks/result'];

        yield 'tools/call' => ['tools/call'];

        yield 'tools/list' => ['tools/list'];
    }

    public function testAddNotificationHandlerAcceptsVendorExtensionMethod(): void
    {
        $this->expectNotToPerformAssertions();

        Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->addNotificationHandler('acme/snapshot-done', new ClosureNotificationHandler(
                static function (): void {},
            ))
            ->build()
        ;
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('provideAddNotificationHandlerRejectsReservedSpecMethodCases')]
    public function testAddNotificationHandlerRejectsReservedSpecMethod(string $method): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(
            'Notification method "%s" is reserved by the MCP specification. Use replaceNotificationHandler() to attach a handler to it.',
            $method,
        ));

        Server::builder()->addNotificationHandler($method, new ClosureNotificationHandler(
            static function (): void {},
        ));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAddNotificationHandlerRejectsReservedSpecMethodCases(): iterable
    {
        yield 'notifications/cancelled' => ['notifications/cancelled'];

        yield 'notifications/elicitation/complete' => ['notifications/elicitation/complete'];

        yield 'notifications/initialized' => ['notifications/initialized'];

        yield 'notifications/message' => ['notifications/message'];

        yield 'notifications/progress' => ['notifications/progress'];

        yield 'notifications/prompts/list_changed' => ['notifications/prompts/list_changed'];

        yield 'notifications/resources/list_changed' => ['notifications/resources/list_changed'];

        yield 'notifications/resources/updated' => ['notifications/resources/updated'];

        yield 'notifications/roots/list_changed' => ['notifications/roots/list_changed'];

        yield 'notifications/tasks/status' => ['notifications/tasks/status'];

        yield 'notifications/tools/list_changed' => ['notifications/tools/list_changed'];
    }

    public function testReplaceRequestHandlerRejectsVendorExtensionMethod(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Request method "acme/snapshot" is not reserved by the MCP specification. Use addRequestHandler() to register a vendor extension.',
        );

        Server::builder()->replaceRequestHandler('acme/snapshot', new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));
    }

    public function testReplaceNotificationHandlerRejectsVendorExtensionMethod(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Notification method "acme/snapshot-done" is not reserved by the MCP specification. Use addNotificationHandler() to register a vendor extension.',
        );

        Server::builder()->replaceNotificationHandler('acme/snapshot-done', new ClosureNotificationHandler(
            static function (): void {},
        ));
    }

    public function testCustomNotificationHandlerIsDispatched(): void
    {
        $invoked = 0;
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceNotificationHandler('notifications/cancelled', new ClosureNotificationHandler(
                static function () use (&$invoked): void { ++$invoked; },
            ))
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        $initializeSent = $transport->nextSend();

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                ],
            ]);
        });

        $initializeSent->await();

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ]);
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 1],
            ]);
            $transport->close();
        });

        $serverRun->await();

        self::assertSame(1, $invoked);
    }

    public function testCustomLoggerReceivesServerLogs(): void
    {
        $logger = new ArrayLogger();
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitError(new \RuntimeException('boom'));
            $transport->close();
        });

        $serverRun->await();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Transport error.');
        self::assertCount(1, $matches);
    }

    public function testRegisteredCompletionStoreFlowsThroughBuiltServer(): void
    {
        $server = Server::builder()
            ->setServerInfo('demo', '1.0.0')
            ->setCompletionStore(new RecordingCompletionStore(new CompleteResult(['values' => ['x']])))
            ->build()
        ;

        $result = $this->dispatchAfterInitialize($server, 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'hello'],
            'argument' => ['name' => 'arg', 'value' => 'partial'],
        ]);

        self::assertInstanceOf(CompleteResult::class, $result);
        self::assertSame(['x'], $result->completion['values']);
    }

    /**
     * Drives the constructed server with a synthetic `initialize` request and
     * returns the typed result captured off the recording transport.
     */
    private function initializeResultFor(Server $server): InitializeResult
    {
        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                ],
            ]);
            $transport->close();
        });

        $serverRun->await();

        self::assertNotEmpty($transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $message);
        self::assertInstanceOf(InitializeResult::class, $message->result);

        return $message->result;
    }

    /**
     * Drives the built server through a full `initialize` + `notifications/initialized`
     * + given operation cycle, returning the typed result of the operation response.
     *
     * @param array<string, mixed> $params
     */
    private function dispatchAfterInitialize(Server $server, string $method, array $params = []): Result
    {
        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        $initializeSent = $transport->nextSend();

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                ],
            ]);
        });

        $initializeSent->await();

        EventLoop::queue(static function () use ($transport, $method, $params): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ]);

            $envelope = ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method];

            if ([] !== $params) {
                $envelope['params'] = $params;
            }

            $transport->emitMessage($envelope);
            $transport->close();
        });

        $serverRun->await();

        $operationResponse = null;

        foreach ($transport->sent as $entry) {
            $msg = $entry['message'];

            if ($msg instanceof JsonRpcResultResponse && 2 === $msg->id->id) {
                $operationResponse = $msg;

                break;
            }
        }

        self::assertNotNull($operationResponse, 'No success response for the operation request was sent.');

        return $operationResponse->result;
    }
}
