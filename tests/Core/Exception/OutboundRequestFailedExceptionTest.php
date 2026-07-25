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

use Nexus\Mcp\Core\Exception\OutboundRequestFailedException;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OutboundRequestFailedException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class OutboundRequestFailedExceptionTest extends TestCase
{
    /**
     * @param int|non-empty-string $id
     */
    #[DataProvider('provideMessageEmitsTheExportedIdCases')]
    public function testMessageEmitsTheExportedId(int|string $id, string $expected): void
    {
        self::assertSame(
            $expected,
            new OutboundRequestFailedException(new RequestId(id: $id), new \RuntimeException('boom'))->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{int|non-empty-string, string}>
     */
    public static function provideMessageEmitsTheExportedIdCases(): iterable
    {
        yield 'int id' => [7, 'The exchange carrying request 7 failed before a response arrived.'];

        yield 'string id' => ['req-7', 'The exchange carrying request \'req-7\' failed before a response arrived.'];
    }

    public function testCarriesTheRequestIdAndTheUnderlyingFault(): void
    {
        $cause = new \RuntimeException('connection refused');
        $exception = new OutboundRequestFailedException(new RequestId(id: 3), $cause);

        self::assertSame(3, $exception->requestId->id);
        self::assertSame($cause, $exception->getPrevious());
    }
}
