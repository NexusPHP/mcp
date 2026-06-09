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
 * Error payload carrying the elicitation list required by the server (code -32042).
 *
 * @extends Error<array{code: -32042, message: non-empty-string, data?: mixed}>
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class UrlElicitationRequiredErrorPayload extends Error
{
    public function __construct(string $message = 'URL elicitation required', mixed $data = null)
    {
        parent::__construct(ProtocolErrorCode::UrlElicitationRequired, $message, $data);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $message = $data['message'] ?? 'URL elicitation required';
        Assert::that($message)->isString('error "message" must be a string, {type} given.');

        return new self(message: $message, data: $data['data'] ?? null);
    }

    #[\Override]
    public function toArray(): array
    {
        $result = [
            'code' => ProtocolErrorCode::UrlElicitationRequired->value,
            'message' => $this->message,
        ];

        $data = $this->data ?? [];

        if ([] !== $data) {
            $result['data'] = $data;
        }

        return $result;
    }
}
