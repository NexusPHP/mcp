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

use Nexus\Mcp\Server\Exception\UnsupportedSchemaTypeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsupportedSchemaTypeException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnsupportedSchemaTypeExceptionTest extends TestCase
{
    public function testNamesTheUnsupportedType(): void
    {
        $exception = new UnsupportedSchemaTypeException('App\\Money');

        self::assertSame('Type "App\\Money" is not supported by the input schema generator.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }
}
