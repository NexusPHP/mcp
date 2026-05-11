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
 * Error payload carrying the elicitation list required by the server (code -32042).
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final readonly class UrlElicitationRequiredErrorPayload extends Error
{
    public function __construct(string $message = 'URL elicitation required', ?array $data = null)
    {
        parent::__construct(ProtocolErrorCode::UrlElicitationRequired, $message, $data);
    }

    /**
     * @param array{message?: string, data?: array<string, mixed>} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data['message'] ?? 'URL elicitation required', $data['data'] ?? null);
    }
}
