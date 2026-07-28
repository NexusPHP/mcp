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

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Base for every JSON-RPC protocol-level failure raised by the SDK. Concrete
 * subclasses pin a JSON-RPC error category and may carry category-specific context.
 */
abstract class AbstractJsonRpcProtocolException extends \RuntimeException implements JsonRpcProtocolExceptionInterface
{
    /**
     * @param mixed $errorData Payload for the error response's `data` slot, omitted when null
     */
    public function __construct(
        public readonly ?RequestId $requestId,
        string $message,
        ?\Throwable $previous = null,
        public readonly mixed $errorData = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
