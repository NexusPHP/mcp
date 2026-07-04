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

namespace Nexus\Mcp\Tests\Fixtures\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 handler double that records whether it was invoked and with which request, then returns a
 * preset response.
 *
 * @internal
 */
final class RecordingRequestHandler implements RequestHandlerInterface
{
    public private(set) bool $called = false;
    public private(set) ?ServerRequestInterface $received = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;
        $this->received = $request;

        return $this->response;
    }
}
