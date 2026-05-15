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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\Handler\Request\GetPromptRequestHandler;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetPromptRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class GetPromptRequestHandlerTest extends TestCase
{
    public function testForwardsNameArgumentsAndContextToStore(): void
    {
        $captured = ['arguments' => null, 'requestId' => 0];
        $store = new PromptStore([
            'greeting' => [
                'prompt' => new Prompt('greeting'),
                'renderer' => new ClosurePromptRenderer(static function (?array $arguments, ServerContext $context) use (&$captured): GetPromptResult {
                    $captured = ['arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return new GetPromptResult([]);
                }),
            ],
        ]);
        $handler = new GetPromptRequestHandler($store);

        $handler->handle(
            new GetPromptRequest(new RequestId(42), new GetPromptRequestParams('greeting', ['name' => 'Paul'])),
            self::makeContext(),
        );

        self::assertSame(['arguments' => ['name' => 'Paul'], 'requestId' => 99], $captured);
    }

    public function testReturnsResultFromStoreUnchanged(): void
    {
        $expected = new GetPromptResult([]);
        $store = new PromptStore([
            'greeting' => [
                'prompt' => new Prompt('greeting'),
                'renderer' => new ClosurePromptRenderer(static fn(?array $arguments, ServerContext $context): GetPromptResult => $expected),
            ],
        ]);
        $handler = new GetPromptRequestHandler($store);

        $result = $handler->handle(
            new GetPromptRequest(new RequestId(1), new GetPromptRequestParams('greeting')),
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
            new GetPromptRequest(new RequestId(1), new GetPromptRequestParams('missing')),
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
