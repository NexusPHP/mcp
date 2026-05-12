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

namespace Nexus\Mcp\Core\Exception;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;

/**
 * Base for every parser failure. Concrete subclasses pin a JSON-RPC error
 * category and may carry category-specific context.
 */
abstract class AbstractJsonRpcParserException extends \RuntimeException implements McpExceptionInterface
{
    public function __construct(
        string $message,
        public readonly null|int|string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The JSON-RPC error code corresponding to this exception's category.
     */
    abstract public static function errorCode(): ProtocolErrorCode;
}
