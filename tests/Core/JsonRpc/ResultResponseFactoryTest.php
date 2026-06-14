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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\ResultResponseFactory;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\CompleteRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;
use Nexus\Mcp\Core\Schema\Result;
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
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\CompleteResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\DiscoverResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GetPromptResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListPromptsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListResourcesResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListResourceTemplatesResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ReadResourceResultResponse;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResultResponseFactory::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResultResponseFactoryTest extends TestCase
{
    /**
     * @param JsonRpcRequest<non-empty-string, array<string, mixed>> $request
     * @param Result<array<string, mixed>>                           $result
     * @param class-string                                           $expectedResponse
     */
    #[DataProvider('provideWrapSelectsTheEnvelopeCases')]
    public function testWrapSelectsTheEnvelope(JsonRpcRequest $request, Result $result, string $expectedResponse): void
    {
        $response = ResultResponseFactory::wrap($request, $result);

        self::assertInstanceOf($expectedResponse, $response);
        self::assertSame($result, $response->result);
        self::assertSame($request->id, $response->id);
    }

    /**
     * @return iterable<string, array{JsonRpcRequest<non-empty-string, array<string, mixed>>, Result<array<string, mixed>>, class-string}>
     */
    public static function provideWrapSelectsTheEnvelopeCases(): iterable
    {
        $meta = RequestMetaObjectFactory::create();
        $id = new RequestId(id: 1);
        $inputRequired = InputRequiredResult::fromArray(['resultType' => 'input_required', 'requestState' => 'tok']);

        $callTool = new CallToolRequest(id: $id, params: new CallToolRequestParams(name: 'tool', meta: $meta));
        $getPrompt = new GetPromptRequest(id: $id, params: new GetPromptRequestParams(name: 'prompt', meta: $meta));
        $readResource = new ReadResourceRequest(id: $id, params: new ReadResourceRequestParams(uri: 'file:///x', meta: $meta));

        yield 'tools/call' => [
            $callTool,
            CallToolResult::fromArray(['content' => []]),
            CallToolResultResponse::class,
        ];

        yield 'tools/call input_required' => [
            $callTool,
            $inputRequired,
            CallToolResultResponse::class,
        ];

        yield 'prompts/get' => [
            $getPrompt,
            GetPromptResult::fromArray([
                'messages' => [
                    ['content' => ['text' => 'Hi.', 'type' => 'text'], 'role' => 'user'],
                ],
            ]),
            GetPromptResultResponse::class,
        ];

        yield 'prompts/get input_required' => [
            $getPrompt,
            $inputRequired,
            GetPromptResultResponse::class,
        ];

        yield 'resources/read' => [
            $readResource,
            ReadResourceResult::fromArray([
                'contents' => [['uri' => 'file:///x', 'text' => 'hi']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ]),
            ReadResourceResultResponse::class,
        ];

        yield 'resources/read input_required' => [
            $readResource,
            $inputRequired,
            ReadResourceResultResponse::class,
        ];

        yield 'completion/complete' => [
            new CompleteRequest(
                id: $id,
                params: new CompleteRequestParams(
                    ref: new PromptReference(name: 'prompt'),
                    argument: ['name' => 'a', 'value' => 'b'],
                    meta: $meta,
                ),
            ),
            CompleteResult::fromArray(['completion' => ['values' => []]]),
            CompleteResultResponse::class,
        ];

        yield 'server/discover' => [
            new DiscoverRequest(
                id: $id,
                params: new EmptyRequestParams(meta: $meta),
            ),
            DiscoverResult::fromArray([
                'supportedVersions' => ['2026-07-28'],
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1.0.0'],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ]),
            DiscoverResultResponse::class,
        ];

        yield 'prompts/list' => [
            new ListPromptsRequest(id: $id, params: new PaginatedRequestParams(meta: $meta)),
            ListPromptsResult::fromArray([
                'prompts' => [['name' => 'p']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ]),
            ListPromptsResultResponse::class,
        ];

        yield 'resources/list' => [
            new ListResourcesRequest(id: $id, params: new PaginatedRequestParams(meta: $meta)),
            ListResourcesResult::fromArray([
                'resources' => [['name' => 's', 'uri' => 'file:///s']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ]),
            ListResourcesResultResponse::class,
        ];

        yield 'resources/templates/list' => [
            new ListResourceTemplatesRequest(id: $id, params: new PaginatedRequestParams(meta: $meta)),
            ListResourceTemplatesResult::fromArray([
                'resourceTemplates' => [['name' => 's', 'uriTemplate' => 'file:///{n}']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ]),
            ListResourceTemplatesResultResponse::class,
        ];

        yield 'tools/list' => [
            new ListToolsRequest(id: $id, params: new PaginatedRequestParams(meta: $meta)),
            ListToolsResult::fromArray([
                'tools' => [],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ]),
            ListToolsResultResponse::class,
        ];

        yield 'untyped result falls back to the generic writer' => [
            $callTool,
            new EmptyResult(),
            GenericResultResponse::class,
        ];
    }
}
