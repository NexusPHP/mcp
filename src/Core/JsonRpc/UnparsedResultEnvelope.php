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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Parser-state shape returned by `JsonRpcMessageParser::parse()` for a JSON-RPC
 * success response envelope when no expected `Result` class was supplied.
 *
 * Carries the request id and the raw `result` payload so consumers without an
 * outbound-request correlation table can log or discard the envelope without
 * the parser raising an error.
 */
final readonly class UnparsedResultEnvelope
{
    public function __construct(public RequestId $id, public mixed $result)
    {
    }
}
