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

namespace Nexus\Mcp\Extension\Skills\Client;

use Nexus\Mcp\Client\Extension\ClientExtensionInterface;
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ReadResourceDirectoryRequest;
use Nexus\Mcp\Extension\Skills\Skills;

/**
 * The official skills extension (`io.modelcontextprotocol/skills`, SEP-2640) for the client.
 */
final readonly class SkillsClientExtension implements ClientExtensionInterface
{
    #[\Override]
    public function getIdentifier(): string
    {
        return Skills::IDENTIFIER;
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
            ListSkillsRequest::getMethod(),
            GetSkillRequest::getMethod(),
            ReadResourceDirectoryRequest::getMethod(),
        ];
    }
}
