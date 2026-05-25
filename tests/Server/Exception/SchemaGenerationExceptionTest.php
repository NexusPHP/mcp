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

use Nexus\Mcp\Server\Exception\SchemaGenerationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SchemaGenerationException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SchemaGenerationExceptionTest extends TestCase
{
    public function testComposesParameterContext(): void
    {
        $exception = new SchemaGenerationException('App\\Calculator', 'add', 'value', 'Reason here.');

        self::assertSame(
            'Cannot generate the input schema for parameter "$value" of App\\Calculator::add(). Reason here. Add #[InputSchema(...)] to describe it explicitly.',
            $exception->getMessage(),
        );
        self::assertNull($exception->getPrevious());
    }

    public function testKeepsThePreviousThrowable(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new SchemaGenerationException('App\\Calculator', 'add', 'value', 'Reason here.', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
