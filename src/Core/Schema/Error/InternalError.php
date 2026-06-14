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
 * A JSON-RPC error indicating that an internal error occurred on the receiver.
 *
 * This error is returned when the receiver encounters an unexpected condition that prevents it
 * from fulfilling the request.
 *
 * @extends Error<array{code: -32603, message: non-empty-string, data?: mixed}>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#internalerror
 */
final readonly class InternalError extends Error
{
    public const string DEFAULT_MESSAGE = 'Internal error';

    public function __construct(string $message, mixed $data = null)
    {
        parent::__construct(code: ProtocolErrorCode::InternalError, message: $message, data: $data);
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
            'code' => ProtocolErrorCode::InternalError->value,
            'message' => $this->message,
        ];

        $data = $this->data ?? [];

        if ([] !== $data) {
            $result['data'] = $data;
        }

        return $result;
    }
}
