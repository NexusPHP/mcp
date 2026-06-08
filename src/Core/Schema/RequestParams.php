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
 * Common params for any request.
 *
 * @implements Arrayable<array{_meta: template-type<RequestMetaObject, Arrayable, 'T'>, ...<string, mixed>}>
 * @implements RequestParamsInterface<array{_meta: template-type<RequestMetaObject, Arrayable, 'T'>, ...<string, mixed>}>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
abstract readonly class RequestParams implements Arrayable, RequestParamsInterface
{
    public function __construct(public RequestMetaObject $meta)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    /**
     * Serializes the params body. Subclasses override to merge their own fields
     * alongside the required `_meta` slice returned here.
     */
    #[\Override]
    public function toArray(): array
    {
        return ['_meta' => $this->meta->toArray()];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['_meta'] = $this->meta->jsonSerialize();

        return $data;
    }
}
