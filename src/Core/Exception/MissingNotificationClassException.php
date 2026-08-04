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

namespace Nexus\Mcp\Core\Exception;

/**
 * Thrown when a handler is registered for a vendor notification method without the class that parses it.
 */
final class MissingNotificationClassException extends \LogicException implements McpExceptionInterface
{
    public function __construct(string $method)
    {
        parent::__construct(\sprintf(
            'Notification method "%s" is not defined by the MCP specification, so its handler registration must name the $notificationClass that parses it.',
            $method,
        ));
    }
}
