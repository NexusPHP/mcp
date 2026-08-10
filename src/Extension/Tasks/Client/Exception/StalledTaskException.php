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

namespace Nexus\Mcp\Extension\Tasks\Client\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;
use Nexus\Mcp\Core\SafeDisplay;

/**
 * Thrown when a polled task stays `input_required` with no new input requests for the poll loop's stall ceiling.
 */
final class StalledTaskException extends \RuntimeException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $taskId
     */
    public function __construct(string $taskId, int $polls)
    {
        parent::__construct(\sprintf(
            'Task "%s" stayed input_required for %d polls without new input requests.',
            SafeDisplay::sanitise($taskId),
            $polls,
        ));
    }
}
