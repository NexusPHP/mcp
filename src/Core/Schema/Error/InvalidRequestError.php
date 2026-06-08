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

namespace Nexus\Mcp\Core\Schema\Error;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;

/**
 * A JSON-RPC error indicating that the request is not a valid request object.
 *
 * This error is returned when the message structure does not conform to the JSON-RPC 2.0
 * specification requirements for a request (e.g., missing required fields like `jsonrpc` or
 * `method`, or using invalid types for these fields).
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class InvalidRequestError extends Error
{
    public function __construct(string $message = 'Invalid request', mixed $data = null)
    {
        parent::__construct(ProtocolErrorCode::InvalidRequest, $message, $data);
    }

    /**
     * @param array{code?: int, message?: string, data?: mixed} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data['message'] ?? 'Invalid request', $data['data'] ?? null);
    }
}
