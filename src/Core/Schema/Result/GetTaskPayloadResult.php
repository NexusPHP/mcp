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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The response to a tasks/result request.
 * The structure matches the result type of the original request.
 * For example, a tools/call task would return the CallToolResult structure.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#gettaskpayloadresult
 */
final readonly class GetTaskPayloadResult extends Result implements ClientResult, ServerResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public array $payload = [], ?MetaObject $meta = null)
    {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = MetaObject::parseFrom($data, 'Result');

        unset($data['_meta']);

        return new self($data, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            ...$this->payload,
        ];
    }
}
