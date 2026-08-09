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

namespace Nexus\Mcp\Tests\Fixtures\Server\Extension;

use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\ServerExtensionInterface;

/**
 * Server extension declaration assembled from constructor arguments.
 *
 * @internal
 */
final readonly class StubServerExtension implements ServerExtensionInterface
{
    /**
     * @param non-empty-string                                                                          $identifier
     * @param array<string, mixed>                                                                      $settings
     * @param array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>                   $requests
     * @param array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>              $notifications
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param array<non-empty-string, NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     */
    public function __construct(
        private string $identifier,
        private array $settings = [],
        private array $requests = [],
        private array $notifications = [],
        private array $requestHandlers = [],
        private array $notificationHandlers = [],
    ) {
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    #[\Override]
    public function getSettings(): array
    {
        return $this->settings;
    }

    #[\Override]
    public function getRequests(): array
    {
        return $this->requests;
    }

    #[\Override]
    public function getNotifications(): array
    {
        return $this->notifications;
    }

    #[\Override]
    public function getRequestHandlers(): array
    {
        return $this->requestHandlers;
    }

    #[\Override]
    public function getNotificationHandlers(): array
    {
        return $this->notificationHandlers;
    }
}
