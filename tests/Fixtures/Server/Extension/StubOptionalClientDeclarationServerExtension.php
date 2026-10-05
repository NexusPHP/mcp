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

use Nexus\Mcp\Server\Extension\OptionalClientDeclarationInterface;
use Nexus\Mcp\Server\Extension\ServerExtensionInterface;

/**
 * Server extension that a client need not declare, answering from the wrapped extension.
 *
 * @internal
 */
final readonly class StubOptionalClientDeclarationServerExtension implements OptionalClientDeclarationInterface, ServerExtensionInterface
{
    public function __construct(private ServerExtensionInterface $extension)
    {
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return $this->extension->getIdentifier();
    }

    #[\Override]
    public function getSettings(): array
    {
        return $this->extension->getSettings();
    }

    #[\Override]
    public function getRequests(): array
    {
        return $this->extension->getRequests();
    }

    #[\Override]
    public function getNotifications(): array
    {
        return $this->extension->getNotifications();
    }

    #[\Override]
    public function getRequestHandlers(): array
    {
        return $this->extension->getRequestHandlers();
    }

    #[\Override]
    public function getNotificationHandlers(): array
    {
        return $this->extension->getNotificationHandlers();
    }
}
