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

namespace Nexus\Mcp\Tests\Core\Exception;

use Nexus\Mcp\Core\Exception\ResponseTooLargeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResponseTooLargeException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResponseTooLargeExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheLimitThatWasExceeded(): void
    {
        self::assertSame(
            'The response exceeded the 1024 byte limit the client accepts.',
            (new ResponseTooLargeException(1024))->getMessage(),
        );
    }

    public function testCarriesTheUnderlyingFaultWhenThereIsOne(): void
    {
        $cause = new \RuntimeException('buffer limit reached');

        self::assertSame($cause, (new ResponseTooLargeException(1024, $cause))->getPrevious());
    }

    public function testTheCauseIsOptional(): void
    {
        self::assertNull((new ResponseTooLargeException(1024))->getPrevious());
    }
}
