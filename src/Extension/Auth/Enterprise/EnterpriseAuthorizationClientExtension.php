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

namespace Nexus\Mcp\Extension\Auth\Enterprise;

use Nexus\Mcp\Client\Extension\ClientExtensionInterface;

/**
 * The official enterprise-managed authorization extension
 * (`io.modelcontextprotocol/enterprise-managed-authorization`, SEP-990) for the client.
 */
final readonly class EnterpriseAuthorizationClientExtension implements ClientExtensionInterface
{
    #[\Override]
    public function getIdentifier(): string
    {
        return EnterpriseAuthorization::IDENTIFIER;
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
        return [];
    }
}
