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
use Nexus\Mcp\Core\Schema\Result;

/**
 * Method name to {@see RequestHandlerInterface} dispatch table.
 *
 * @template TContext of AbstractContext
 */
final readonly class RequestHandlerRegistry
{
    /**
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, TContext>> $handlers
     */
    public function __construct(private array $handlers)
    {
        Assert::that($this->handlers)
            ->keys()
            ->isNonEmptyString('Request handler registry key must be a non-empty string.')
        ;
        Assert::that($this->handlers)
            ->values()
            ->isInstanceOf(RequestHandlerInterface::class, 'Request handler registry value must implement RequestHandlerInterface.')
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
     * @return RequestHandlerInterface<non-empty-string, Result, TContext>
     *
     * @throws MethodNotFoundException
     */
    public function get(string $method): RequestHandlerInterface
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
