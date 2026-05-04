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

use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;

/**
 * @template-covariant TMethod of non-empty-string
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
abstract readonly class Request
{
    public function __construct(public RequestParams $params = new EmptyRequestParams())
    {
    }

    /**
     * @return TMethod
     */
    abstract public static function method(): string;
}
