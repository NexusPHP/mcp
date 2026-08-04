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

namespace Nexus\Mcp\Extension\Tasks\Server\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when a task issues an input-request key it already issued before,
 * violating the per-task lifetime uniqueness of request keys.
 */
final class InputRequestKeyReusedException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $taskId
     */
    public function __construct(string $taskId, string $key)
    {
        parent::__construct(\sprintf('Task "%s" already issued the input-request key "%s".', $taskId, $key));
    }
}
