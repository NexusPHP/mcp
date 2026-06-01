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
 * Error indicating that the request payload is not a valid JSON-RPC request (code -32600).
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
