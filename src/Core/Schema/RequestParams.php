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

use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;

/**
 * Common params for any request.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @implements Arrayable<T>
 * @implements RequestParamsInterface<T>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#requestparams
 */
abstract readonly class RequestParams implements Arrayable, RequestParamsInterface
{
    public function __construct(public RequestMetaObject $meta)
    {
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['_meta'] = $this->meta->jsonSerialize();

        return $data;
    }
}
