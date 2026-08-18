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

namespace Nexus\Mcp\Extension\Apps\Server;

use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Server\Extension\ServerExtensionInterface;

/**
 * The official MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) for the server.
 */
final readonly class AppsServerExtension implements ServerExtensionInterface
{
    #[\Override]
    public function getIdentifier(): string
    {
        return Apps::IDENTIFIER;
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
}
