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

namespace Nexus\Mcp\Tests\Core\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Handler\Request\PingRequestHandler;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PingRequestHandler::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PingRequestHandlerTest extends TestCase
{
    public function testReturnsEmptyResult(): void
    {
        $handler = new PingRequestHandler();

        $result = $handler->handle(self::makeRequest(), self::makeContext());

        self::assertSame(new EmptyResult()->toArray(), $result->toArray());
    }

    public function testPropagatesMetaWhenProvided(): void
    {
        $meta = new MetaObject(['trace' => 'abc']);
        $handler = new PingRequestHandler($meta);

        $result = $handler->handle(self::makeRequest(), self::makeContext());

        self::assertSame(['_meta' => ['trace' => 'abc']], $result->toArray());
    }

    private static function makeRequest(): PingRequest
    {
        return new PingRequest(new RequestId(1));
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
