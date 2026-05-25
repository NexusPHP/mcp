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

use Amp\ByteStream\ReadableStream;
use Nexus\Assert\Assert;

/**
 * Reads `\n`-delimited lines from a byte stream with a configurable per-line
 * byte cap. Strips trailing `\r` so CRLF input yields the same lines as LF.
 *
 * @internal
 */
final readonly class LineReader
{
    public const int DEFAULT_MAX_LINE_BYTES = 4 * 1024 * 1024;

    public function __construct(private ReadableStream $source, private int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES)
    {
        Assert::that($maxLineBytes)->isPositiveInt('maxLineBytes must be a positive integer, {value} given.');
    }

    /**
     * @return iterable<string>
     *
     * @throws \RuntimeException
     */
    public function getLines(): iterable
    {
        $buffer = '';

        $chunk = $this->source->read();

        while (null !== $chunk) {
            $buffer .= $chunk;

            while (str_contains($buffer, "\n")) {
                [$rawLine, $buffer] = explode("\n", $buffer, 2);

                $this->guardLineBytes(\strlen($rawLine));

                $line = rtrim($rawLine, "\r");

                if ('' !== $line) {
                    yield $line;
                }
            }

            $this->guardLineBytes(\strlen($buffer));

            $chunk = $this->source->read();
        }

        $trailing = rtrim($buffer, "\r");

        if ('' !== $trailing) {
            yield $trailing;
        }
    }

    private function guardLineBytes(int $size): void
    {
        if ($this->maxLineBytes < $size) {
            throw new \RuntimeException(\sprintf(
                'Line exceeded the %d-byte cap before a delimiter was found.',
                $this->maxLineBytes,
            ));
        }
    }
}
