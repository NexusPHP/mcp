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

namespace Nexus\Mcp\Core\Http;

use Nexus\Mcp\Core\Exception\ResponseTooLargeException;

/**
 * Incremental Server-Sent Events parser.
 *
 * @internal
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
 */
final class SseFrameParser
{
    public const int DEFAULT_MAX_FRAME_BYTES = 10_485_760;

    /**
     * Bytes absorbed since the last frame boundary.
     */
    private int $frameBytes = 0;

    /**
     * Chunk remainder up to the last complete line.
     */
    private string $pending = '';

    /**
     * Whether the bytes absorbed so far end on a CR, which an LF opening the next chunk completes.
     */
    private bool $afterCarriageReturn = false;

    /**
     * @var list<string>
     */
    private array $data = [];

    /**
     * @var non-empty-string
     */
    private string $event = 'message';

    public function __construct(private readonly int $maxFrameBytes = self::DEFAULT_MAX_FRAME_BYTES)
    {
    }

    /**
     * @return list<SseFrame>
     *
     * @throws ResponseTooLargeException
     */
    public function feed(string $chunk): array
    {
        $this->frameBytes += \strlen($chunk);

        if ($this->frameBytes > $this->maxFrameBytes) {
            throw new ResponseTooLargeException($this->maxFrameBytes);
        }

        if ($this->afterCarriageReturn && str_starts_with($chunk, "\n")) {
            $chunk = substr($chunk, 1);
            $this->afterCarriageReturn = false;
        }

        $this->pending .= $chunk;

        if ('' !== $chunk) {
            $this->afterCarriageReturn = str_ends_with($chunk, "\r");
        }

        $frames = [];

        $lines = preg_split('/\r\n|\n|\r/', $this->pending);
        \assert(\is_array($lines));
        $this->pending = (string) array_pop($lines);

        foreach ($lines as $line) {
            $frame = $this->consumeLine($line);

            // A blank line is a frame boundary whether or not it dispatched, so the budget restarts.
            if ('' === $line) {
                $this->frameBytes = \strlen($this->pending);
            }

            if (null !== $frame) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    private function consumeLine(string $line): ?SseFrame
    {
        if ('' === $line) {
            return $this->dispatch();
        }

        [$field, $value] = $this->split($line);

        if ('data' === $field) {
            $this->data[] = $value;
        } elseif ('event' === $field && '' !== $value) {
            $this->event = $value;
        }

        return null;
    }

    /**
     * Emits the accumulated frame and resets for the next one, dispatching nothing when no data accumulated.
     */
    private function dispatch(): ?SseFrame
    {
        $data = $this->data;
        $event = $this->event;

        $this->data = [];
        $this->event = 'message';

        return [] === $data ? null : new SseFrame($event, implode("\n", $data));
    }

    /**
     * Splits a field line on its first colon, stripping one optional space after it, a colonless line being
     * a field with an empty value.
     *
     * @return array{string, string}
     */
    private function split(string $line): array
    {
        $position = strpos($line, ':');

        if (false === $position) {
            return [$line, ''];
        }

        $value = substr($line, $position + 1);

        return [substr($line, 0, $position), str_starts_with($value, ' ') ? substr($value, 1) : $value];
    }
}
