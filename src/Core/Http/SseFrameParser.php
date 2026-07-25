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
 * Incremental Server-Sent Events parser: absorbs response chunks and yields whole frames as they complete.
 *
 * Lines end with LF, CRLF, or CR. A line beginning with a colon is a comment, which servers emit as a
 * keep-alive and clients must ignore. A blank line dispatches the frame, unless no data accumulated.
 *
 * @internal
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
 */
final class SseFrameParser
{
    /**
     * Bytes one frame may absorb before the stream is abandoned.
     */
    public const int DEFAULT_MAX_FRAME_BYTES = 10_485_760;

    private const string DEFAULT_EVENT = 'message';

    /**
     * Bytes absorbed since the last frame was dispatched.
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
    private string $event = self::DEFAULT_EVENT;

    /**
     * @param int $maxFrameBytes Bytes one frame may absorb before the stream is abandoned
     */
    public function __construct(private readonly int $maxFrameBytes = self::DEFAULT_MAX_FRAME_BYTES)
    {
    }

    /**
     * Absorbs a chunk of the response body, returning every frame it completed.
     *
     * @return list<SseFrame>
     *
     * @throws ResponseTooLargeException
     */
    public function feed(string $chunk): array
    {
        // Neither a line nor a frame has to end for bytes to keep arriving, so the reader would otherwise
        // hold an unterminated stream in memory for as long as a peer cares to send one.
        $this->frameBytes += \strlen($chunk);

        if ($this->frameBytes > $this->maxFrameBytes) {
            throw new ResponseTooLargeException($this->maxFrameBytes);
        }

        if ($this->afterCarriageReturn && str_starts_with($chunk, "\n")) {
            // The CR that ended the previous chunk and this LF are the two halves of one CRLF. Splitting
            // on both would read the LF as a second, empty line and dispatch a frame the sender never ended.
            $chunk = substr($chunk, 1);
        }

        $this->pending .= $chunk;
        $this->afterCarriageReturn = str_ends_with($this->pending, "\r");
        $frames = [];

        // The final segment carries no terminator yet, so it waits for the chunk that supplies one.
        $lines = preg_split('/\r\n|\n|\r/', $this->pending);
        \assert(\is_array($lines));
        $this->pending = (string) array_pop($lines);

        foreach ($lines as $line) {
            $frame = $this->consume($line);

            if (null !== $frame) {
                $frames[] = $frame;
                $this->frameBytes = \strlen($this->pending);
            }
        }

        return $frames;
    }

    /**
     * Interprets one complete line, returning the frame it dispatched.
     */
    private function consume(string $line): ?SseFrame
    {
        if ('' === $line) {
            return $this->dispatch();
        }

        // A comment line, which the transport keep-alive uses, opens with a colon and so splits to an empty
        // field name that matches nothing below. `id` and `retry` are likewise ignored: a request-scoped MCP
        // stream is not resumable.
        [$field, $value] = self::split($line);

        if ('data' === $field) {
            $this->data[] = $value;
        } elseif ('event' === $field && '' !== $value) {
            $this->event = $value;
        }

        return null;
    }

    /**
     * Emits the accumulated frame and resets for the next one. A frame with no data is not dispatched.
     */
    private function dispatch(): ?SseFrame
    {
        $data = $this->data;
        $event = $this->event;

        $this->data = [];
        $this->event = self::DEFAULT_EVENT;

        return [] === $data ? null : new SseFrame($event, implode("\n", $data));
    }

    /**
     * Splits a field line on its first colon, stripping one optional space after it. A line with no colon
     * is a field with an empty value.
     *
     * @return array{string, string}
     */
    private static function split(string $line): array
    {
        $position = strpos($line, ':');

        if (false === $position) {
            return [$line, ''];
        }

        $value = substr($line, $position + 1);

        return [substr($line, 0, $position), str_starts_with($value, ' ') ? substr($value, 1) : $value];
    }
}
