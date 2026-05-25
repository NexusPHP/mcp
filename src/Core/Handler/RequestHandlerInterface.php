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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result;

/**
 * Handles a single inbound JSON-RPC request and returns the typed result.
 *
 * `TMethod` binds the literal returned by `static::getMethod()` on the matching
 * request class so the registry can store handlers heterogeneously. It does
 * not narrow `handle()`'s parameter, which stays at the wide envelope type.
 *
 * @template-covariant TMethod of non-empty-string
 * @template-covariant TResult of Result
 * @template-contravariant TContext of AbstractContext
 */
interface RequestHandlerInterface
{
    /**
     * @param JsonRpcRequest<non-empty-string> $request
     * @param TContext                         $context
     *
     * @return TResult
     */
    public function handle(JsonRpcRequest $request, AbstractContext $context): Result;
}
