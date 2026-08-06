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

use Nexus\Mcp\Server\Exception\BuilderAlreadyBuiltException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(BuilderAlreadyBuiltException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class BuilderAlreadyBuiltExceptionTest extends AbstractMcpTestCase
{
    public function testMessagePointsAtConstructingANewBuilder(): void
    {
        self::assertSame(
            'This builder has already been built. Construct a new ServerBuilder for another server.',
            (new BuilderAlreadyBuiltException())->getMessage(),
        );
    }
}
