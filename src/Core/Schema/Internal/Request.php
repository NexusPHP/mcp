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

namespace Nexus\Mcp\Core\Schema\Internal;

/**
 * @internal
 *
 * @template-covariant TMethod of non-empty-string
 */
abstract readonly class Request
{
    public function __construct(public RequestParams $params = new RequestParams())
    {
    }

    /**
     * @return TMethod
     */
    abstract public static function method(): string;
}
