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

use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;

/**
 * Common result fields.
 *
 * @template-covariant T of array<string, mixed> = array<string, mixed>
 *
 * @implements Arrayable<T>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#result
 */
abstract readonly class Result implements Arrayable
{
    public function __construct(public ResultMetaObject $meta = new GenericResultMetaObject())
    {
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @internal
     */
    abstract public function rebuildWithMeta(ResultMetaObject $meta): static;

    /**
     * @return non-empty-string
     */
    abstract protected function getResultType(): string;
}
