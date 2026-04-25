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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestId::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestIdTest extends TestCase
{
    public function testRequestIdCapturesIdAsIs(): void
    {
        self::assertSame('12345', new RequestId('12345')->id);
        self::assertSame('abcde', new RequestId('abcde')->id);
        self::assertSame(100, new RequestId(100)->id);
    }

    public function testRequestIdCannotBeEmptyString(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Request ID must be a non-empty string.');

        new RequestId('');
    }
}
