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
 * A JSON-RPC error indicating that the method parameters are invalid or malformed.
 *
 * In MCP, this error is returned in various contexts when request parameters fail validation:
 *
 * - **Tools**: Unknown tool name or invalid tool arguments
 * - **Prompts**: Unknown prompt name or missing required arguments
 * - **Pagination**: Invalid or expired cursor values
 * - **Logging**: Invalid log level
 * - **Elicitation**: Server requests an elicitation mode not declared in client capabilities
 * - **Sampling**: Missing tool result or tool results mixed with other content
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class InvalidParamsError extends Error
{
    public function __construct(string $message = 'Invalid params', mixed $data = null)
    {
        parent::__construct(ProtocolErrorCode::InvalidParams, $message, $data);
    }

    /**
     * @param array{code?: int, message?: string, data?: mixed} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data['message'] ?? 'Invalid params', $data['data'] ?? null);
    }
}
