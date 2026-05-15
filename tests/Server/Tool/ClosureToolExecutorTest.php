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

namespace Nexus\Mcp\Tests\Server\Tool;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClosureToolExecutor::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ClosureToolExecutorTest extends TestCase
{
    public function testForwardsArgumentsAndContextToClosure(): void
    {
        $captured = ['arguments' => null, 'requestId' => 0];
        $expected = new CallToolResult([]);
        $executor = new ClosureToolExecutor(
            static function (?array $arguments, ServerContext $context) use ($expected, &$captured): CallToolResult {
                $captured = ['arguments' => $arguments, 'requestId' => $context->requestId->id];

                return $expected;
            },
        );

        $result = $executor->execute(['x' => 1], self::makeContext());

        self::assertSame($expected, $result);
        self::assertSame(['arguments' => ['x' => 1], 'requestId' => 7], $captured);
    }

    public function testForwardsNullArgumentsToClosure(): void
    {
        $captured = ['arguments' => ['marker']];
        $executor = new ClosureToolExecutor(
            static function (?array $arguments, ServerContext $context) use (&$captured): CallToolResult {
                $captured = ['arguments' => $arguments];

                return new CallToolResult([]);
            },
        );

        $executor->execute(null, self::makeContext());

        self::assertNull($captured['arguments']);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(7),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );
    }
}
