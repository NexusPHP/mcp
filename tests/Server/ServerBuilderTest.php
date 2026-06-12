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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Exception\DuplicateServerMetadataException;
use Nexus\Mcp\Server\Exception\MissingDiscoveryAttributeException;
use Nexus\Mcp\Server\Exception\ReservedMethodException;
use Nexus\Mcp\Server\Exception\UnreservedMethodException;
use Nexus\Mcp\Server\Prompt\PromptStoreInterface;
use Nexus\Mcp\Server\Resource\ResourceStoreInterface;
use Nexus\Mcp\Server\Resource\ResourceTemplateStoreInterface;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Server\Completion\RecordingCompletionStore;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\DiscoverableServer;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SelfDescribingServer;
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
        $this->expectExceptionMessageIs('Server information must be set before build() via setServerInfo() or a class-level #[AsServer].');

        new ServerBuilder()->build();
    }

    public function testSetServerInfoRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Implementation name must be a non-empty string.');

        new ServerBuilder()->setServerInfo('', '1.0.0');
    }

    public function testSetServerInfoRejectsEmptyVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"version" must be a non-empty string.');

        new ServerBuilder()->setServerInfo('demo', '');
    }

    public function testSetInstructionsRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Server instructions must be a non-empty string or null.');

        new ServerBuilder()->setInstructions('');
    }

    public function testDiscoverOnEmptyServerAdvertisesNoCapabilities(): void
    {
        $result = $this->discoverResultFor(new ServerBuilder()->setServerInfo('demo', '1.0.0')->build());

        self::assertNull($result->capabilities->tools);
        self::assertNull($result->capabilities->prompts);
        self::assertNull($result->capabilities->resources);
        self::assertNull($result->capabilities->completions);
        self::assertSame([], $result->capabilities->toArray());
    }

    public function testCapabilitiesIncludeToolsWhenToolRegistered(): void
    {
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->setCompletionStore(new RecordingCompletionStore(new CompleteResult(completion: ['values' => []])))
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame([], $result->capabilities->completions);
    }

    public function testReplacingBothToolsHandlersEnablesToolsCapability(): void
    {
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->setInstructions('Greet the user warmly.')
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame('Greet the user warmly.', $result->instructions);
    }

    public function testServerInfoMatchesNameAndVersion(): void
    {
        $server = new ServerBuilder()->setServerInfo('demo-srv', '2.3.4')->build();

        $result = $this->discoverResultFor($server);

        self::assertSame('demo-srv', $result->serverInfo->name);
        self::assertSame('2.3.4', $result->serverInfo->version);
    }

    public function testRegisteredToolFlowsThroughBuiltServer(): void
    {
        $server = new ServerBuilder()
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

        $server = new ServerBuilder()
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

    public function testRegisteredPromptFlowsThroughBuiltServer(): void
    {
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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

        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame('described-server', $result->serverInfo->name);
        self::assertSame('2.3.4', $result->serverInfo->version);
        self::assertSame('Described Server', $result->serverInfo->title);
        self::assertSame('A server described entirely by attributes.', $result->serverInfo->description);
        self::assertSame('https://nexus.test', $result->serverInfo->websiteUrl);
        self::assertSame('Call the tools politely.', $result->instructions);
        self::assertSame([], $result->capabilities->tools);
    }

    public function testExplicitServerInfoFieldsWinAndAttributeFillsGaps(): void
    {
        $server = new ServerBuilder()
            ->setServerInfo('explicit-server', '9.9.9')
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $result = $this->discoverResultFor($server);
        $info = $result->serverInfo;

        self::assertSame('explicit-server', $info->name);
        self::assertSame('9.9.9', $info->version);
        self::assertSame('Described Server', $info->title);
        self::assertSame('A server described entirely by attributes.', $info->description);
        self::assertSame('https://nexus.test', $info->websiteUrl);
        self::assertSame('https://nexus.test/icon.svg', self::firstIcon($info)->src);
    }

    public function testExplicitServerInfoFieldsTakePrecedencePerField(): void
    {
        $server = new ServerBuilder()
            ->setServerInfo('explicit-server', '9.9.9', 'Explicit Title', 'Explicit description.', 'https://explicit.test', [new Icon(src: 'https://explicit.test/icon.svg')])
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $result = $this->discoverResultFor($server);
        $info = $result->serverInfo;

        self::assertSame('Explicit Title', $info->title);
        self::assertSame('Explicit description.', $info->description);
        self::assertSame('https://explicit.test', $info->websiteUrl);
        self::assertSame('https://explicit.test/icon.svg', self::firstIcon($info)->src);
    }

    public function testAttributeWithoutInstructionsLeavesThemNull(): void
    {
        $source = new #[AsServer(name: 'minimal', version: '1.0.0')] class {};

        $result = $this->discoverResultFor(new ServerBuilder()->register($source)->build());

        self::assertNull($result->instructions);
        self::assertSame('minimal', $result->serverInfo->name);
    }

    public function testRegisterRejectsEmptyInstructionsFromAttribute(): void
    {
        $source = new #[AsServer(name: 'x', version: '1.0.0', instructions: '')] class {};

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Server instructions must be a non-empty string or null.');

        new ServerBuilder()->register($source)->build();
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

        $result = $this->discoverResultFor(
            new ServerBuilder()->register(new SelfDescribingServer(), $extra)->build(),
        );

        self::assertSame('described-server', $result->serverInfo->name);
    }

    public function testMultipleServerAttributesAcrossSourcesThrow(): void
    {
        $second = new #[AsServer(name: 'second-server', version: '5.0.0')] class {};

        $this->expectException(DuplicateServerMetadataException::class);

        new ServerBuilder()->register(new SelfDescribingServer(), $second);
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
        new ServerBuilder()->register($source);
    }

    public function testExplicitInstructionsTakePrecedenceOverAttribute(): void
    {
        $server = new ServerBuilder()
            ->setInstructions('Explicit instructions win.')
            ->register(new SelfDescribingServer())
            ->build()
        ;

        $result = $this->discoverResultFor($server);

        self::assertSame('Explicit instructions win.', $result->instructions);
    }

    public function testAddResourceTemplateRejectsUnsupportedTemplateAtRegistration(): void
    {
        $builder = new ServerBuilder()->setServerInfo('demo', '1.0.0');

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
        $server = new ServerBuilder()
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

    public function testAddRequestHandlerAcceptsVendorExtensionMethod(): void
    {
        $this->expectNotToPerformAssertions();

        new ServerBuilder()
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
        $this->expectException(ReservedMethodException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Request method "%s" is reserved by the MCP specification. Use replaceRequestHandler() to attach a handler to it.',
            $method,
        ));

        new ServerBuilder()->addRequestHandler($method, new ClosureRequestHandler(
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

        yield 'prompts/get' => ['prompts/get'];

        yield 'prompts/list' => ['prompts/list'];

        yield 'resources/list' => ['resources/list'];

        yield 'resources/read' => ['resources/read'];

        yield 'resources/templates/list' => ['resources/templates/list'];

        yield 'server/discover' => ['server/discover'];

        yield 'tools/call' => ['tools/call'];

        yield 'tools/list' => ['tools/list'];
    }

    public function testAddNotificationHandlerAcceptsVendorExtensionMethod(): void
    {
        $this->expectNotToPerformAssertions();

        new ServerBuilder()
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
        $this->expectException(ReservedMethodException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Notification method "%s" is reserved by the MCP specification. Use replaceNotificationHandler() to attach a handler to it.',
            $method,
        ));

        new ServerBuilder()->addNotificationHandler($method, new ClosureNotificationHandler(
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

        yield 'notifications/progress' => ['notifications/progress'];

        yield 'notifications/prompts/list_changed' => ['notifications/prompts/list_changed'];

        yield 'notifications/resources/list_changed' => ['notifications/resources/list_changed'];

        yield 'notifications/resources/updated' => ['notifications/resources/updated'];

        yield 'notifications/tools/list_changed' => ['notifications/tools/list_changed'];
    }

    public function testReplaceRequestHandlerRejectsVendorExtensionMethod(): void
    {
        $this->expectException(UnreservedMethodException::class);
        $this->expectExceptionMessageIs(
            'Request method "acme/snapshot" is not reserved by the MCP specification. Use addRequestHandler() to register a vendor extension.',
        );

        new ServerBuilder()->replaceRequestHandler('acme/snapshot', new ClosureRequestHandler(
            static fn(): EmptyResult => new EmptyResult(),
        ));
    }

    public function testReplaceNotificationHandlerRejectsVendorExtensionMethod(): void
    {
        $this->expectException(UnreservedMethodException::class);
        $this->expectExceptionMessageIs(
            'Notification method "acme/snapshot-done" is not reserved by the MCP specification. Use addNotificationHandler() to register a vendor extension.',
        );

        new ServerBuilder()->replaceNotificationHandler('acme/snapshot-done', new ClosureNotificationHandler(
            static function (): void {},
        ));
    }

    public function testCustomNotificationHandlerIsDispatched(): void
    {
        $invoked = 0;
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setToolStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->tools);

        $storeOnly = $this->dispatch(
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setToolStore($store)->build(),
            'tools/list',
        );
        self::assertInstanceOf(ListToolsResult::class, $storeOnly);
        self::assertSame(['custom_tool'], array_map(static fn(Tool $tool): string => $tool->name, $storeOnly->tools));

        $withEntry = $this->dispatch(
            new ServerBuilder()
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
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setPromptStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->prompts);

        $storeOnly = $this->dispatch(
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setPromptStore($store)->build(),
            'prompts/list',
        );
        self::assertInstanceOf(ListPromptsResult::class, $storeOnly);
        self::assertSame(['custom_prompt'], array_map(static fn(Prompt $prompt): string => $prompt->name, $storeOnly->prompts));

        $withEntry = $this->dispatch(
            new ServerBuilder()
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
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setResourceStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->resources);

        $storeOnly = $this->dispatch(
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setResourceStore($store)->build(),
            'resources/list',
        );
        self::assertInstanceOf(ListResourcesResult::class, $storeOnly);
        self::assertSame(['mem://custom'], array_map(static fn(Resource $resource): string => $resource->uri, $storeOnly->resources));

        $withEntry = $this->dispatch(
            new ServerBuilder()
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
            new ServerBuilder()
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
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setResourceTemplateStore($store)->build(),
        )->capabilities;
        self::assertSame([], $capabilities->resources);

        $storeOnly = $this->dispatch(
            new ServerBuilder()->setServerInfo('demo', '1.0.0')->setResourceTemplateStore($store)->build(),
            'resources/templates/list',
        );
        self::assertInstanceOf(ListResourceTemplatesResult::class, $storeOnly);
        self::assertSame(['mem://{id}'], array_map(static fn(ResourceTemplate $template): string => $template->uriTemplate, $storeOnly->resourceTemplates));

        $withEntry = $this->dispatch(
            new ServerBuilder()
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
     * Drives the built server with a single operation request, returning the
     * typed result of the operation response.
     *
     * @param array<string, mixed> $params
     *
     * @return Result<array<string, mixed>>
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
