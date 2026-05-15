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

namespace Nexus\Mcp\Core\Handler;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;

/**
 * Method name to `NotificationHandlerInterface` dispatch table.
 */
final readonly class NotificationHandlerRegistry
{
    /**
     * @param array<non-empty-string, NotificationHandlerInterface<non-empty-string>> $handlers
     */
    public function __construct(private array $handlers)
    {
        Assert::that($this->handlers)
            ->keys()
            ->isNonEmptyString('Notification handler registry key must be a non-empty string.')
        ;
        Assert::that($this->handlers)
            ->values()
            ->isInstanceOf(NotificationHandlerInterface::class, 'Notification handler registry value must implement NotificationHandlerInterface.')
        ;
    }

    /**
     * @param non-empty-string $method
     */
    public function has(string $method): bool
    {
        return \array_key_exists($method, $this->handlers);
    }

    /**
     * @param non-empty-string $method
     *
     * @return NotificationHandlerInterface<non-empty-string>
     *
     * @throws MethodNotFoundException
     */
    public function get(string $method): NotificationHandlerInterface
    {
        if (! \array_key_exists($method, $this->handlers)) {
            throw new MethodNotFoundException($method);
        }

        return $this->handlers[$method];
    }

    /**
     * @return list<non-empty-string>
     */
    public function methods(): array
    {
        return array_keys($this->handlers);
    }
}
