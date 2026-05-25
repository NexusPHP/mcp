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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsupportedReturnValueException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnsupportedReturnValueExceptionTest extends TestCase
{
    public function testComposesMessageWithTheDebugType(): void
    {
        $exception = new UnsupportedReturnValueException('App\\Calculator', 'add', 'a string', 5);

        self::assertSame('App\\Calculator::add() must return a string, int given.', $exception->getMessage());
    }
}
