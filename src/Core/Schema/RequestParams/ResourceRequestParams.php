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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Common params for resource-related requests.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @extends RequestParams<T>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
abstract readonly class ResourceRequestParams extends RequestParams
{
    public function __construct(public string $uri, RequestMetaObject $meta)
    {
        parent::__construct(meta: $meta);
    }
}
