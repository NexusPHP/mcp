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
 * Error carrying a raw integer code that does not map to a known `ProtocolErrorCode` case.
 *
 * @extends Error<array{code: int, message: non-empty-string, data?: mixed}>
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class UnknownProtocolError extends Error
{
    /**
     * @param non-empty-string $message
     */
    public function __construct(int $code, string $message, mixed $data = null)
    {
        if (ProtocolErrorCode::tryFrom($code) !== null) {
            throw new \InvalidArgumentException(\sprintf('code %d maps to a known protocol error code.', $code));
        }

        parent::__construct(code: $code, message: $message, data: $data);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('code', '"error" is missing the required "code" key.');
        Assert::that($data['code'])->isInt('"error.code" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('message', '"error" is missing the required "message" key.');
        Assert::that($data['message'])->isNonEmptyString('"error.message" must be a non-empty string, {type} given.');

        return new self(code: $data['code'], message: $data['message'], data: $data['data'] ?? null);
    }

    #[\Override]
    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        $data = $this->data ?? [];

        if ([] !== $data) {
            $result['data'] = $data;
        }

        return $result;
    }
}
