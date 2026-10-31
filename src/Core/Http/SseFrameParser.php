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
     * The line still awaiting its terminator.
     */
    private string $pending = '';

    /**
     * Whether the bytes absorbed so far end on a CR, which an LF opening the next chunk completes.
     */
    private bool $afterCarriageReturn = false;

    /**
     * Whether a blank line ended on a CR, so that its budget restarts once an LF opening the next chunk is counted.
     */
    private bool $boundaryAwaitsLineFeed = false;

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
        $completesCarriageReturn = $this->afterCarriageReturn && str_starts_with($chunk, "\n");

        if ($completesCarriageReturn) {
            $chunk = substr($chunk, 1);
            $this->afterCarriageReturn = false;
            $this->absorb(1);
        }

        if (($completesCarriageReturn || '' !== $chunk) && $this->boundaryAwaitsLineFeed) {
            $this->frameBytes = 0;
            $this->boundaryAwaitsLineFeed = false;
        }

        if ('' !== $chunk) {
            $this->afterCarriageReturn = str_ends_with($chunk, "\r");
        }

        preg_match_all('/([^\r\n]*)(\r\n|\n|\r)/', $chunk, $lines, \PREG_SET_ORDER);

        $consumed = 0;
        $frames = [];

        foreach ($lines as [$terminated, $text, $terminator]) {
            $consumed += \strlen($terminated);
            $this->absorb(\strlen($terminated));

            $line = $this->pending.$text;
            $this->pending = '';
            $frame = $this->consumeLine($line);

            // A blank line is a frame boundary whether or not it dispatched, so the budget restarts.
            if ('' === $line) {
                $this->boundaryAwaitsLineFeed = "\r" === $terminator && \strlen($chunk) === $consumed;

                if (! $this->boundaryAwaitsLineFeed) {
                    $this->frameBytes = 0;
                }
            }

            if (null !== $frame) {
                $frames[] = $frame;
            }
        }

        $remainder = substr($chunk, $consumed);
        $this->absorb(\strlen($remainder));
        $this->pending .= $remainder;

        return $frames;
    }

    /**
     * @throws ResponseTooLargeException
     */
    private function absorb(int $bytes): void
    {
        $this->frameBytes += $bytes;

        if ($this->frameBytes > $this->maxFrameBytes) {
            throw new ResponseTooLargeException($this->maxFrameBytes);
        }
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
