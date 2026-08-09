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

namespace Nexus\Mcp\Extension\Auth\ClientCredentials;

use Nexus\Mcp\Client\ClientExtensionInterface;

/**
 * The official OAuth client credentials extension (`io.modelcontextprotocol/oauth-client-credentials`,
 * SEP-1046) for the client.
 */
final readonly class ClientCredentialsClientExtension implements ClientExtensionInterface
{
    #[\Override]
    public function getIdentifier(): string
    {
        return ClientCredentials::IDENTIFIER;
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
