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

namespace Nexus\Mcp\Core\Transport;

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Schema\RequestId;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Transport-supplied context accompanying an inbound JSON-RPC message.
 */
final readonly class ReceiveContext
{
    /**
     * @param ?ServerRequestInterface $request       Originating HTTP request when the transport is request-scoped, null otherwise
     * @param ?VerifiedAccessToken    $authInfo      Bearer token the request was authorized with, null when the endpoint is unprotected
     * @param ?RequestId              $peerRequestId Id the peer sent, when the transport dispatches under an id of its own, null otherwise
     */
    public function __construct(
        public ?ServerRequestInterface $request = null,
        public ?VerifiedAccessToken $authInfo = null,
        public ?RequestId $peerRequestId = null,
    ) {
    }
}
