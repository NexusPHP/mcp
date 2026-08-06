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

use Nexus\Mcp\Server\Exception\InvalidCompletionAttributeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\CompletionHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InvalidCompletionAttributeException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InvalidCompletionAttributeExceptionTest extends AbstractMcpTestCase
{
    public function testNamesTheMethodAndTheReason(): void
    {
        $exception = new InvalidCompletionAttributeException(
            CompletionHandlers::class,
            'completeTone',
            'its "argument" must be a non-empty string',
        );

        self::assertSame(
            'Nexus\Mcp\Tests\Fixtures\Server\Discovery\CompletionHandlers::completeTone() declares an invalid #[AsCompletion] attribute: its "argument" must be a non-empty string.',
            $exception->getMessage(),
        );
    }
}
