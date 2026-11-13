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

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Extension\RegistrationValidatorInterface;
use Nexus\Mcp\Server\Extension\ServerExtensionInterface;

/**
 * Server extension that records the registrations handed to it at `build()`.
 *
 * @internal
 */
final class RecordingRegistrationValidatorExtension implements RegistrationValidatorInterface, ServerExtensionInterface
{
    /**
     * @var list<array{list<Tool>, list<Resource>, list<ResourceTemplate>}>
     */
    public array $calls = [];

    #[\Override]
    public function validateRegistrations(array $tools, array $resources, array $templates): void
    {
        $this->calls[] = [$tools, $resources, $templates];
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return 'com.example/validator';
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
