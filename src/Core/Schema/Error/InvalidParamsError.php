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

use Nexus\Assert\Assert;
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
 * @extends Error<array{code: -32602, message: non-empty-string, data?: mixed}>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#invalidparamserror
 */
final readonly class InvalidParamsError extends Error
{
    public const string DEFAULT_MESSAGE = 'Invalid params';

    public function __construct(string $message, mixed $data = null)
    {
        parent::__construct(code: ProtocolErrorCode::InvalidParams, message: $message, data: $data);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $message = $data['message'] ?? self::DEFAULT_MESSAGE;
        Assert::that($message)->isString('error "message" must be a string, {type} given.');

        return new self(message: $message, data: $data['data'] ?? null);
    }

    #[\Override]
    public function toArray(): array
    {
        $result = [
            'code' => ProtocolErrorCode::InvalidParams->value,
            'message' => $this->message,
        ];

        $data = $this->data ?? [];

        if ([] !== $data) {
            $result['data'] = $data;
        }

        return $result;
    }
}
