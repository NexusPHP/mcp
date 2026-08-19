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

use Psr\Http\Message\StreamInterface;

/**
 * Read-only stream double counting its reads, serving them chunked, and optionally stalling or reporting a size.
 *
 * A stalled stream answers one empty read and refuses the next, so a caller that ignores the empty
 * chunk fails fast instead of spinning.
 *
 * @internal
 */
final class CountingStream implements StreamInterface
{
    public int $bytesRead = 0;
    private int $cursor = 0;
    private bool $stalled = false;

    public function __construct(
        private readonly string $content,
        private readonly int $chunkSize = 256,
        private readonly ?int $stallAfterBytes = null,
        private readonly ?int $reportedSize = null,
    ) {
    }

    #[\Override]
    public function __toString(): string
    {
        return substr($this->content, $this->cursor);
    }

    #[\Override]
    public function read(int $length): string
    {
        if ($length < 1) {
            throw new \RuntimeException('Read length must be positive.');
        }

        if ($this->stalled) {
            throw new \RuntimeException('Read past the stall.');
        }

        if (null !== $this->stallAfterBytes && $this->cursor >= $this->stallAfterBytes) {
            $this->stalled = true;

            return '';
        }

        $take = min($length, $this->chunkSize);

        if (null !== $this->stallAfterBytes) {
            $take = min($take, $this->stallAfterBytes - $this->cursor);
        }

        $chunk = substr($this->content, $this->cursor, $take);
        $this->cursor += \strlen($chunk);
        $this->bytesRead += \strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->cursor >= \strlen($this->content) && null === $this->stallAfterBytes;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->reportedSize;
    }

    #[\Override]
    public function close(): void
    {
    }

    #[\Override]
    public function detach()
    {
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return $this->cursor;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('This stream is not seekable.');
    }

    #[\Override]
    public function rewind(): void
    {
        throw new \RuntimeException('This stream is not seekable.');
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('This stream is not writable.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function getContents(): string
    {
        return substr($this->content, $this->cursor);
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
