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
 * Error indicating that the JSON-RPC method's params are invalid (code -32602).
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class InvalidParamsError extends Error
{
    public function __construct(string $message = 'Invalid params', ?array $data = null)
    {
        parent::__construct(ProtocolErrorCode::InvalidParams, $message, $data);
    }

    /**
     * @param array{message?: string, data?: array<string, mixed>} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data['message'] ?? 'Invalid params', $data['data'] ?? null);
    }
}
