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

use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Result;

/**
 * A result that carries no fields beyond the optional `_meta`. Used by methods
 * like `ping` whose acknowledgement has no payload.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#emptyresult
 */
final readonly class EmptyResult extends Result implements ClientResult, ServerResult
{
    public function __construct(?Meta $meta = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = Meta::parseFromWire($data, 'Result');

        return new self($meta);
    }
}
