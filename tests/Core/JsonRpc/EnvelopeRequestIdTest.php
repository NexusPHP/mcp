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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\EnvelopeRequestId;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EnvelopeRequestId::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EnvelopeRequestIdTest extends TestCase
{
    /**
     * @param array<string, mixed> $envelope
     * @param int|non-empty-string $expected
     */
    #[DataProvider('provideRecoversAnIdInTheMcpDomainCases')]
    public function testRecoversAnIdInTheMcpDomain(array $envelope, int|string $expected): void
    {
        $id = EnvelopeRequestId::recover($envelope);

        self::assertInstanceOf(RequestId::class, $id);
        self::assertSame($expected, $id->id);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int|non-empty-string}>
     */
    public static function provideRecoversAnIdInTheMcpDomainCases(): iterable
    {
        yield 'int' => [['id' => 7], 7];

        yield 'zero' => [['id' => 0], 0];

        yield 'negative int' => [['id' => -1], -1];

        yield 'string' => [['id' => 'req-7'], 'req-7'];

        yield 'numeric string stays a string' => [['id' => '7'], '7'];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[DataProvider('provideRejectsAnIdOutsideTheMcpDomainCases')]
    public function testRejectsAnIdOutsideTheMcpDomain(array $envelope): void
    {
        self::assertNull(EnvelopeRequestId::recover($envelope));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideRejectsAnIdOutsideTheMcpDomainCases(): iterable
    {
        yield 'absent' => [['jsonrpc' => '2.0']];

        // MCP narrows the JSON-RPC id, which allows null, to int|non-empty-string.
        yield 'null' => [['id' => null]];

        yield 'empty string' => [['id' => '']];

        yield 'float' => [['id' => 1.5]];

        yield 'bool' => [['id' => true]];

        yield 'array' => [['id' => [1]]];

        yield 'object' => [['id' => new \stdClass()]];
    }
}
