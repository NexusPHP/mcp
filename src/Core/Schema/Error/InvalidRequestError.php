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
 * A JSON-RPC error indicating that the request is not a valid request object.
 *
 * This error is returned when the message structure does not conform to the JSON-RPC 2.0
 * specification requirements for a request (e.g., missing required fields like `jsonrpc` or
 * `method`, or using invalid types for these fields).
 *
 * @extends Error<array{code: -32600, message: non-empty-string, data?: mixed}>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#invalidrequesterror
 */
final readonly class InvalidRequestError extends Error
{
    public const string DEFAULT_MESSAGE = 'Invalid request';

    public function __construct(string $message, mixed $data = null)
    {
        parent::__construct(code: ProtocolErrorCode::InvalidRequest, message: $message, data: $data);
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
            'code' => ProtocolErrorCode::InvalidRequest->value,
            'message' => $this->message,
        ];

        $data = $this->data ?? [];

        if ([] !== $data) {
            $result['data'] = $data;
        }

        return $result;
    }
}
