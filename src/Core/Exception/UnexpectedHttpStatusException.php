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

/**
 * Thrown when an HTTP exchange answers with a status that carries no JSON-RPC payload settling
 * the message the exchange was sent for.
 */
final class UnexpectedHttpStatusException extends \RuntimeException implements McpExceptionInterface
{
    /**
     * How many leading bytes of the answer's body are retained for diagnostics.
     */
    private const int MAX_BODY_BYTES = 8192;

    /**
     * The leading bytes of the answer's body, or null where the body was not read.
     */
    public readonly ?string $body;

    public function __construct(public readonly int $status, ?string $body = null)
    {
        $this->body = null === $body ? null : substr($body, 0, self::MAX_BODY_BYTES);

        parent::__construct(\sprintf('The endpoint answered %d where 200 or 202 was expected.', $status));
    }
}
