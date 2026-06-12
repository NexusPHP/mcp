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
use Nexus\Mcp\Core\Schema\Notification\ElicitationCompleteNotification;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\PromptListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;

use function PHPStan\Testing\assertType;

assertType('\'notifications/cancelled\'', CancelledNotification::getMethod());
assertType('\'notifications/elicitation/complete\'', ElicitationCompleteNotification::getMethod());
assertType('\'notifications/message\'', LoggingMessageNotification::getMethod());
assertType('\'notifications/progress\'', ProgressNotification::getMethod());
assertType('\'notifications/prompts/list_changed\'', PromptListChangedNotification::getMethod());
assertType('\'notifications/resources/list_changed\'', ResourceListChangedNotification::getMethod());
assertType('\'notifications/resources/updated\'', ResourceUpdatedNotification::getMethod());
assertType('\'notifications/subscriptions/acknowledged\'', SubscriptionsAcknowledgedNotification::getMethod());
assertType('\'notifications/tools/list_changed\'', ToolListChangedNotification::getMethod());
