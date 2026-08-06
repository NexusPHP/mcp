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

use Nexus\Mcp\Core\Exception\UnexpectedHttpStatusException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UnexpectedHttpStatusException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class UnexpectedHttpStatusExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheStatus(): void
    {
        $exception = new UnexpectedHttpStatusException(503);

        self::assertSame('The endpoint answered 503 where 200 or 202 was expected.', $exception->getMessage());
        self::assertSame(503, $exception->status);
        self::assertNull($exception->body);
    }

    public function testTheBodyIsRetainedTruncated(): void
    {
        $exception = new UnexpectedHttpStatusException(502, str_repeat('a', 9_000));

        self::assertSame(str_repeat('a', 8_192), $exception->body);
    }

    public function testAShortBodyIsKeptWhole(): void
    {
        self::assertSame('{"error":"x"}', (new UnexpectedHttpStatusException(400, '{"error":"x"}'))->body);
    }
}
