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

use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Base for an MCP request (the method-specific body of a JSON-RPC request).
 *
 * @internal
 *
 * @template-covariant TMethod of non-empty-string
 *
 * @implements Arrayable<array{method: non-empty-string, params?: array<string, mixed>}>
 */
abstract readonly class Request implements Arrayable
{
    public function __construct(public RequestParams $params)
    {
    }

    /**
     * @return TMethod
     */
    abstract public static function method(): string;

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    /**
     * @return array{method: non-empty-string, params?: array<string, mixed>}
     */
    #[\Override]
    public function toArray(): array
    {
        $out = ['method' => static::method()];
        $params = $this->params->toArray();

        if ([] !== $params) {
            $out['params'] = $params;
        }

        return $out;
    }

    /**
     * @return array{method: non-empty-string, params?: array<string, mixed>}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
