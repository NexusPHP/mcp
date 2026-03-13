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

final readonly class MethodNotFoundError extends Error
{
    public function __construct(string $message = 'Method not found', ?array $data = null)
    {
        parent::__construct(ProtocolErrorCode::MethodNotFound, $message, $data);
    }

    /**
     * @param array{message?: string, data?: array<string, mixed>} $data
     */
    #[\Override]
    public static function fromArray(array $data): self
    {
        return new self($data['message'] ?? 'Method not found', $data['data'] ?? null);
    }
}
