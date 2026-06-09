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
 * A JSON-RPC error indicating that the requested method does not exist or is not available.
 *
 * In MCP, a server returns this error when a client invokes a method the server does not
 * implement — either a genuinely unknown method, or one gated behind a server capability the
 * server did not advertise (e.g., calling `prompts/list` when the `prompts` capability was not
 * advertised). A request that requires a client capability the client did not declare is
 * signalled instead by `MissingRequiredClientCapabilityError` (`-32003`).
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#methodnotfounderror
 */
final readonly class MethodNotFoundError extends Error
{
    public const string DEFAULT_MESSAGE = 'Method not found';

    public function __construct(string $message, mixed $data = null)
    {
        parent::__construct(ProtocolErrorCode::MethodNotFound, $message, $data);
    }

    /**
     * @param array{code?: int, message?: string, data?: mixed} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(message: $data['message'] ?? self::DEFAULT_MESSAGE, data: $data['data'] ?? null);
    }
}
