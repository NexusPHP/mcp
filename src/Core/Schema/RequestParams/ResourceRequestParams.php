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

use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Common parameters when working with resources.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resourcerequestparams
 */
abstract readonly class ResourceRequestParams extends RequestParams
{
    public function __construct(public string $uri, ?RequestMeta $meta = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'uri' => $this->uri,
        ]);
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
