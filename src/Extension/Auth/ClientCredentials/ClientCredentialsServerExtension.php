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

use Nexus\Mcp\Server\ServerExtensionInterface;

/**
 * The official OAuth client credentials extension (`io.modelcontextprotocol/oauth-client-credentials`,
 * SEP-1046) for the server: advertises the extension slot for discoverability. The extension defines no
 * JSON-RPC methods, so there is nothing to serve or gate.
 */
final readonly class ClientCredentialsServerExtension implements ServerExtensionInterface
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
}
