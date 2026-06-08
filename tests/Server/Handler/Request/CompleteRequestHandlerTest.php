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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CompleteRequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Handler\Request\CompleteRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Completion\RecordingCompletionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CompleteRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CompleteRequestHandlerTest extends TestCase
{
    public function testDelegatesPromptRefToStoreWithDeconstructedParams(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(['values' => ['suggestion']]));

        $handler = new CompleteRequestHandler($store);
        $context = self::makeContext();
        $request = new CompleteRequest(
            new RequestId(7),
            new CompleteRequestParams(
                new PromptReference('my-prompt'),
                ['name' => 'arg', 'value' => 'partial'],
                RequestMetaObjectFactory::create(),
                ['arguments' => ['other' => 'context-value']],
            ),
        );

        $result = $handler->handle($request, $context);

        self::assertSame(['suggestion'], $result->completion['values']);

        $call = self::firstCall($store);
        $ref = $call['ref'];

        if (! $ref instanceof PromptReference) {
            self::fail('Expected PromptReference.');
        }

        self::assertSame('my-prompt', $ref->name);
        self::assertSame('arg', $call['argumentName']);
        self::assertSame('partial', $call['argumentValue']);
        self::assertSame(['other' => 'context-value'], $call['contextArguments']);
        self::assertSame($context, $call['context']);
    }

    public function testDelegatesTemplateRefToStoreWithDeconstructedParams(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(['values' => ['report.pdf']]));

        $handler = new CompleteRequestHandler($store);
        $request = new CompleteRequest(
            new RequestId(8),
            new CompleteRequestParams(
                new ResourceTemplateReference('file:///{folder}/{filename}'),
                ['name' => 'filename', 'value' => 'rep'],
                RequestMetaObjectFactory::create(),
                ['arguments' => ['folder' => 'docs']],
            ),
        );

        $result = $handler->handle($request, self::makeContext());

        self::assertSame(['report.pdf'], $result->completion['values']);

        $call = self::firstCall($store);
        $ref = $call['ref'];

        if (! $ref instanceof ResourceTemplateReference) {
            self::fail('Expected ResourceTemplateReference.');
        }

        self::assertSame('file:///{folder}/{filename}', $ref->uri);
        self::assertSame('filename', $call['argumentName']);
        self::assertSame(['folder' => 'docs'], $call['contextArguments']);
    }

    public function testPassesNullContextArgumentsWhenParamsHaveNoContext(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(['values' => []]));

        $handler = new CompleteRequestHandler($store);
        $request = new CompleteRequest(
            new RequestId(9),
            new CompleteRequestParams(
                new PromptReference('p'),
                ['name' => 'a', 'value' => ''],
                RequestMetaObjectFactory::create(),
            ),
        );

        $handler->handle($request, self::makeContext());

        self::assertNull(self::firstCall($store)['contextArguments']);
    }

    public function testPassesNullContextArgumentsWhenContextOmitsArguments(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(['values' => []]));

        $handler = new CompleteRequestHandler($store);
        $request = new CompleteRequest(
            new RequestId(10),
            new CompleteRequestParams(
                new PromptReference('p'),
                ['name' => 'a', 'value' => ''],
                RequestMetaObjectFactory::create(),
                [],
            ),
        );

        $handler->handle($request, self::makeContext());

        self::assertNull(self::firstCall($store)['contextArguments']);
    }

    /**
     * @return array{
     *     ref: PromptReference|ResourceTemplateReference,
     *     argumentName: string,
     *     argumentValue: string,
     *     contextArguments: ?array<string, string>,
     *     context: ServerContext,
     * }
     */
    private static function firstCall(RecordingCompletionStore $store): array
    {
        if ([] === $store->calls) {
            self::fail('Expected at least one recorded call.');
        }

        return $store->calls[0];
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }
}
