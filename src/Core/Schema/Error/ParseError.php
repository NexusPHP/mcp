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
 * Error indicating that the received JSON could not be parsed (code -32700).
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class ParseError extends Error
{
    /**
     * @param null|array<string, mixed> $data
     */
    public function __construct(string $message = 'Parse error', ?array $data = null)
    {
        parent::__construct(ProtocolErrorCode::ParseError, $message, $data);
    }

    /**
     * @param array{code?: int, message?: string, data?: array<string, mixed>} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data['message'] ?? 'Parse error', $data['data'] ?? null);
    }
}
