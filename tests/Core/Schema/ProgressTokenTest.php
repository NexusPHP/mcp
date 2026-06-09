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
use Nexus\Mcp\Core\Schema\ProgressToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProgressToken::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProgressTokenTest extends TestCase
{
    public function testProgressTokenCapturesTokenAsIs(): void
    {
        self::assertSame('token123', new ProgressToken(token: 'token123')->token);
        self::assertSame('xyz789', new ProgressToken(token: 'xyz789')->token);
        self::assertSame(456, new ProgressToken(token: 456)->token);
    }

    public function testProgressTokenCannotBeEmptyString(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"progressToken" must be a non-empty string.');

        new ProgressToken(token: '');
    }
}
