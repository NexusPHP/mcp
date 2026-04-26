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

use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;

use function PHPStan\Testing\assertType;

assertType('\'notifications/cancelled\'', CancelledNotification::method());
assertType('\'notifications/initialized\'', InitializedNotification::method());
