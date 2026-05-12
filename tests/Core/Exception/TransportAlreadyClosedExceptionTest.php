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

use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TransportAlreadyClosedException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TransportAlreadyClosedExceptionTest extends TestCase
{
    /**
     * @param non-empty-string $operation
     */
    #[DataProvider('provideRendersOperationIntoMessageCases')]
    public function testRendersOperationIntoMessage(string $operation, string $expected): void
    {
        $e = new TransportAlreadyClosedException($operation);

        self::assertSame($expected, $e->getMessage());
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function provideRendersOperationIntoMessageCases(): iterable
    {
        yield 'send' => ['send', 'Cannot send on a closed transport.'];

        yield 'start' => ['start', 'Cannot start on a closed transport.'];
    }
}
