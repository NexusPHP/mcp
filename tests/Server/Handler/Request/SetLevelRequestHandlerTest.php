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
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\SetLevelRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Server\Handler\Request\SetLevelRequestHandler;
use Nexus\Mcp\Server\Logging\LoggingLevelGate;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SetLevelRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SetLevelRequestHandlerTest extends TestCase
{
    public function testUpdatesTheStoreAndReturnsAnEmptyResult(): void
    {
        $store = new LoggingLevelGate();
        $handler = new SetLevelRequestHandler($store);
        $request = new SetLevelRequest(
            new RequestId(7),
            new SetLevelRequestParams(LoggingLevel::Debug),
        );

        $result = $handler->handle($request, self::makeContext());

        self::assertSame(new EmptyResult()->toArray(), $result->toArray());
        self::assertSame(LoggingLevel::Debug, $store->level);
    }

    public function testOverwritesPreviousLevelOnEachInvocation(): void
    {
        $store = new LoggingLevelGate(LoggingLevel::Debug);
        $handler = new SetLevelRequestHandler($store);

        $handler->handle(
            new SetLevelRequest(new RequestId(1), new SetLevelRequestParams(LoggingLevel::Error)),
            self::makeContext(),
        );

        self::assertSame(LoggingLevel::Error, $store->level);

        $handler->handle(
            new SetLevelRequest(new RequestId(2), new SetLevelRequestParams(LoggingLevel::Warning)),
            self::makeContext(),
        );

        self::assertSame(LoggingLevel::Warning, $store->level);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );
    }
}
