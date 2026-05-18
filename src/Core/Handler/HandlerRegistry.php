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

/**
 * Method-name to handler dispatch table, generic over the handler interface.
 *
 * @template-covariant THandler of object
 */
final readonly class HandlerRegistry
{
    /**
     * @param array<non-empty-string, THandler> $handlers
     * @param class-string                      $handlerInterface
     * @param non-empty-string                  $label
     */
    public function __construct(
        private array $handlers,
        string $handlerInterface,
        string $label,
    ) {
        Assert::that($handlers)
            ->keys()
            ->isNonEmptyString(\sprintf('%s registry key must be a non-empty string.', $label))
        ;
        Assert::that($handlers)
            ->values()
            ->isInstanceOf(
                $handlerInterface,
                \sprintf('%s registry value must implement {class}.', $label),
            )
        ;
    }

    /**
     * @param non-empty-string $method
     *
     * @return null|THandler
     */
    public function get(string $method): ?object
    {
        return $this->handlers[$method] ?? null;
    }

    /**
     * @return list<non-empty-string>
     */
    public function methods(): array
    {
        return array_keys($this->handlers);
    }
}
