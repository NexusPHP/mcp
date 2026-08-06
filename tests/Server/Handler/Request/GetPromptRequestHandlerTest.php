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
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\Handler\Request\GetPromptRequestHandler;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetPromptRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class GetPromptRequestHandlerTest extends AbstractMcpTestCase
{
    public function testForwardsNameArgumentsAndContextToStore(): void
    {
        $captured = ['arguments' => null, 'requestId' => 0];
        $store = new PromptStore([
            'greeting' => new PromptEntry(
                new Prompt(name: 'greeting'),
                new ClosurePromptRenderer(static function (?array $arguments, ServerContext $context) use (&$captured): GetPromptResult {
                    $captured = ['arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return new GetPromptResult(messages: []);
                }),
            ),
        ]);
        $handler = new GetPromptRequestHandler($store);

        $handler->handle(
            new GetPromptRequest(id: new RequestId(id: 42), params: new GetPromptRequestParams(name: 'greeting', meta: RequestMetaObjectFactory::create(), arguments: ['name' => 'Paul'])),
            self::makeContext(),
        );

        self::assertSame(['arguments' => ['name' => 'Paul'], 'requestId' => 99], $captured);
    }

    public function testReturnsResultFromStoreUnchanged(): void
    {
        $expected = new GetPromptResult(messages: []);
        $store = new PromptStore([
            'greeting' => new PromptEntry(
                new Prompt(name: 'greeting'),
                new ClosurePromptRenderer(static fn(?array $arguments, ServerContext $context): GetPromptResult => $expected),
            ),
        ]);
        $handler = new GetPromptRequestHandler($store);

        $result = $handler->handle(
            new GetPromptRequest(id: new RequestId(id: 1), params: new GetPromptRequestParams(name: 'greeting', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertSame($expected, $result);
    }

    public function testPropagatesPromptNotFoundFromStore(): void
    {
        $handler = new GetPromptRequestHandler(new PromptStore());

        $this->expectException(PromptNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No prompt registered under name "missing"\.$/');

        $handler->handle(
            new GetPromptRequest(id: new RequestId(id: 1), params: new GetPromptRequestParams(name: 'missing', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 99),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
