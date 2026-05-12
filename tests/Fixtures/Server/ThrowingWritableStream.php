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

use Amp\ByteStream\WritableStream;

/**
 * Writable stream that throws on every `write()` call.
 *
 * @internal
 */
final class ThrowingWritableStream implements WritableStream
{
    private bool $closed = false;

    public function __construct(private readonly \Throwable $error)
    {
    }

    #[\Override]
    public function write(string $bytes): void
    {
        throw $this->error;
    }

    #[\Override]
    public function end(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function isWritable(): bool
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
