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

namespace Nexus\Mcp\Extension\Tasks\Client;

use Nexus\Mcp\Client\Extension\ClientExtensionInterface;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Tasks;

/**
 * The official tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) for the client.
 */
final readonly class TasksClientExtension implements ClientExtensionInterface
{
    #[\Override]
    public function getIdentifier(): string
    {
        return Tasks::IDENTIFIER;
    }

    #[\Override]
    public function getSettings(): array
    {
        return [];
    }

    #[\Override]
    public function getRequests(): array
    {
        return [];
    }

    #[\Override]
    public function getNotifications(): array
    {
        return [];
    }

    #[\Override]
    public function getRequestHandlers(): array
    {
        return [];
    }

    #[\Override]
    public function getNotificationHandlers(): array
    {
        return [];
    }

    #[\Override]
    public function getOutboundRequests(): array
    {
        return [
            GetTaskRequest::getMethod(),
            UpdateTaskRequest::getMethod(),
            CancelTaskRequest::getMethod(),
        ];
    }
}
