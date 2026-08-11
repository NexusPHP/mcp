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
 * Returned when a server rejects a request because the values in the HTTP headers do not match the
 * corresponding values in the request body, or because required headers are missing or malformed. For
 * HTTP, the response status code MUST be `400 Bad Request`.
 *
 * @extends Error<array{code: -32020, message: non-empty-string, data?: mixed}>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#headermismatcherror
 */
final readonly class HeaderMismatchError extends Error
{
    public const string DEFAULT_MESSAGE = 'Header mismatch';

    /**
     * @param non-empty-string $message
     */
    public function __construct(string $message = self::DEFAULT_MESSAGE, mixed $data = null)
    {
        parent::__construct(code: ProtocolErrorCode::HeaderMismatch, message: $message, data: $data);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $message = $data['message'] ?? self::DEFAULT_MESSAGE;
        Assert::that($message)->isNonEmptyString('error "message" must be a non-empty string, {type} given.');

        return new self(message: $message, data: $data['data'] ?? null);
    }

    #[\Override]
    public function toArray(): array
    {
        $result = [
            'code' => ProtocolErrorCode::HeaderMismatch->value,
            'message' => $this->message,
        ];

        $data = $this->data ?? [];

        if ([] !== $data) {
            $result['data'] = $data;
        }

        return $result;
    }
}
