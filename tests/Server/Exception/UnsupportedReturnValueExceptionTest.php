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

namespace Nexus\Mcp\Tests\Server\Exception;

use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UnsupportedReturnValueException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnsupportedReturnValueExceptionTest extends AbstractMcpTestCase
{
    public function testComposesMessageWithTheDebugType(): void
    {
        $exception = new UnsupportedReturnValueException('App\\Calculator', 'add', 'a string', 5);

        self::assertSame('App\\Calculator::add() must return a string, int given.', $exception->getMessage());
    }
}
