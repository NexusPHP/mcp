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
 * Request body that cannot rewind, so a second read yields the empty string.
 *
 * @internal
 */
final class NonSeekableStream implements StreamInterface
{
    public int $reads = 0;
    private bool $consumed = false;

    public function __construct(private readonly string $payload)
    {
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->getContents();
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
    public function getSize(): ?int
    {
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return $this->consumed ? \strlen($this->payload) : 0;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->consumed;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable.');
    }

    #[\Override]
    public function rewind(): void
    {
        throw new \RuntimeException('Stream is not seekable.');
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        return $this->getContents();
    }

    #[\Override]
    public function getContents(): string
    {
        ++$this->reads;

        if ($this->consumed) {
            return '';
        }

        $this->consumed = true;

        return $this->payload;
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}
