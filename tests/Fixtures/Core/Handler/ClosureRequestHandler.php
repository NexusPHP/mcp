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

namespace Nexus\Mcp\Tests\Fixtures\Core\Handler;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result;

/**
 * Adapts a closure into a `RequestHandlerInterface` for ad-hoc test wiring.
 *
 * @internal
 *
 * @implements RequestHandlerInterface<non-empty-string, Result, AbstractContext>
 */
final readonly class ClosureRequestHandler implements RequestHandlerInterface
{
    /**
     * @param \Closure(JsonRpcRequest<non-empty-string>, AbstractContext): Result $handler
     */
    public function __construct(private \Closure $handler)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): Result
    {
        return ($this->handler)($request, $context);
    }
}
