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

use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ToolOutputValidationException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ToolOutputValidationExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheToolAndJoinsTheErrors(): void
    {
        $errors = ['the n field must be an integer', 'the q field is required'];
        $exception = new ToolOutputValidationException('report', $errors);

        self::assertSame(
            \sprintf(
                'Tool "report" returned structuredContent that does not conform to its outputSchema: %s',
                implode('; ', $errors),
            ),
            $exception->getMessage(),
        );
    }

    public function testMessageNamesTheMissingStructuredContentWhenNoErrorsAreGiven(): void
    {
        self::assertSame(
            'Tool "report" declares an outputSchema but its result carries no structuredContent.',
            (new ToolOutputValidationException('report', []))->getMessage(),
        );
    }

    public function testWrapsThePreviousThrowable(): void
    {
        $previous = new \RuntimeException('root cause');

        self::assertSame($previous, (new ToolOutputValidationException('report', [], $previous))->getPrevious());
    }
}
