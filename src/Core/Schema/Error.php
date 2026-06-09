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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;

/**
 * @template-covariant T of array<string, mixed>
 *
 * @implements Arrayable<T>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#error
 */
abstract readonly class Error implements Arrayable
{
    public int $code;

    /**
     * @var non-empty-string
     */
    public string $message;

    public function __construct(
        int|ProtocolErrorCode $code,
        string $message,
        public mixed $data = null,
    ) {
        Assert::that($message)->isNonEmptyString('error "message" must be a non-empty string.');

        $this->code = $code instanceof ProtocolErrorCode ? $code->value : $code;
        $this->message = $message;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
