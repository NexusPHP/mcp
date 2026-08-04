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

use Nexus\Mcp\Core\Exception\MissingNotificationClassException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MissingNotificationClassException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MissingNotificationClassExceptionTest extends TestCase
{
    public function testMessageNamesTheMethodAndTheParameter(): void
    {
        self::assertSame(
            'Notification method "vendor/custom-done" is not defined by the MCP specification, so its handler registration must name the $notificationClass that parses it.',
            new MissingNotificationClassException('vendor/custom-done')->getMessage(),
        );
    }
}
