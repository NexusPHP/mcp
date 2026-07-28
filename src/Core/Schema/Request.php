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

namespace Nexus\Mcp\Core\Schema;

/**
 * @template-covariant TMethod of non-empty-string
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
abstract readonly class Request
{
    public function __construct(public ?RequestParamsInterface $params = null)
    {
    }

    /**
     * @return TMethod
     */
    abstract public static function getMethod(): string;
}
