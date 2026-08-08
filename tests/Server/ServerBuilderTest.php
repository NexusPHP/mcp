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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Exception\DuplicateExtensionException;
use Nexus\Mcp\Core\Exception\ExtensionMethodCollisionException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Completion\CompletionProviderInterface;
use Nexus\Mcp\Server\Completion\CompletionStore;
use Nexus\Mcp\Server\Exception\BuilderAlreadyBuiltException;
use Nexus\Mcp\Server\Exception\DuplicateServerMetadataException;
use Nexus\Mcp\Server\Exception\MissingDiscoveryAttributeException;
use Nexus\Mcp\Server\Exception\ReservedMethodException;
use Nexus\Mcp\Server\Exception\UnreservedMethodException;
use Nexus\Mcp\Server\ListChangeSourceInterface;
use Nexus\Mcp\Server\Prompt\MutablePromptStoreInterface;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\Prompt\PromptStoreInterface;
use Nexus\Mcp\Server\Resource\MutableResourceStoreInterface;
use Nexus\Mcp\Server\Resource\MutableResourceTemplateStoreInterface;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\Resource\ResourceStoreInterface;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\Resource\ResourceTemplateStoreInterface;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\ServerInfoDisclosure;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\MutableToolStoreInterface;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestSecondClientRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestSecondNotification;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Server\Completion\RecordingCompletionStore;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\CompletionHandlers;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\DiscoverableServer;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SelfDescribingServer;
use Nexus\Mcp\Tests\Fixtures\Server\Extension\StubDecoratingServerExtension;
use Nexus\Mcp\Tests\Fixtures\Server\Extension\StubServerExtension;
use Nexus\Mcp\Tests\Fixtures\Server\Tool\PagedToolStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(ServerBuilder::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerBuilderTest extends AbstractMcpTestCase
{
    public function testBuildFailsWithoutServerInfo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Server information must be set before build() via setServerInfo() or a class-level #[AsServer].');

        (new ServerBuilder())->build();
    }

    public function testSetServerInfoRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Implementation name must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        (new ServerBuilder())->setServerInfo('', '1.0.0');
    }

    public function testSetServerInfoRejectsEmptyVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"version" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        (new ServerBuilder())->setServerInfo('demo', '');
    }

    public function testSetInstructionsRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Server instructions must be a non-empty string or null.');

        (new ServerBuilder())->setInstructions('');
    }

    public function testDiscoverOnEmptyServerAdvertisesNoCapabilities(): void
    {
        $result = $this->discoverResultFor((new ServerBuilder())->setServerInfo('demo', '1.0.0')->build());

        self::assertNull($result->capabilities->tools);
        self::assertNull($result->capabilities->prompts);
        self::assertNull($result->capabilities->resources);
        self::assertNull($result->capabilities->completions);
        self::assertSame([], $result->capabilities->toArray());
    }

    public function testCapabilitiesIncludeToolsWhenToolRegistered(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'echo')]),
            )
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->tools);
    }

    public function testCapabilitiesIncludePromptsWhenPromptRegistered(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addPrompt(
                new Prompt(name: 'hello'),
                static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult(messages: []),
            )
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->prompts);
    }

    public function testCapabilitiesIncludeResourcesWhenResourceRegistered(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource(name: 'cfg', uri: 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'data')], ttlMs: 0, cacheScope: CacheScope::Private),
            )
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->resources);
    }

    public function testCapabilitiesIncludeResourcesWhenResourceTemplateRegistered(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResourceTemplate(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            )
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->resources);
    }

    public function testCapabilitiesIncludeCompletionsWhenCompletionStoreSet(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setCompletionStore(new RecordingCompletionStore(new CompleteResult(completion: ['values' => []])))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->completions);
    }

    public function testReplacingBothToolsHandlersEnablesToolsCapability(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('tools/list', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->replaceRequestHandler('tools/call', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->tools);
    }

    /**
     * @param non-empty-string $onlyMethod
     */
    #[DataProvider('provideReplacingOnlyOneToolHandlerDoesNotAdvertiseToolsCapabilityCases')]
    public function testReplacingOnlyOneToolHandlerDoesNotAdvertiseToolsCapability(string $onlyMethod): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler($onlyMethod, new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

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
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('prompts/list', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->replaceRequestHandler('prompts/get', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->prompts);
    }

    /**
     * @param non-empty-string $onlyMethod
     */
    #[DataProvider('provideReplacingOnlyOnePromptHandlerDoesNotAdvertisePromptsCapabilityCases')]
    public function testReplacingOnlyOnePromptHandlerDoesNotAdvertisePromptsCapability(string $onlyMethod): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler($onlyMethod, new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

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
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('resources/list', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->replaceRequestHandler('resources/read', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->resources);
    }

    /**
     * @param non-empty-string $onlyMethod
     */
    #[DataProvider('provideReplacingOnlyOneResourceHandlerDoesNotAdvertiseResourcesCapabilityCases')]
    public function testReplacingOnlyOneResourceHandlerDoesNotAdvertiseResourcesCapability(string $onlyMethod): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler($onlyMethod, new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

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
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('completion/complete', new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->completions);
    }

    public function testInstructionsArePropagatedToDiscoverResult(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setInstructions('Greet the user warmly.')
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame('Greet the user warmly.', $result->instructions);
    }

    public function testServerInfoMatchesNameAndVersion(): void
    {
        $server = (new ServerBuilder())->setServerInfo('demo-srv', '2.3.4')->build();

        $info = $this->serverInfoFor($server);

        self::assertSame('demo-srv', $info->name);
        self::assertSame('2.3.4', $info->version);
    }

    public function testServerInfoRidesTheMetaOfAResultOtherThanDiscover(): void
    {
        // The spec asks for the identity on every response, not only on the discovery probe.
        $server = (new ServerBuilder())
            ->setServerInfo('demo-srv', '2.3.4')
            ->addTool(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'echo')]),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/list');

        self::assertSame('demo-srv', $result->meta->serverInfo?->name);
    }

    public function testSetMaxInFlightDispatchesRejectsANonPositiveCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Maximum in-flight dispatches must be a positive integer or null, 0 given.');

        (new ServerBuilder())->setMaxInFlightDispatches(0);
    }

    public function testTheInFlightCapReachesTheDispatcher(): void
    {
        // `listen()` attaches without awaiting close, so both envelopes are dispatched before the
        // loop turns and the second one meets a saturated dispatcher.
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setMaxInFlightDispatches(1)
            ->build()
        ;

        $transport = new RecordingTransport();
        $server->listen($transport);

        $transport->emitMessage(self::discoverEnvelope(1));
        $transport->emitMessage(self::discoverEnvelope(2));

        EventLoop::run();

        self::assertCount(2, $transport->sent);
        $shed = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $shed);
        self::assertSame(SdkErrorCode::Overloaded->value, $shed->error->code);
    }

    public function testDisclosingNoServerInfoLeavesTheIdentityOffEveryResult(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo-srv', '2.3.4')
            ->setServerInfoDisclosure(ServerInfoDisclosure::None)
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertNull($result->meta->serverInfo);
        self::assertArrayNotHasKey('_meta', $result->toArray());
    }

    public function testServerInfoDisclosureIsFullByDefaultAndResettable(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo-srv', '2.3.4')
            ->setServerInfoDisclosure(ServerInfoDisclosure::None)
            ->setServerInfoDisclosure(ServerInfoDisclosure::Full)
            ->build()
        ;

        self::assertSame('demo-srv', $this->serverInfoFor($server)->name);
    }

    public function testNameAndVersionDisclosureTrimsTheIdentityOnResultsOtherThanDiscover(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo-srv', '2.3.4', title: 'Demo', description: 'A demo.', websiteUrl: 'https://example.com')
            ->setServerInfoDisclosure(ServerInfoDisclosure::NameAndVersion)
            ->addTool(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'echo')]),
            )
            ->build()
        ;

        self::assertSame(
            ['name' => 'demo-srv', 'version' => '2.3.4'],
            $this->dispatch($server, 'tools/list')->meta->serverInfo?->toArray(),
        );
    }

    public function testDiscoverCarriesTheFullIdentityEvenWhenOtherResultsAreTrimmed(): void
    {
        // The rich fields are display material a client collects once, at discovery.
        $server = (new ServerBuilder())
            ->setServerInfo('demo-srv', '2.3.4', title: 'Demo', description: 'A demo.')
            ->setServerInfoDisclosure(ServerInfoDisclosure::NameAndVersion)
            ->build()
        ;

        $info = $this->serverInfoFor($server);

        self::assertSame('Demo', $info->title);
        self::assertSame('A demo.', $info->description);
    }

    public function testRegisteredToolFlowsThroughBuiltServer(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'echo')]),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/list');

        self::assertInstanceOf(ListToolsResult::class, $result);
        self::assertCount(1, $result->tools);
        self::assertSame('echo', $result->tools[0]->name);
    }

    public function testCustomSchemaValidatorReplacesTheDefault(): void
    {
        $alwaysValid = new class implements SchemaValidatorInterface {
            #[\Override]
            public function validate(mixed $data, array $schema): array
            {
                return [];
            }
        };

        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setSchemaValidator($alwaysValid)
            ->addTool(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: [
                    'type' => 'object',
                    'properties' => ['n' => ['type' => 'integer']],
                    'required' => ['n'],
                ]),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [], structuredContent: ['n' => 'not-an-int']),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/call', ['name' => 'report']);

        // The default opis validator would reject the non-integer n and yield a generic error result.
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertNull($result->isError);
        self::assertSame(['n' => 'not-an-int'], $result->structuredContent);
    }

    public function testPageSizeReachesEveryBuilderAssembledStore(): void
    {
        $server = self::registerFeatureTriples((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
            ->setPageSize(2)
            ->build()
        ;

        $tools = $this->dispatch($server, 'tools/list');
        self::assertInstanceOf(ListToolsResult::class, $tools);
        self::assertCount(2, $tools->tools);
        self::assertNotNull($tools->nextCursor);

        $prompts = $this->dispatch($server, 'prompts/list');
        self::assertInstanceOf(ListPromptsResult::class, $prompts);
        self::assertCount(2, $prompts->prompts);
        self::assertNotNull($prompts->nextCursor);

        $resources = $this->dispatch($server, 'resources/list');
        self::assertInstanceOf(ListResourcesResult::class, $resources);
        self::assertCount(2, $resources->resources);
        self::assertNotNull($resources->nextCursor);

        $templates = $this->dispatch($server, 'resources/templates/list');
        self::assertInstanceOf(ListResourceTemplatesResult::class, $templates);
        self::assertCount(2, $templates->resourceTemplates);
        self::assertNotNull($templates->nextCursor);
    }

    public function testCacheHintsReachEveryBuilderAssembledStore(): void
    {
        $server = self::registerFeatureTriples((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
            ->setTtlMs(60_000)
            ->setCacheScope(CacheScope::Public)
            ->build()
        ;

        foreach (['tools/list', 'prompts/list', 'resources/list', 'resources/templates/list'] as $method) {
            $result = $this->dispatch($server, $method);

            if (! $result instanceof CacheableResult) {
                self::fail(\sprintf('"%s" did not return a cacheable result.', $method));
            }

            self::assertSame(60_000, $result->ttlMs, \sprintf('"%s" did not carry the builder TTL.', $method));
            self::assertSame(CacheScope::Public, $result->cacheScope, \sprintf('"%s" did not carry the builder cache scope.', $method));
        }
    }

    #[DataProvider('provideSetPageSizeRejectsANonPositiveSizeCases')]
    public function testSetPageSizeRejectsANonPositiveSize(int $pageSize): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Store page size must be a positive integer, %d given.', $pageSize));

        (new ServerBuilder())->setPageSize($pageSize);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideSetPageSizeRejectsANonPositiveSizeCases(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }

    public function testSetTtlMsRejectsANegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Store TTL must be a non-negative integer, -1 given.');

        (new ServerBuilder())->setTtlMs(-1);
    }

    public function testRegisteredPromptFlowsThroughBuiltServer(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addPrompt(
                new Prompt(name: 'hello'),
                static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult(messages: []),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'prompts/list');

        self::assertInstanceOf(ListPromptsResult::class, $result);
        self::assertCount(1, $result->prompts);
        self::assertSame('hello', $result->prompts[0]->name);
    }

    public function testRegisteredResourceFlowsThroughBuiltServer(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource(name: 'cfg', uri: 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'data')], ttlMs: 0, cacheScope: CacheScope::Private),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'resources/list');

        self::assertInstanceOf(ListResourcesResult::class, $result);
        self::assertCount(1, $result->resources);
        self::assertSame('file:///etc/cfg', $result->resources[0]->uri);
    }

    public function testRegisteredResourceAndTemplateBothFlowThroughBuiltServer(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource(name: 'cfg', uri: 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'static')], ttlMs: 0, cacheScope: CacheScope::Private),
            )
            ->addResourceTemplate(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(string $uri, array $bindings, $ctx): ReadResourceResult => new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'templated:'.($bindings['path'] ?? 'missing'))], ttlMs: 0, cacheScope: CacheScope::Private),
            )
            ->build()
        ;

        $listResult = $this->dispatch($server, 'resources/list');
        self::assertInstanceOf(ListResourcesResult::class, $listResult);
        self::assertCount(1, $listResult->resources);

        $staticRead = $this->dispatch($server, 'resources/read', ['uri' => 'file:///etc/cfg']);
        self::assertInstanceOf(ReadResourceResult::class, $staticRead);
        $staticEntry = $staticRead->contents[0] ?? null;

        if (! $staticEntry instanceof TextResourceContents) {
            self::fail('Expected first static read entry to be TextResourceContents.');
        }

        self::assertSame('static', $staticEntry->text);

        $templatedRead = $this->dispatch($server, 'resources/read', ['uri' => 'file:///other']);
        self::assertInstanceOf(ReadResourceResult::class, $templatedRead);
        $templatedEntry = $templatedRead->contents[0] ?? null;

        if (! $templatedEntry instanceof TextResourceContents) {
            self::fail('Expected first templated read entry to be TextResourceContents.');
        }

        self::assertSame('templated:other', $templatedEntry->text);
    }

    public function testRegisteredResourceTemplateFlowsThroughBuiltServer(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResourceTemplate(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'resources/templates/list');

        self::assertInstanceOf(ListResourceTemplatesResult::class, $result);
        self::assertCount(1, $result->resourceTemplates);
        self::assertSame('file:///{path}', $result->resourceTemplates[0]->uriTemplate);
    }

    public function testTemplateOnlyServerStillServesResourceRead(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResourceTemplate(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(string $uri, array $bindings, $ctx): ReadResourceResult => new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'templated:'.($bindings['path'] ?? 'missing'))], ttlMs: 0, cacheScope: CacheScope::Private),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'resources/read', ['uri' => 'file:///etc']);

        self::assertInstanceOf(ReadResourceResult::class, $result);
        $entry = $result->contents[0] ?? null;

        if (! $entry instanceof TextResourceContents) {
            self::fail('Expected a TextResourceContents entry.');
        }

        self::assertSame('templated:etc', $entry->text);
    }

    public function testRegisterDiscoversAttributeMarkedDefinitions(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->register(new DiscoverableServer())
            ->build()
        ;

        $tools = $this->dispatch($server, 'tools/list');
        self::assertInstanceOf(ListToolsResult::class, $tools);
        self::assertSame(['add', 'greet_user'], self::sorted(array_map(static fn(Tool $tool): string => $tool->name, $tools->tools)));

        $prompts = $this->dispatch($server, 'prompts/list');
        self::assertInstanceOf(ListPromptsResult::class, $prompts);
        self::assertSame(['compose', 'labelled', 'outline', 'ping_prompt'], self::sorted(array_map(static fn(Prompt $prompt): string => $prompt->name, $prompts->prompts)));

        $resources = $this->dispatch($server, 'resources/list');
        self::assertInstanceOf(ListResourcesResult::class, $resources);
        self::assertSame(['app_config', 'defaults'], self::sorted(array_map(static fn(Resource $resource): string => $resource->name, $resources->resources)));

        $templates = $this->dispatch($server, 'resources/templates/list');
        self::assertInstanceOf(ListResourceTemplatesResult::class, $templates);
        self::assertSame(['fileTemplate', 'user_profile'], self::sorted(array_map(static fn(ResourceTemplate $template): string => $template->name, $templates->resourceTemplates)));
    }

    public function testRegisterWiresAnExecutableTool(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->register(new DiscoverableServer())
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/call', ['name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]]);

        self::assertInstanceOf(CallToolResult::class, $result);

        $block = $result->content[0] ?? null;

        if (! $block instanceof TextContent) {
            self::fail('Expected a TextContent block.');
        }

        self::assertSame('5', $block->text);
    }

    public function testRegisterMergesMultipleSources(): void
    {
        $extra = new class {
            #[AsTool(description: 'Pings.')]
            public function ping(): string
            {
                return 'pong';
            }
        };

        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->register(new DiscoverableServer(), $extra)
            ->build()
        ;

        $tools = $this->dispatch($server, 'tools/list');

        self::assertInstanceOf(ListToolsResult::class, $tools);
        self::assertSame(['add', 'greet_user', 'ping'], self::sorted(array_map(static fn(Tool $tool): string => $tool->name, $tools->tools)));
    }

    public function testRegisterReturnsTheBuilderForChaining(): void
    {
        $builder = new ServerBuilder();

        self::assertSame($builder, $builder->register(new DiscoverableServer()));
    }

    public function testRegisterAppliesServerMetadataFromAttribute(): void
    {
        $server = (new ServerBuilder())
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $result = $this->discoverResultFor($server);
        $info = $this->serverInfoFor($server);

        self::assertSame('described-server', $info->name);
        self::assertSame('2.3.4', $info->version);
        self::assertSame('Described Server', $info->title);
        self::assertSame('A server described entirely by attributes.', $info->description);
        self::assertSame('https://nexus.test', $info->websiteUrl);
        self::assertSame('Call the tools politely.', $result->instructions);
        self::assertSame([], $result->capabilities->tools);
    }

    public function testExplicitServerInfoFieldsWinAndAttributeFillsGaps(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('explicit-server', '9.9.9')
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $info = $this->serverInfoFor($server);

        self::assertSame('explicit-server', $info->name);
        self::assertSame('9.9.9', $info->version);
        self::assertSame('Described Server', $info->title);
        self::assertSame('A server described entirely by attributes.', $info->description);
        self::assertSame('https://nexus.test', $info->websiteUrl);
        self::assertSame('https://nexus.test/icon.svg', self::firstIcon($info)->src);
    }

    public function testExplicitServerInfoFieldsTakePrecedencePerField(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('explicit-server', '9.9.9', 'Explicit Title', 'Explicit description.', 'https://explicit.test', [new Icon(src: 'https://explicit.test/icon.svg')])
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $info = $this->serverInfoFor($server);

        self::assertSame('Explicit Title', $info->title);
        self::assertSame('Explicit description.', $info->description);
        self::assertSame('https://explicit.test', $info->websiteUrl);
        self::assertSame('https://explicit.test/icon.svg', self::firstIcon($info)->src);
    }

    public function testAttributeWithoutInstructionsLeavesThemNull(): void
    {
        $source = new #[AsServer(name: 'minimal', version: '1.0.0')] class {};
        $server = (new ServerBuilder())->register($source)->build();

        self::assertNull($this->discoverResultFor($server)->instructions);
        self::assertSame('minimal', $this->serverInfoFor($server)->name);
    }

    public function testRegisterRejectsEmptyInstructionsFromAttribute(): void
    {
        $source = new #[AsServer(name: 'x', version: '1.0.0', instructions: '')] class {};

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Server instructions must be a non-empty string or null.');

        (new ServerBuilder())->register($source)->build();
    }

    public function testLaterAttributelessSourceDoesNotClearServerMetadata(): void
    {
        $extra = new class {
            #[AsTool(description: 'Pings.')]
            public function ping(): string
            {
                return 'pong';
            }
        };

        $info = $this->serverInfoFor(
            (new ServerBuilder())->register(new SelfDescribingServer(), $extra)->build(),
        );

        self::assertSame('described-server', $info->name);
    }

    public function testMultipleServerAttributesAcrossSourcesThrow(): void
    {
        $second = new #[AsServer(name: 'second-server', version: '5.0.0')] class {};

        $this->expectException(DuplicateServerMetadataException::class);

        (new ServerBuilder())->register(new SelfDescribingServer(), $second);
    }

    public function testRegisterRejectsASourceWithoutDiscoverableAttributes(): void
    {
        $this->expectException(MissingDiscoveryAttributeException::class);

        $source = new class {
            public function notAttributed(): string
            {
                return 'ignored';
            }
        };
        (new ServerBuilder())->register($source);
    }

    public function testExplicitInstructionsTakePrecedenceOverAttribute(): void
    {
        $server = (new ServerBuilder())
            ->setInstructions('Explicit instructions win.')
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame('Explicit instructions win.', $result->instructions);
    }

    public function testAddResourceTemplateRejectsUnsupportedTemplateAtRegistration(): void
    {
        $builder = (new ServerBuilder())->setServerInfo('demo', '1.0.0');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must use only RFC 6570 Level 1 simple-name expressions/');

        $builder->addResourceTemplate(
            new ResourceTemplate(name: 'files', uriTemplate: 'file:///{+path}'),
            static fn(): never => throw new \LogicException('unreachable'),
        );
    }

    public function testReplaceRequestHandlerOverridesBuiltinAndIsDispatched(): void
    {
        $invoked = 0;
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static function () use (&$invoked): EmptyResult {
                    ++$invoked;

                    return new EmptyResult();
                },
            ))
            ->build()
        ;

        $this->dispatch($server, 'server/discover');

        self::assertSame(1, $invoked);
    }

    public function testAddRequestHandlerDispatchesTheVendorExtensionMethod(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addRequestHandler(TestClientRequest::getMethod(), new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            ), TestClientRequest::class)
            ->build()
        ;

        $result = $this->dispatch($server, TestClientRequest::getMethod());

        self::assertInstanceOf(EmptyResult::class, $result);
    }

    public function testAddRequestHandlerRejectsAClassWithoutTheClientMarker(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Request class "Nexus\Mcp\Tests\Fixtures\Core\TestRequest" must implement "Nexus\Mcp\Core\Schema\Request\ClientRequest" for the server to dispatch it.',
        );

        (new ServerBuilder())->addRequestHandler(TestRequest::getMethod(), new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ), TestRequest::class);
    }

    public function testAddRequestHandlerRejectsAClassDeclaringADifferentMethod(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Request class "Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest" must declare the method "acme/snapshot" it is registered for, \'tests/test-client-request\' declared.',
        );

        (new ServerBuilder())->addRequestHandler('acme/snapshot', new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ), TestClientRequest::class);
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('provideAddRequestHandlerRejectsReservedSpecMethodCases')]
    public function testAddRequestHandlerRejectsReservedSpecMethod(string $method): void
    {
        $this->expectException(ReservedMethodException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Request method "%s" is reserved by the MCP specification. Use replaceRequestHandler() to attach a handler to it.',
            $method,
        ));

        (new ServerBuilder())->addRequestHandler($method, new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ), TestClientRequest::class);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAddRequestHandlerRejectsReservedSpecMethodCases(): iterable
    {
        yield 'completion/complete' => ['completion/complete'];

        yield 'prompts/get' => ['prompts/get'];

        yield 'prompts/list' => ['prompts/list'];

        yield 'resources/list' => ['resources/list'];

        yield 'resources/read' => ['resources/read'];

        yield 'resources/templates/list' => ['resources/templates/list'];

        yield 'server/discover' => ['server/discover'];

        yield 'tools/call' => ['tools/call'];

        yield 'tools/list' => ['tools/list'];
    }

    public function testAddNotificationHandlerDispatchesTheVendorExtensionMethod(): void
    {
        $received = 0;

        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addNotificationHandler(TestNotification::getMethod(), new ClosureNotificationHandler(
                static function () use (&$received): void {
                    ++$received;
                },
            ), TestNotification::class)
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => TestNotification::getMethod(),
            ]);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->close();
        });

        $serverRun->await();

        self::assertSame(1, $received);
    }

    public function testAddNotificationHandlerRejectsAClassDeclaringADifferentMethod(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Notification class "Nexus\Mcp\Tests\Fixtures\Core\TestNotification" must declare the method "acme/snapshot-done" it is registered for, \'tests/test-notification\' declared.',
        );

        (new ServerBuilder())->addNotificationHandler('acme/snapshot-done', new ClosureNotificationHandler(
            static function (): void {},
        ), TestNotification::class);
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('provideAddNotificationHandlerRejectsReservedSpecMethodCases')]
    public function testAddNotificationHandlerRejectsReservedSpecMethod(string $method): void
    {
        $this->expectException(ReservedMethodException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Notification method "%s" is reserved by the MCP specification. Use replaceNotificationHandler() to attach a handler to it.',
            $method,
        ));

        (new ServerBuilder())->addNotificationHandler($method, new ClosureNotificationHandler(
            static function (): void {},
        ), TestNotification::class);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAddNotificationHandlerRejectsReservedSpecMethodCases(): iterable
    {
        yield 'notifications/cancelled' => ['notifications/cancelled'];

        yield 'notifications/progress' => ['notifications/progress'];

        yield 'notifications/prompts/list_changed' => ['notifications/prompts/list_changed'];

        yield 'notifications/resources/list_changed' => ['notifications/resources/list_changed'];

        yield 'notifications/resources/updated' => ['notifications/resources/updated'];

        yield 'notifications/tools/list_changed' => ['notifications/tools/list_changed'];
    }

    public function testEnableExtensionAdvertisesTheCapabilitySlot(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(self::buildServerExtension())
            ->build()
        ;

        $result = $this->dispatch($server, 'server/discover');

        self::assertInstanceOf(DiscoverResult::class, $result);
        self::assertStringContainsString(
            '"extensions":{"com.example/feature":{}}',
            json_encode($result->capabilities, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testAGatedExtensionMethodServesADeclaringClient(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(self::buildServerExtension())
            ->build()
        ;

        $result = $this->dispatch($server, TestClientRequest::getMethod(), [
            '_meta' => RequestMetaObjectFactory::shape(
                clientCapabilities: new ClientCapabilities(extensions: ['com.example/feature' => []]),
            ),
        ]);

        self::assertInstanceOf(EmptyResult::class, $result);
    }

    public function testAGatedExtensionMethodRejectsANonDeclaringClient(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(self::buildServerExtension())
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => TestClientRequest::getMethod(),
                'params' => ['_meta' => RequestMetaObjectFactory::shape()],
            ]);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->close();
        });

        $serverRun->await();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::MissingRequiredClientCapability->value, $message->error->code);
        self::assertSame(
            'This request requires client capabilities the client did not declare: extensions.com.example/feature.',
            $message->error->message,
        );
    }

    public function testADecoratorWrapsTheDefaultToolHandlerAndServesUngated(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'report', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'inner')]),
            )
            ->enableExtension(self::buildTextWrappingExtension('com.example/feature', 'outer'))
            ->build()
        ;

        // The request declares no extension capability, so a gated handler would refuse it.
        $result = $this->dispatch($server, 'tools/call', ['name' => 'report']);

        self::assertInstanceOf(CallToolResult::class, $result);
        $content = $result->content[0] ?? null;

        if (! $content instanceof TextContent) {
            self::fail('The decorated tool result must carry a text block.');
        }

        self::assertSame('outer(inner)', $content->text);
    }

    public function testADecoratorWrapsAReplacedHandler(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('tools/call', new ClosureRequestHandler(
                static fn(JsonRpcRequest $request, AbstractContext $context): Result => new CallToolResult(content: [new TextContent(text: 'replaced')]),
            ))
            ->enableExtension(self::buildTextWrappingExtension('com.example/feature', 'outer'))
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/call', ['name' => 'report']);

        self::assertInstanceOf(CallToolResult::class, $result);
        $content = $result->content[0] ?? null;

        if (! $content instanceof TextContent) {
            self::fail('The decorated tool result must carry a text block.');
        }

        self::assertSame('outer(replaced)', $content->text);
    }

    public function testTwoDecoratorsComposeWithTheLastEnabledOutermost(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'report', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'inner')]),
            )
            ->enableExtension(self::buildTextWrappingExtension('com.example/first', 'first'))
            ->enableExtension(self::buildTextWrappingExtension('com.example/second', 'second'))
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/call', ['name' => 'report']);

        self::assertInstanceOf(CallToolResult::class, $result);
        $content = $result->content[0] ?? null;

        if (! $content instanceof TextContent) {
            self::fail('The decorated tool result must carry a text block.');
        }

        self::assertSame('second(first(inner))', $content->text);
    }

    public function testBuildRejectsADecoratedMethodNoHandlerServes(): void
    {
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(self::buildTextWrappingExtension('com.example/feature', 'outer'))
        ;

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" decorates "tools/call", but no handler serves that method.');

        $builder->build();
    }

    public function testBuildRejectsADecoratorReturningANonHandler(): void
    {
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'report', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'inner')]),
            )
            ->enableExtension(new StubDecoratingServerExtension(
                identifier: 'com.example/feature',
                // @phpstan-ignore argument.type
                requestDecorators: ['tools/call' => static fn(RequestHandlerInterface $inner): string => 'oops'],
            ))
        ;

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" decorator for "tools/call" must return a request handler, string given.');

        $builder->build();
    }

    public function testExtensionNotificationHandlersDispatchUngated(): void
    {
        $received = [];

        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(new StubServerExtension(
                identifier: 'com.example/feature',
                notifications: [TestNotification::getMethod() => TestNotification::class],
                notificationHandlers: [TestNotification::getMethod() => new ClosureNotificationHandler(
                    static function () use (&$received): void {
                        $received[] = 'feature';
                    },
                )],
            ))
            ->enableExtension(new StubServerExtension(
                identifier: 'com.example/other',
                notifications: [TestSecondNotification::getMethod() => TestSecondNotification::class],
                notificationHandlers: [TestSecondNotification::getMethod() => new ClosureNotificationHandler(
                    static function () use (&$received): void {
                        $received[] = 'other';
                    },
                )],
            ))
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => TestNotification::getMethod(),
            ]);
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => TestSecondNotification::getMethod(),
            ]);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->close();
        });

        $serverRun->await();

        self::assertSame(['feature', 'other'], $received);
    }

    public function testTwoEnabledExtensionsServeTheirOwnMethods(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(self::buildServerExtension())
            ->enableExtension(new StubServerExtension(
                identifier: 'com.example/other',
                requests: [TestSecondClientRequest::getMethod() => TestSecondClientRequest::class],
                requestHandlers: [TestSecondClientRequest::getMethod() => new ClosureRequestHandler(
                    static fn(): EmptyResult => new EmptyResult(),
                )],
            ))
            ->build()
        ;

        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        $meta = RequestMetaObjectFactory::shape(clientCapabilities: new ClientCapabilities(extensions: [
            'com.example/feature' => [],
            'com.example/other' => [],
        ]));

        EventLoop::queue(static function () use ($transport, $meta): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => TestClientRequest::getMethod(),
                'params' => ['_meta' => $meta],
            ]);
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => TestSecondClientRequest::getMethod(),
                'params' => ['_meta' => $meta],
            ]);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->close();
        });

        $serverRun->await();

        $answered = [];

        foreach ($transport->sent as $entry) {
            $message = $entry['message'];

            if ($message instanceof JsonRpcResultResponse) {
                $answered[] = $message->id->id;
            }
        }

        self::assertSame([2, 3], $answered);
    }

    public function testEnableExtensionRejectsADuplicateIdentifier(): void
    {
        $builder = (new ServerBuilder())->enableExtension(new StubServerExtension(identifier: 'com.example/feature'));

        $this->expectException(DuplicateExtensionException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" is declared more than once.');

        $builder->enableExtension(new StubServerExtension(identifier: 'com.example/feature'));
    }

    public function testEnableExtensionRejectsAMethodABuilderHandlerOwns(): void
    {
        $builder = (new ServerBuilder())->addRequestHandler(TestClientRequest::getMethod(), new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ), TestClientRequest::class);

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the request method "tests/test-client-request" already owned by a builder-registered handler.',
        );

        $builder->enableExtension(self::buildServerExtension());
    }

    public function testEnableExtensionRejectsANotificationMethodABuilderHandlerOwns(): void
    {
        $builder = (new ServerBuilder())->addNotificationHandler(TestNotification::getMethod(), new ClosureNotificationHandler(
            static function (): void {},
        ), TestNotification::class);

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the notification method "tests/test-notification" already owned by a builder-registered handler.',
        );

        $builder->enableExtension(new StubServerExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::getMethod() => TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => new ClosureNotificationHandler(
                static function (): void {},
            )],
        ));
    }

    public function testAddRequestHandlerRejectsAMethodAnExtensionOwns(): void
    {
        $builder = (new ServerBuilder())->enableExtension(self::buildServerExtension());

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'A builder-registered handler cannot claim the request method "tests/test-client-request" already owned by extension "com.example/feature".',
        );

        $builder->addRequestHandler(TestClientRequest::getMethod(), new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ), TestClientRequest::class);
    }

    public function testAddNotificationHandlerRejectsAMethodAnExtensionOwns(): void
    {
        $builder = (new ServerBuilder())->enableExtension(new StubServerExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::getMethod() => TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => new ClosureNotificationHandler(
                static function (): void {},
            )],
        ));

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'A builder-registered handler cannot claim the notification method "tests/test-notification" already owned by extension "com.example/feature".',
        );

        $builder->addNotificationHandler(TestNotification::getMethod(), new ClosureNotificationHandler(
            static function (): void {},
        ), TestNotification::class);
    }

    public function testEnableExtensionRejectsARequestClassWithoutTheClientMarker(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" request class "Nexus\Mcp\Tests\Fixtures\Core\TestRequest" must implement "Nexus\Mcp\Core\Schema\Request\ClientRequest" for the server to dispatch it.',
        );

        (new ServerBuilder())->enableExtension(new StubServerExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            )],
        ));
    }

    public function testReplaceRequestHandlerRejectsVendorExtensionMethod(): void
    {
        $this->expectException(UnreservedMethodException::class);
        $this->expectExceptionMessageIs(
            'Request method "acme/snapshot" is not reserved by the MCP specification. Use addRequestHandler() to register a vendor extension.',
        );

        (new ServerBuilder())->replaceRequestHandler('acme/snapshot', new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));
    }

    public function testReplaceNotificationHandlerRejectsVendorExtensionMethod(): void
    {
        $this->expectException(UnreservedMethodException::class);
        $this->expectExceptionMessageIs(
            'Notification method "acme/snapshot-done" is not reserved by the MCP specification. Use addNotificationHandler() to register a vendor extension.',
        );

        (new ServerBuilder())->replaceNotificationHandler('acme/snapshot-done', new ClosureNotificationHandler(
            static function (): void {},
        ));
    }

    public function testCustomNotificationHandlerIsDispatched(): void
    {
        $invoked = 0;
        $server = (new ServerBuilder())
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

        EventLoop::queue(static function () use ($transport): void {
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
        $server = (new ServerBuilder())
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
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setCompletionStore(new RecordingCompletionStore(new CompleteResult(completion: ['values' => ['x']])))
            ->build()
        ;

        $result = $this->dispatch($server, 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'hello'],
            'argument' => ['name' => 'arg', 'value' => 'partial'],
        ]);

        self::assertInstanceOf(CompleteResult::class, $result);
        self::assertSame(['x'], $result->completion['values']);
    }

    public function testAddedCompletionsFlowThroughBuiltServerAndAdvertiseTheCapability(): void
    {
        $provider = new class implements CompletionProviderInterface {
            /**
             * @param null|array<array-key, string> $contextArguments
             */
            #[\Override]
            public function complete(string $argumentValue, ?array $contextArguments, ServerContext $context): CompleteResult
            {
                return new CompleteResult(completion: ['values' => ['from-provider-'.$argumentValue]]);
            }
        };

        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addPromptCompletion('compose', 'tone', static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['from-closure']]))
            ->addResourceTemplateCompletion('file:///{path}', 'path', $provider)
            ->addResourceTemplateCompletion('file:///{path}', 'ext', static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['csv']]))
            ->build()
        ;

        $discover = $this->dispatch($server, 'server/discover');
        self::assertInstanceOf(DiscoverResult::class, $discover);
        self::assertSame([], $discover->capabilities->completions);

        $promptResult = $this->dispatch($server, 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'compose'],
            'argument' => ['name' => 'tone', 'value' => 'f'],
        ]);
        self::assertInstanceOf(CompleteResult::class, $promptResult);
        self::assertSame(['from-closure'], $promptResult->completion['values']);

        $templateResult = $this->dispatch($server, 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file:///{path}'],
            'argument' => ['name' => 'path', 'value' => 'rep'],
        ]);
        self::assertInstanceOf(CompleteResult::class, $templateResult);
        self::assertSame(['from-provider-rep'], $templateResult->completion['values']);

        $closureTemplateResult = $this->dispatch($server, 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file:///{path}'],
            'argument' => ['name' => 'ext', 'value' => 'c'],
        ]);
        self::assertInstanceOf(CompleteResult::class, $closureTemplateResult);
        self::assertSame(['csv'], $closureTemplateResult->completion['values']);
    }

    public function testRegisterDiscoversCompletionProviders(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->register(new CompletionHandlers())
            ->build()
        ;

        $promptResult = $this->dispatch($server, 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'compose'],
            'argument' => ['name' => 'tone', 'value' => 'f'],
        ]);
        self::assertInstanceOf(CompleteResult::class, $promptResult);
        self::assertSame(['formal', 'friendly'], $promptResult->completion['values']);

        $templateResult = $this->dispatch($server, 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file:///{path}'],
            'argument' => ['name' => 'path', 'value' => 'report.csv'],
        ]);
        self::assertInstanceOf(CompleteResult::class, $templateResult);
        self::assertSame(['report.csv'], $templateResult->completion['values']);
    }

    public function testAnExplicitCompletionStoreWinsOverAddedCompletions(): void
    {
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addPromptCompletion('compose', 'tone', static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['added']]))
            ->setCompletionStore(new RecordingCompletionStore(new CompleteResult(completion: ['values' => ['explicit']])))
        ;

        $result = $this->dispatch($builder->build(), 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'compose'],
            'argument' => ['name' => 'tone', 'value' => 'f'],
        ]);

        self::assertInstanceOf(CompleteResult::class, $result);
        self::assertSame(['explicit'], $result->completion['values']);
    }

    public function testGetCompletionStoreMemoisesTheAssembledStore(): void
    {
        $builder = (new ServerBuilder())
            ->addPromptCompletion('compose', 'tone', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]))
        ;

        $store = $builder->getCompletionStore();

        self::assertInstanceOf(CompletionStore::class, $store);
        self::assertSame($store, $builder->getCompletionStore());
    }

    public function testGetCompletionStoreIsNullWithoutCompletions(): void
    {
        self::assertNull((new ServerBuilder())->getCompletionStore());
    }

    public function testAddPromptCompletionRejectsAnEmptyPromptName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Completion prompt name must be a non-empty string.');

        (new ServerBuilder())->addPromptCompletion('', 'tone', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]));
    }

    public function testAddPromptCompletionRejectsAnEmptyArgumentName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Completion argument name must be a non-empty string.');

        (new ServerBuilder())->addPromptCompletion('compose', '', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]));
    }

    public function testAddResourceTemplateCompletionRejectsAnEmptyTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Completion URI template must be a non-empty string.');

        (new ServerBuilder())->addResourceTemplateCompletion('', 'path', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]));
    }

    public function testAddResourceTemplateCompletionRejectsAnEmptyArgumentName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Completion argument name must be a non-empty string.');

        (new ServerBuilder())->addResourceTemplateCompletion('file:///{path}', '', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]));
    }

    public function testCustomToolStoreReplacesEntriesAndAdvertisesCapability(): void
    {
        $store = new class implements ToolStoreInterface {
            #[\Override]
            public function list(?Cursor $cursor): ListToolsResult
            {
                return new ListToolsResult(tools: [new Tool(name: 'custom_tool', inputSchema: ['type' => 'object'])], ttlMs: 0, cacheScope: CacheScope::Private);
            }

            #[\Override]
            public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult
            {
                return new CallToolResult(content: [new TextContent(text: 'custom')]);
            }
        };

        $capabilities = $this->discoverResultFor(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setToolStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->tools);

        $storeOnly = $this->dispatch(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setToolStore($store)->build(),
            'tools/list',
        );
        self::assertInstanceOf(ListToolsResult::class, $storeOnly);
        self::assertSame(['custom_tool'], array_map(static fn(Tool $tool): string => $tool->name, $storeOnly->tools));

        $withEntry = $this->dispatch(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->addTool(
                    new Tool(name: 'entry_tool', inputSchema: ['type' => 'object']),
                    static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: []),
                )
                ->setToolStore($store)
                ->build(),
            'tools/list',
        );
        self::assertInstanceOf(ListToolsResult::class, $withEntry);
        self::assertSame(['custom_tool'], array_map(static fn(Tool $tool): string => $tool->name, $withEntry->tools));
    }

    public function testToolStoreIsNullWhenNoToolsAreRegistered(): void
    {
        self::assertNull((new ServerBuilder())->setServerInfo('demo', '1.0.0')->getToolStore());
    }

    public function testToolStoreAssemblesTheRegisteredEntries(): void
    {
        $store = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'entry_tool', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: []),
            )
            ->getToolStore()
        ;

        if (! $store instanceof ToolStoreInterface) {
            self::fail('Expected a tool store.');
        }

        self::assertSame(['entry_tool'], array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools));
    }

    public function testToolStoreReturnsTheSuppliedStoreUntouched(): void
    {
        $store = new ToolStore(entries: [], pageSize: 10);

        self::assertSame(
            $store,
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setToolStore($store)->getToolStore(),
        );
    }

    public function testTheServedToolStoreIsTheSameInstanceTheAccessorReturns(): void
    {
        // `SecuredHttpEndpoint` validates `Mcp-Param-{Name}` against this store, so it must be the one
        // the request handlers serve rather than a second copy.
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'entry_tool', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: []),
            )
        ;

        $builder->build();

        self::assertSame($builder->getToolStore(), $builder->getToolStore());
    }

    public function testCancellingAnInFlightRequestSuppressesItsResponse(): void
    {
        // `notifications/cancelled` is served by default, so a built server honours the spec's rule that a
        // cancelled request draws no response without the consumer registering anything.
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'slow', inputSchema: ['type' => 'object']),
                static function (?array $args, ServerContext $ctx): CallToolResult {
                    delay(1.0, cancellation: $ctx->cancellation);

                    return new CallToolResult(content: []);
                },
            )
            ->build()
        ;

        $transport = new RecordingTransport();
        $server->listen($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'slow'],
        ]);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ]);
        $transport->close();

        self::assertSame([], $transport->sent);
    }

    public function testAConsumerMayStillReplaceTheCancelledNotificationHandler(): void
    {
        $seen = [];
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceNotificationHandler(
                'notifications/cancelled',
                new ClosureNotificationHandler(static function ($notification) use (&$seen): void {
                    $seen[] = $notification::getMethod();
                }),
            )
            ->build()
        ;

        $transport = new RecordingTransport();
        $server->listen($transport);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ]);
        $transport->close();

        self::assertSame(['notifications/cancelled'], $seen, 'A replaced handler must win over the built-in default.');
    }

    public function testSubscriptionsAreNotServedUntilAStoreIsRegistered(): void
    {
        $capabilities = $this->discoverResultFor(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->addTool(new Tool(name: 't', inputSchema: ['type' => 'object']), static fn(?array $a, $c): CallToolResult => new CallToolResult(content: []))
                ->build(),
        )->capabilities;

        self::assertSame([], $capabilities->tools, 'listChanged is a promise to deliver, so it needs a subscription store.');
    }

    public function testAdvertisesListChangedOnlyForFeaturesWhoseStoreCanReportChanges(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setSubscriptionStore(new SubscriptionStore(toolsListChanged: true, promptsListChanged: true))
            ->addTool(new Tool(name: 't', inputSchema: ['type' => 'object']), static fn(?array $a, $c): CallToolResult => new CallToolResult(content: []))
            ->setPromptStore(self::createStub(PromptStoreInterface::class))
            ->build()
        ;

        $capabilities = $this->discoverResultFor($server)->capabilities;

        self::assertSame(['listChanged' => true], $capabilities->tools);
        self::assertSame([], $capabilities->prompts, 'A store that cannot report changes must not claim listChanged.');
    }

    public function testEachFeatureReadsItsOwnSlotOfWhatTheSubscriptionStoreHonours(): void
    {
        $capabilities = $this->discoverResultFor(
            self::registerFeatureTriples((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
                ->setSubscriptionStore(new SubscriptionStore(promptsListChanged: true))
                ->build(),
        )->capabilities;

        self::assertSame(['listChanged' => true], $capabilities->prompts);
        self::assertSame([], $capabilities->tools, 'A type the subscription store will not honour is not advertised.');
        self::assertSame([], $capabilities->resources);
    }

    public function testToolListChangedNeedsAToolStoreThatReportsChanges(): void
    {
        $capabilities = $this->discoverResultFor(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->setToolStore(new PagedToolStore([[new Tool(name: 't', inputSchema: ['type' => 'object'])]]))
                ->setSubscriptionStore(new SubscriptionStore(toolsListChanged: true))
                ->build(),
        )->capabilities;

        self::assertSame([], $capabilities->tools, 'A store that cannot report changes must not claim listChanged.');
    }

    /**
     * @param \Closure(ServerBuilder): ServerBuilder $compose
     * @param array<string, bool>                    $expected
     */
    #[DataProvider('provideResourceListChangedFollowsEitherResourceStoreCases')]
    public function testResourceListChangedFollowsEitherResourceStore(\Closure $compose, array $expected): void
    {
        // Both stores fan into `notifications/resources/list_changed`, so either one reporting is enough.
        $builder = $compose((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
            ->setSubscriptionStore(new SubscriptionStore(resourcesListChanged: true))
        ;

        self::assertSame($expected, $this->discoverResultFor($builder->build())->capabilities->resources);
    }

    /**
     * @return iterable<string, array{\Closure(ServerBuilder): ServerBuilder, array<string, bool>}>
     */
    public static function provideResourceListChangedFollowsEitherResourceStoreCases(): iterable
    {
        yield 'only the resource store reports' => [
            static fn(ServerBuilder $b): ServerBuilder => $b
                ->addResource(
                    new Resource(name: 'r', uri: 'mem://r'),
                    static fn(string $u, $c): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
                ->setResourceTemplateStore(self::createStub(ResourceTemplateStoreInterface::class)),
            ['listChanged' => true],
        ];

        yield 'only the template store reports' => [
            static fn(ServerBuilder $b): ServerBuilder => $b
                ->setResourceStore(self::createStub(ResourceStoreInterface::class))
                ->addResourceTemplate(
                    new ResourceTemplate(name: 'rt', uriTemplate: 'mem://rt/{x}'),
                    static fn(string $u, array $bs, $c): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                ),
            ['listChanged' => true],
        ];

        yield 'neither reports' => [
            static fn(ServerBuilder $b): ServerBuilder => $b
                ->setResourceStore(self::createStub(ResourceStoreInterface::class))
                ->setResourceTemplateStore(self::createStub(ResourceTemplateStoreInterface::class)),
            [],
        ];
    }

    public function testAdvertisesResourceSubscribeAlongsideAListenStore(): void
    {
        $server = self::builderWithResource()
            ->setSubscriptionStore(new SubscriptionStore(resourcesListChanged: true, resourceSubscriptions: true))
            ->build()
        ;

        self::assertSame(
            ['listChanged' => true, 'subscribe' => true],
            $this->discoverResultFor($server)->capabilities->resources,
        );
    }

    public function testAMutatedToolListReachesAnOpenSubscription(): void
    {
        $subscriptions = new SubscriptionStore(toolsListChanged: true);
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setSubscriptionStore($subscriptions)
            ->addTool(new Tool(name: 'seed', inputSchema: ['type' => 'object']), static fn(?array $a, $c): CallToolResult => new CallToolResult(content: []))
        ;
        $builder->build();

        $sender = new RecordingSender();
        $subscriptions->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        $toolStore = $builder->getToolStore();

        if (! $toolStore instanceof MutableToolStoreInterface) {
            self::fail('The entry-built tool store must support runtime mutation.');
        }

        $toolStore->addTool(new Tool(name: 'added', inputSchema: ['type' => 'object']), new ClosureToolExecutor(
            static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: []),
        ));
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/tools/list_changed'],
            array_map(static fn(JsonRpcNotification $n): string => $n::getMethod(), $sender->notifications),
        );
    }

    public function testEveryMutableStoreIsRoutedToTheSubscriptionStore(): void
    {
        $subscriptions = new SubscriptionStore(
            toolsListChanged: true,
            promptsListChanged: true,
            resourcesListChanged: true,
        );
        $builder = self::registerFeatureTriples((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
            ->setSubscriptionStore($subscriptions)
        ;
        $builder->build();

        $sender = new RecordingSender();
        $subscriptions->open(
            new RequestId(id: 1),
            new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
            $sender,
        );

        $toolStore = $builder->getToolStore();
        $promptStore = $builder->getPromptStore();
        $resourceStore = $builder->getResourceStore();

        if (
            ! $toolStore instanceof ListChangeSourceInterface
            || ! $promptStore instanceof ListChangeSourceInterface
            || ! $resourceStore instanceof ListChangeSourceInterface
        ) {
            self::fail('Every entry-built store reports its own changes.');
        }

        // Mutating each store must reach the stream, which is what proves the builder routed all three.
        self::assertInstanceOf(MutableToolStoreInterface::class, $toolStore);
        self::assertInstanceOf(MutablePromptStoreInterface::class, $promptStore);
        self::assertInstanceOf(MutableResourceStoreInterface::class, $resourceStore);
        $toolStore->removeTool('alpha');
        $promptStore->removePrompt('alpha');
        $resourceStore->removeResource('mem://alpha');
        delay(0.0);

        self::assertSame([
            'notifications/subscriptions/acknowledged',
            'notifications/tools/list_changed',
            'notifications/prompts/list_changed',
            'notifications/resources/list_changed',
        ], array_map(static fn(JsonRpcNotification $n): string => $n::getMethod(), $sender->notifications));
    }

    public function testAMutatedTemplateListReachesAnOpenSubscription(): void
    {
        $subscriptions = new SubscriptionStore(resourcesListChanged: true);
        $builder = self::registerFeatureTriples((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
            ->setSubscriptionStore($subscriptions)
        ;
        $builder->build();

        $sender = new RecordingSender();
        $subscriptions->open(new RequestId(id: 1), new SubscriptionFilter(resourcesListChanged: true), $sender);

        $templateStore = $builder->getResourceTemplateStore();

        if (! $templateStore instanceof MutableResourceTemplateStoreInterface) {
            self::fail('The entry-built resource template store must support runtime mutation.');
        }

        // A template expansion changes what the server can read, so it is a resource list change.
        $templateStore->removeResourceTemplate('mem://alpha/{path}');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/list_changed'],
            array_map(static fn(JsonRpcNotification $n): string => $n::getMethod(), $sender->notifications),
        );
    }

    public function testACapabilityIsNotAdvertisedWhenTheSubscriptionStoreWillNotHonourIt(): void
    {
        // A store that honours nothing is the default shape, so the builder must not promise on its behalf.
        $capabilities = $this->discoverResultFor(
            self::registerFeatureTriples((new ServerBuilder())->setServerInfo('demo', '1.0.0'))
                ->setSubscriptionStore(new SubscriptionStore())
                ->build(),
        )->capabilities;

        self::assertSame([], $capabilities->tools);
        self::assertSame([], $capabilities->prompts);
        self::assertSame([], $capabilities->resources);
    }

    /**
     * @param \Closure(ServerBuilder): ServerBuilder $mutate
     */
    #[DataProvider('provideEveryRegistrationIsRefusedAfterBuildCases')]
    public function testEveryRegistrationIsRefusedAfterBuild(\Closure $mutate): void
    {
        // The built server holds the stores and the list-change listeners, so a later registration would be
        // dropped without a trace.
        $builder = (new ServerBuilder())->setServerInfo('demo', '1.0.0');
        $builder->build();

        $this->expectException(BuilderAlreadyBuiltException::class);

        $mutate($builder);
    }

    /**
     * @return iterable<string, array{\Closure(ServerBuilder): ServerBuilder}>
     */
    public static function provideEveryRegistrationIsRefusedAfterBuildCases(): iterable
    {
        yield 'setServerInfo' => [static fn(ServerBuilder $b): ServerBuilder => $b->setServerInfo('x', '1.0.0')];

        yield 'setMaxInFlightDispatches' => [static fn(ServerBuilder $b): ServerBuilder => $b->setMaxInFlightDispatches(2)];

        yield 'setServerInfoDisclosure' => [static fn(ServerBuilder $b): ServerBuilder => $b->setServerInfoDisclosure(ServerInfoDisclosure::None)];

        yield 'setInstructions' => [static fn(ServerBuilder $b): ServerBuilder => $b->setInstructions('hi')];

        yield 'setLogger' => [static fn(ServerBuilder $b): ServerBuilder => $b->setLogger(new ArrayLogger())];

        yield 'setSchemaValidator' => [static fn(ServerBuilder $b): ServerBuilder => $b->setSchemaValidator(new OpisSchemaValidator())];

        yield 'setPageSize' => [static fn(ServerBuilder $b): ServerBuilder => $b->setPageSize(5)];

        yield 'setTtlMs' => [static fn(ServerBuilder $b): ServerBuilder => $b->setTtlMs(5)];

        yield 'setCacheScope' => [static fn(ServerBuilder $b): ServerBuilder => $b->setCacheScope(CacheScope::Public)];

        yield 'addTool' => [static fn(ServerBuilder $b): ServerBuilder => $b->addTool(new Tool(name: 't', inputSchema: ['type' => 'object']), static fn(?array $a, $c): CallToolResult => new CallToolResult(content: []))];

        yield 'addPrompt' => [static fn(ServerBuilder $b): ServerBuilder => $b->addPrompt(new Prompt(name: 'p'), static fn(?array $a, $c): GetPromptResult => new GetPromptResult(messages: []))];

        yield 'addResource' => [static fn(ServerBuilder $b): ServerBuilder => $b->addResource(new Resource(name: 'r', uri: 'mem://r'), static fn(string $u, $c): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private))];

        yield 'addResourceTemplate' => [static fn(ServerBuilder $b): ServerBuilder => $b->addResourceTemplate(new ResourceTemplate(name: 'rt', uriTemplate: 'mem://rt/{x}'), static fn(string $u, array $bs, $c): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private))];

        yield 'setToolStore' => [static fn(ServerBuilder $b): ServerBuilder => $b->setToolStore(new ToolStore())];

        yield 'setPromptStore' => [static fn(ServerBuilder $b): ServerBuilder => $b->setPromptStore(new PromptStore())];

        yield 'setResourceStore' => [static fn(ServerBuilder $b): ServerBuilder => $b->setResourceStore(new ResourceStore())];

        yield 'setResourceTemplateStore' => [static fn(ServerBuilder $b): ServerBuilder => $b->setResourceTemplateStore(new ResourceTemplateStore())];

        yield 'setSubscriptionStore' => [static fn(ServerBuilder $b): ServerBuilder => $b->setSubscriptionStore(new SubscriptionStore())];

        yield 'setCompletionStore' => [static fn(ServerBuilder $b): ServerBuilder => $b->setCompletionStore(new CompletionStore())];

        yield 'addPromptCompletion' => [static fn(ServerBuilder $b): ServerBuilder => $b->addPromptCompletion('compose', 'tone', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]))];

        yield 'addResourceTemplateCompletion' => [static fn(ServerBuilder $b): ServerBuilder => $b->addResourceTemplateCompletion('file:///{path}', 'path', static fn(): CompleteResult => new CompleteResult(completion: ['values' => []]))];

        // A source carrying only `#[AsServer]` contributes without reaching a guarded `add*()`, so this is
        // the case that proves `register()` needs a guard of its own.
        yield 'register' => [static fn(ServerBuilder $b): ServerBuilder => $b->register(new #[AsServer(name: 'late', version: '1.0.0')] class {})];

        yield 'addRequestHandler' => [static fn(ServerBuilder $b): ServerBuilder => $b->addRequestHandler('completion/complete', new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult()), TestClientRequest::class)];

        yield 'replaceRequestHandler' => [static fn(ServerBuilder $b): ServerBuilder => $b->replaceRequestHandler('tools/list', new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult()))];

        yield 'addNotificationHandler' => [static fn(ServerBuilder $b): ServerBuilder => $b->addNotificationHandler('notifications/progress', new ClosureNotificationHandler(static fn() => null), TestNotification::class)];

        yield 'enableExtension' => [static fn(ServerBuilder $b): ServerBuilder => $b->enableExtension(new StubServerExtension(identifier: 'com.example/feature'))];

        yield 'replaceNotificationHandler' => [static fn(ServerBuilder $b): ServerBuilder => $b->replaceNotificationHandler('notifications/cancelled', new ClosureNotificationHandler(static fn() => null))];
    }

    public function testBuildingTwiceIsRefused(): void
    {
        $builder = (new ServerBuilder())->setServerInfo('demo', '1.0.0');
        $builder->build();

        $this->expectException(BuilderAlreadyBuiltException::class);
        $this->expectExceptionMessageIs('This builder has already been built. Construct a new ServerBuilder for another server.');

        $builder->build();
    }

    public function testAMutatedResourceListReachesAnOpenSubscription(): void
    {
        $subscriptions = new SubscriptionStore(resourcesListChanged: true);
        $builder = self::builderWithResource()->setSubscriptionStore($subscriptions);
        $builder->build();

        $sender = new RecordingSender();
        $subscriptions->open(new RequestId(id: 1), new SubscriptionFilter(resourcesListChanged: true), $sender);

        $resourceStore = $builder->getResourceStore();

        if (! $resourceStore instanceof MutableResourceStoreInterface) {
            self::fail('The entry-built resource store must support runtime mutation.');
        }

        $resourceStore->removeResource('file:///etc/cfg');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/list_changed'],
            array_map(static fn(JsonRpcNotification $n): string => $n::getMethod(), $sender->notifications),
        );
    }

    public function testPromptStoreIsNullWhenNoPromptsAreRegistered(): void
    {
        self::assertNull((new ServerBuilder())->setServerInfo('demo', '1.0.0')->getPromptStore());
    }

    public function testPromptStoreAssemblesTheRegisteredEntries(): void
    {
        $store = self::builderWithPrompt()->getPromptStore();

        if (! $store instanceof PromptStoreInterface) {
            self::fail('Expected a prompt store.');
        }

        self::assertSame(['hello'], array_map(static fn(Prompt $p): string => $p->name, $store->list(null)->prompts));
    }

    public function testPromptStoreReturnsTheSuppliedStoreUntouched(): void
    {
        $store = new PromptStore(entries: [], pageSize: 10);

        self::assertSame(
            $store,
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setPromptStore($store)->getPromptStore(),
        );
    }

    public function testTheServedPromptStoreIsTheSameInstanceTheAccessorReturns(): void
    {
        $builder = self::builderWithPrompt();
        $builder->build();

        self::assertSame($builder->getPromptStore(), $builder->getPromptStore());
    }

    public function testResourceStoreIsNullWhenNoResourcesAreRegistered(): void
    {
        self::assertNull((new ServerBuilder())->setServerInfo('demo', '1.0.0')->getResourceStore());
    }

    public function testResourceStoreAssemblesTheRegisteredEntries(): void
    {
        $store = self::builderWithResource()->getResourceStore();

        if (! $store instanceof ResourceStoreInterface) {
            self::fail('Expected a resource store.');
        }

        self::assertSame(
            ['file:///etc/cfg'],
            array_map(static fn(Resource $r): string => $r->uri, $store->list(null)->resources),
        );
    }

    public function testResourceStoreIsAssembledWhenOnlyTemplatesAreRegistered(): void
    {
        // `resources/read` composes the two stores, so a template alone still needs a resource store.
        $store = self::builderWithResourceTemplate()->getResourceStore();

        if (! $store instanceof ResourceStoreInterface) {
            self::fail('Expected a resource store alongside the template store.');
        }

        self::assertSame([], $store->list(null)->resources);
    }

    public function testResourceStoreReturnsTheSuppliedStoreUntouched(): void
    {
        $store = new ResourceStore(entries: [], pageSize: 10);

        self::assertSame(
            $store,
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setResourceStore($store)->getResourceStore(),
        );
    }

    public function testTheServedResourceStoreIsTheSameInstanceTheAccessorReturns(): void
    {
        $builder = self::builderWithResource();
        $builder->build();

        self::assertSame($builder->getResourceStore(), $builder->getResourceStore());
    }

    public function testResourceTemplateStoreIsNullWhenNoTemplatesAreRegistered(): void
    {
        self::assertNull((new ServerBuilder())->setServerInfo('demo', '1.0.0')->getResourceTemplateStore());
    }

    public function testResourceTemplateStoreAssemblesTheRegisteredEntries(): void
    {
        $store = self::builderWithResourceTemplate()->getResourceTemplateStore();

        if (! $store instanceof ResourceTemplateStoreInterface) {
            self::fail('Expected a resource template store.');
        }

        self::assertSame(
            ['file:///{path}'],
            array_map(
                static fn(ResourceTemplate $t): string => $t->uriTemplate,
                $store->list(null)->resourceTemplates,
            ),
        );
    }

    public function testResourceTemplateStoreReturnsTheSuppliedStoreUntouched(): void
    {
        $store = new ResourceTemplateStore(entries: [], pageSize: 10);

        self::assertSame(
            $store,
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->setResourceTemplateStore($store)
                ->getResourceTemplateStore(),
        );
    }

    public function testTheServedResourceTemplateStoreIsTheSameInstanceTheAccessorReturns(): void
    {
        $builder = self::builderWithResourceTemplate();
        $builder->build();

        self::assertSame($builder->getResourceTemplateStore(), $builder->getResourceTemplateStore());
    }

    /**
     * @param \Closure(ServerBuilder): ServerBuilder $register
     */
    #[DataProvider('provideRegisteringAnUnconventionalNameIsRefusedCases')]
    public function testRegisteringAnUnconventionalNameIsRefused(\Closure $register, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        $register(new ServerBuilder());
    }

    /**
     * @return iterable<string, array{\Closure(ServerBuilder): ServerBuilder, string}>
     */
    public static function provideRegisteringAnUnconventionalNameIsRefusedCases(): iterable
    {
        yield 'addTool' => [
            static fn(ServerBuilder $b): ServerBuilder => $b->addTool(
                new Tool(name: 'Project Files', inputSchema: ['type' => 'object']),
                static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: []),
            ),
            'tool "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.',
        ];

        yield 'addPrompt' => [
            static fn(ServerBuilder $b): ServerBuilder => $b->addPrompt(
                new Prompt(name: 'Project Files'),
                static fn(?array $a, ServerContext $c): GetPromptResult => new GetPromptResult(messages: []),
            ),
            'prompt "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.',
        ];

        yield 'addResource' => [
            static fn(ServerBuilder $b): ServerBuilder => $b->addResource(
                new Resource(name: 'Project Files', uri: 'mem://r'),
                static fn(string $u, ServerContext $c): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
            ),
            'resource "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.',
        ];

        yield 'addResourceTemplate' => [
            static fn(ServerBuilder $b): ServerBuilder => $b->addResourceTemplate(
                new ResourceTemplate(name: 'Project Files', uriTemplate: 'mem://{path}'),
                static fn(string $u, array $bindings, ServerContext $c): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
            ),
            'resource template "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.',
        ];
    }

    public function testAToolMayAnswerWithAnInputRequiredResult(): void
    {
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'ask', inputSchema: ['type' => 'object']),
                static fn(?array $args, ServerContext $ctx): InputRequiredResult => new InputRequiredResult(
                    inputRequests: ['who' => new ElicitRequest(params: new ElicitRequestFormParams(
                        message: 'Who are you?',
                        requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
                    ))],
                    requestState: 'state-1',
                ),
            )
            ->build()
        ;

        $result = $this->dispatch($server, 'tools/call', ['name' => 'ask', 'arguments' => []]);

        if (! $result instanceof InputRequiredResult) {
            self::fail('Expected an InputRequiredResult.');
        }

        self::assertSame('state-1', $result->requestState);
        self::assertSame(['who'], array_keys($result->inputRequests ?? []));
        self::assertSame('input_required', $result->toArray()['resultType']);
    }

    public function testTheClientsInputResponsesAndRequestStateReachTheExecutor(): void
    {
        $seen = null;
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'ask', inputSchema: ['type' => 'object']),
                static function (?array $args, ServerContext $ctx) use (&$seen): CallToolResult {
                    $seen = [$ctx->inputResponses, $ctx->requestState];

                    return new CallToolResult(content: []);
                },
            )
            ->build()
        ;

        $this->dispatch($server, 'tools/call', [
            'name' => 'ask',
            'arguments' => [],
            'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['name' => 'Ada']]],
            'requestState' => 'state-1',
        ]);

        if (! \is_array($seen)) {
            self::fail('The executor was never reached.');
        }

        self::assertSame('state-1', $seen[1]);
        self::assertSame(['who'], array_keys($seen[0] ?? []));
    }

    public function testTheClientsInputResponsesAndRequestStateReachAResourceReader(): void
    {
        $seen = null;
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource(name: 'cfg', uri: 'file:///etc/cfg'),
                static function (string $uri, ServerContext $ctx) use (&$seen): ReadResourceResult {
                    $seen = [$ctx->inputResponses, $ctx->requestState];

                    return new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private);
                },
            )
            ->build()
        ;

        $this->dispatch($server, 'resources/read', [
            'uri' => 'file:///etc/cfg',
            'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['name' => 'Ada']]],
            'requestState' => 'state-1',
        ]);

        if (! \is_array($seen)) {
            self::fail('The resource reader was never reached.');
        }

        self::assertSame('state-1', $seen[1]);
        self::assertSame(['who'], array_keys($seen[0] ?? []));
    }

    public function testTheClientsInputResponsesAndRequestStateReachAPromptRenderer(): void
    {
        $seen = null;
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addPrompt(
                new Prompt(name: 'ask'),
                static function (?array $args, ServerContext $ctx) use (&$seen): GetPromptResult {
                    $seen = [$ctx->inputResponses, $ctx->requestState];

                    return new GetPromptResult(messages: []);
                },
            )
            ->build()
        ;

        $this->dispatch($server, 'prompts/get', [
            'name' => 'ask',
            'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['name' => 'Ada']]],
            'requestState' => 'state-1',
        ]);

        if (! \is_array($seen)) {
            self::fail('The prompt renderer was never reached.');
        }

        self::assertSame('state-1', $seen[1]);
        self::assertSame(['who'], array_keys($seen[0] ?? []));
    }

    public function testAToolCallWithoutMrtrFieldsLeavesThemNullOnTheContext(): void
    {
        $seen = null;
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'ask', inputSchema: ['type' => 'object']),
                static function (?array $args, ServerContext $ctx) use (&$seen): CallToolResult {
                    $seen = [$ctx->inputResponses, $ctx->requestState];

                    return new CallToolResult(content: []);
                },
            )
            ->build()
        ;

        $this->dispatch($server, 'tools/call', ['name' => 'ask', 'arguments' => []]);

        self::assertSame([null, null], $seen);
    }

    public function testCustomPromptStoreReplacesEntriesAndAdvertisesCapability(): void
    {
        $store = new class implements PromptStoreInterface {
            #[\Override]
            public function list(?Cursor $cursor): ListPromptsResult
            {
                return new ListPromptsResult(prompts: [new Prompt(name: 'custom_prompt')], ttlMs: 0, cacheScope: CacheScope::Private);
            }

            #[\Override]
            public function get(string $name, ?array $arguments, ServerContext $context): GetPromptResult
            {
                return new GetPromptResult(messages: []);
            }
        };

        $capabilities = $this->discoverResultFor(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setPromptStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->prompts);

        $storeOnly = $this->dispatch(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setPromptStore($store)->build(),
            'prompts/list',
        );
        self::assertInstanceOf(ListPromptsResult::class, $storeOnly);
        self::assertSame(['custom_prompt'], array_map(static fn(Prompt $prompt): string => $prompt->name, $storeOnly->prompts));

        $withEntry = $this->dispatch(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->addPrompt(
                    new Prompt(name: 'entry_prompt'),
                    static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult(messages: []),
                )
                ->setPromptStore($store)
                ->build(),
            'prompts/list',
        );
        self::assertInstanceOf(ListPromptsResult::class, $withEntry);
        self::assertSame(['custom_prompt'], array_map(static fn(Prompt $prompt): string => $prompt->name, $withEntry->prompts));
    }

    public function testCustomResourceStoreReplacesEntriesAndAdvertisesCapability(): void
    {
        $store = new class implements ResourceStoreInterface {
            #[\Override]
            public function list(?Cursor $cursor): ListResourcesResult
            {
                return new ListResourcesResult(resources: [new Resource(name: 'custom', uri: 'mem://custom')], ttlMs: 0, cacheScope: CacheScope::Private);
            }

            #[\Override]
            public function read(string $uri, ServerContext $context): ReadResourceResult
            {
                return new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'custom-body')], ttlMs: 0, cacheScope: CacheScope::Private);
            }
        };

        $capabilities = $this->discoverResultFor(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setResourceStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->resources);

        $storeOnly = $this->dispatch(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setResourceStore($store)->build(),
            'resources/list',
        );
        self::assertInstanceOf(ListResourcesResult::class, $storeOnly);
        self::assertSame(['mem://custom'], array_map(static fn(Resource $resource): string => $resource->uri, $storeOnly->resources));

        $withEntry = $this->dispatch(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->addResource(
                    new Resource(name: 'entry', uri: 'mem://entry'),
                    static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
                ->setResourceStore($store)
                ->build(),
            'resources/list',
        );
        self::assertInstanceOf(ListResourcesResult::class, $withEntry);
        self::assertSame(['mem://custom'], array_map(static fn(Resource $resource): string => $resource->uri, $withEntry->resources));

        $alongsideTemplates = $this->dispatch(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->addResource(
                    new Resource(name: 'entry', uri: 'mem://entry'),
                    static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
                ->addResourceTemplate(
                    new ResourceTemplate(name: 'files', uriTemplate: 'mem://files/{id}'),
                    static fn(string $uri, array $bindings, $ctx): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
                ->setResourceStore($store)
                ->build(),
            'resources/list',
        );
        self::assertInstanceOf(ListResourcesResult::class, $alongsideTemplates);
        self::assertSame(['mem://custom'], array_map(static fn(Resource $resource): string => $resource->uri, $alongsideTemplates->resources));
    }

    public function testCustomResourceTemplateStoreReplacesEntriesAndAdvertisesCapability(): void
    {
        $store = new class implements ResourceTemplateStoreInterface {
            #[\Override]
            public function list(?Cursor $cursor): ListResourceTemplatesResult
            {
                return new ListResourceTemplatesResult(resourceTemplates: [new ResourceTemplate(name: 'custom', uriTemplate: 'mem://{id}')], ttlMs: 0, cacheScope: CacheScope::Private);
            }

            #[\Override]
            public function read(string $uri, ServerContext $context): ReadResourceResult
            {
                return new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'custom-body')], ttlMs: 0, cacheScope: CacheScope::Private);
            }
        };

        $capabilities = $this->discoverResultFor(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setResourceTemplateStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->resources);

        $storeOnly = $this->dispatch(
            (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setResourceTemplateStore($store)->build(),
            'resources/templates/list',
        );
        self::assertInstanceOf(ListResourceTemplatesResult::class, $storeOnly);
        self::assertSame(['mem://{id}'], array_map(static fn(ResourceTemplate $template): string => $template->uriTemplate, $storeOnly->resourceTemplates));

        $withEntry = $this->dispatch(
            (new ServerBuilder())
                ->setServerInfo('demo', '1.0.0')
                ->addResourceTemplate(
                    new ResourceTemplate(name: 'entry', uriTemplate: 'mem://entry/{id}'),
                    static fn(string $uri, array $bindings, $ctx): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
                ->setResourceTemplateStore($store)
                ->build(),
            'resources/templates/list',
        );
        self::assertInstanceOf(ListResourceTemplatesResult::class, $withEntry);
        self::assertSame(['mem://{id}'], array_map(static fn(ResourceTemplate $template): string => $template->uriTemplate, $withEntry->resourceTemplates));
    }

    private static function buildServerExtension(): StubServerExtension
    {
        return new StubServerExtension(
            identifier: 'com.example/feature',
            requests: [TestClientRequest::getMethod() => TestClientRequest::class],
            requestHandlers: [TestClientRequest::getMethod() => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(),
            )],
        );
    }

    /**
     * @param non-empty-string $identifier
     * @param non-empty-string $marker
     */
    private static function buildTextWrappingExtension(string $identifier, string $marker): StubDecoratingServerExtension
    {
        return new StubDecoratingServerExtension(
            identifier: $identifier,
            requestDecorators: [
                'tools/call' => static fn(RequestHandlerInterface $inner): RequestHandlerInterface => new ClosureRequestHandler(
                    static function (JsonRpcRequest $request, AbstractContext $context) use ($inner, $marker): Result {
                        $result = $inner->handle($request, $context);
                        \assert($result instanceof CallToolResult);
                        $text = $result->content[0] ?? null;
                        \assert($text instanceof TextContent);

                        return new CallToolResult(content: [new TextContent(text: \sprintf('%s(%s)', $marker, $text->text))]);
                    },
                ),
            ],
        );
    }

    private static function builderWithPrompt(): ServerBuilder
    {
        return (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addPrompt(
                new Prompt(name: 'hello'),
                static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult(messages: []),
            )
        ;
    }

    private static function builderWithResource(): ServerBuilder
    {
        return (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResource(
                new Resource(name: 'cfg', uri: 'file:///etc/cfg'),
                static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(
                    contents: [new TextResourceContents(uri: $uri, text: 'data')],
                    ttlMs: 0,
                    cacheScope: CacheScope::Private,
                ),
            )
        ;
    }

    private static function builderWithResourceTemplate(): ServerBuilder
    {
        return (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addResourceTemplate(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            )
        ;
    }

    /**
     * @return array<string, mixed>
     */
    private static function discoverEnvelope(int $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    private static function firstIcon(Implementation $info): Icon
    {
        $icon = ($info->icons ?? [])[0] ?? null;

        if (! $icon instanceof Icon) {
            self::fail('Expected an icon.');
        }

        return $icon;
    }

    /**
     * Drives the constructed server with a synthetic `server/discover` request and
     * returns the typed result captured off the recording transport.
     */
    private function discoverResultFor(Server $server): DiscoverResult
    {
        $result = $this->dispatch($server, 'server/discover');

        self::assertInstanceOf(DiscoverResult::class, $result);

        return $result;
    }

    /**
     * The server identity the built server advertises on its `server/discover` result `_meta`.
     */
    private function serverInfoFor(Server $server): Implementation
    {
        $serverInfo = $this->discoverResultFor($server)->meta->serverInfo;

        if (! $serverInfo instanceof Implementation) {
            self::fail('Expected the discover result "_meta" to carry the server info.');
        }

        return $serverInfo;
    }

    /**
     * Registers three entries of every paginated feature, so a page size of two leaves a remainder.
     */
    private static function registerFeatureTriples(ServerBuilder $builder): ServerBuilder
    {
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $builder
                ->addTool(
                    new Tool(name: $name, inputSchema: ['type' => 'object']),
                    static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: []),
                )
                ->addPrompt(
                    new Prompt(name: $name),
                    static fn(?array $args, $ctx): GetPromptResult => new GetPromptResult(messages: []),
                )
                ->addResource(
                    new Resource(name: $name, uri: \sprintf('mem://%s', $name)),
                    static fn(string $uri, $ctx): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
                ->addResourceTemplate(
                    new ResourceTemplate(name: $name, uriTemplate: \sprintf('mem://%s/{path}', $name)),
                    static fn(string $uri, array $bindings, $ctx): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
                )
            ;
        }

        return $builder;
    }

    /**
     * Drives the built server with a single operation request, returning the
     * typed result of the operation response.
     *
     * @param array<string, mixed> $params
     */
    private function dispatch(Server $server, string $method, array $params = []): Result
    {
        $transport = new RecordingTransport();
        $serverRun = \Amp\async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        $started = $transport->nextSend();

        EventLoop::queue(static function () use ($transport, $method, $params): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => $method,
                'params' => ['_meta' => RequestMetaObjectFactory::shape(), ...$params],
            ]);
        });

        $started->await();

        EventLoop::queue(static function () use ($transport): void {
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
