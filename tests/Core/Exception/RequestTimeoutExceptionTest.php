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

use Nexus\Mcp\Core\Exception\RequestTimeoutException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(RequestTimeoutException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestTimeoutExceptionTest extends AbstractMcpTestCase
{
    /**
     * @param int|non-empty-string $id
     */
    #[DataProvider('provideMessageNamesTheRequestAndTheElapsedDeadlineCases')]
    public function testMessageNamesTheRequestAndTheElapsedDeadline(int|string $id, float $seconds, string $expected): void
    {
        self::assertSame($expected, (new RequestTimeoutException(new RequestId(id: $id), $seconds))->getMessage());
    }

    /**
     * @return iterable<string, array{int|non-empty-string, float, string}>
     */
    public static function provideMessageNamesTheRequestAndTheElapsedDeadlineCases(): iterable
    {
        yield 'int id' => [7, 60.0, 'Request 7 went unanswered for 60 seconds.'];

        yield 'string id' => ['req-7', 1.5, 'Request \'req-7\' went unanswered for 1.5 seconds.'];
    }

    public function testCarriesTheRequestIdAndTheCancellationThatFired(): void
    {
        $cause = new \RuntimeException('cancelled');
        $exception = new RequestTimeoutException(new RequestId(id: 3), 30.0, $cause);

        self::assertSame(3, $exception->requestId->id);
        self::assertSame($cause, $exception->getPrevious());
    }

    public function testTheCauseIsOptional(): void
    {
        self::assertNull((new RequestTimeoutException(new RequestId(id: 1), 5.0))->getPrevious());
    }
}
