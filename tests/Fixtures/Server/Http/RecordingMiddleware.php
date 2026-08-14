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
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware double that records its label on entry, refusing a re-entry while its own is still in flight.
 *
 * @internal
 */
final class RecordingMiddleware implements MiddlewareInterface
{
    private bool $inFlight = false;

    public function __construct(
        private readonly string $label,
        private readonly CallLog $log,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->inFlight) {
            throw new \LogicException(\sprintf('The "%s" middleware was entered again before it returned.', $this->label));
        }

        $this->log->record($this->label);
        $this->inFlight = true;

        try {
            return $handler->handle($request);
        } finally {
            $this->inFlight = false;
        }
    }
}
