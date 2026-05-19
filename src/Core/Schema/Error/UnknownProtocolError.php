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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;

/**
 * Error carrying a raw integer code that does not map to a known `ProtocolErrorCode` case.
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class UnknownProtocolError extends Error
{
    public function __construct(int $code, string $message, ?array $data = null)
    {
        if (ProtocolErrorCode::tryFrom($code) !== null) {
            throw new ExpectationFailedException(
                'code {value} maps to a known protocol error code.',
                ['value' => var_export($code, true)],
            );
        }

        parent::__construct($code, $message, $data);
    }

    /**
     * @param array{code: int, message: string, data?: array<string, mixed>} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('code', 'missing the required "code" key.');
        Assert::that($data['code'])->isInt('"code" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('message', 'missing the required "message" key.');
        Assert::that($data['message'])->isString('"message" must be a string, {type} given.');

        return new self($data['code'], $data['message'], $data['data'] ?? null);
    }
}
