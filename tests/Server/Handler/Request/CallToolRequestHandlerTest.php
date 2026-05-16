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
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Handler\Request\CallToolRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CallToolRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CallToolRequestHandlerTest extends TestCase
{
    public function testForwardsNameArgumentsAndContextToStore(): void
    {
        $captured = ['arguments' => null, 'requestId' => 0];
        $store = new ToolStore([
            'echo' => new ToolEntry(
                new Tool('echo', ['type' => 'object']),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use (&$captured): CallToolResult {
                    $captured = ['arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return new CallToolResult([]);
                }),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $handler->handle(
            new CallToolRequest(new RequestId(42), new CallToolRequestParams('echo', ['x' => 1])),
            self::makeContext(),
        );

        self::assertSame(['arguments' => ['x' => 1], 'requestId' => 99], $captured);
    }

    public function testReturnsResultFromStoreUnchanged(): void
    {
        $expected = new CallToolResult([]);
        $store = new ToolStore([
            'echo' => new ToolEntry(
                new Tool('echo', ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => $expected),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $result = $handler->handle(
            new CallToolRequest(new RequestId(1), new CallToolRequestParams('echo')),
            self::makeContext(),
        );

        self::assertSame($expected, $result);
    }

    public function testPropagatesToolNotFoundFromStore(): void
    {
        $handler = new CallToolRequestHandler(new ToolStore());

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No tool registered under name "missing"\.$/');

        $handler->handle(
            new CallToolRequest(new RequestId(1), new CallToolRequestParams('missing')),
            self::makeContext(),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(99),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );
    }
}
