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

namespace Nexus\Mcp\Tests\Fixtures\Server;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Readable stream that throws on every `read()` call.
 *
 * @internal
 *
 * @implements \IteratorAggregate<int, string>
 */
final class ThrowingReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    public function __construct(private readonly \Throwable $error)
    {
    }

    #[\Override]
    public function read(?Cancellation $cancellation = null): ?string
    {
        throw $this->error;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
    }
}
