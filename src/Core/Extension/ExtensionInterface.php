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

namespace Nexus\Mcp\Core\Extension;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result;

/**
 * One MCP extension declaration (SEP-2133).
 *
 * @template TContext of AbstractContext
 *
 * @see https://modelcontextprotocol.io/seps/2133-extensions
 */
interface ExtensionInterface
{
    /**
     * The `{vendor-prefix}/{name}` capability identifier, e.g. "io.modelcontextprotocol/tasks".
     *
     * @return non-empty-string
     */
    public function getIdentifier(): string;

    /**
     * Settings advertised under `capabilities.extensions[identifier]`, empty meaning supported with none.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array;

    /**
     * @return array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    public function getRequests(): array;

    /**
     * @return array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    public function getNotifications(): array;

    /**
     * Handlers for `getRequests()`, under the same method keys.
     *
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, TContext>>
     */
    public function getRequestHandlers(): array;

    /**
     * Handlers for `getNotifications()`, under the same method keys.
     *
     * @return array<non-empty-string, NotificationHandlerInterface<non-empty-string>>
     */
    public function getNotificationHandlers(): array;
}
