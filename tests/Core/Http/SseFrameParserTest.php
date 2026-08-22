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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Exception\ResponseTooLargeException;
use Nexus\Mcp\Core\Http\SseFrame;
use Nexus\Mcp\Core\Http\SseFrameParser;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SseFrameParser::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SseFrameParserTest extends AbstractMcpTestCase
{
    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('provideParsesAWholeStreamCases')]
    public function testParsesAWholeStream(string $stream, array $expected): void
    {
        self::assertSame($expected, $this->flatten((new SseFrameParser())->feed($stream)));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function provideParsesAWholeStreamCases(): iterable
    {
        yield 'the frame the server writes' => [
            "event: message\ndata: {\"jsonrpc\":\"2.0\"}\n\n",
            [['message', '{"jsonrpc":"2.0"}']],
        ];

        yield 'two frames' => [
            "event: message\ndata: one\n\nevent: message\ndata: two\n\n",
            [['message', 'one'], ['message', 'two']],
        ];

        yield 'a frame declaring no event defaults to message' => [
            "data: bare\n\n",
            [['message', 'bare']],
        ];

        yield 'multiple data lines join with newlines' => [
            "data: first\ndata: second\n\n",
            [['message', "first\nsecond"]],
        ];

        yield 'a custom event type is preserved' => [
            "event: endpoint\ndata: /mcp\n\n",
            [['endpoint', '/mcp']],
        ];

        yield 'the event type does not leak into the next frame' => [
            "event: custom\ndata: one\n\ndata: two\n\n",
            [['custom', 'one'], ['message', 'two']],
        ];

        yield 'id and retry fields are ignored' => [
            "id: 7\nretry: 5000\ndata: payload\n\n",
            [['message', 'payload']],
        ];

        yield 'a field with no value' => [
            "data\n\n",
            [['message', '']],
        ];

        yield 'only the first space after the colon is stripped' => [
            "data:  padded\n\n",
            [['message', ' padded']],
        ];

        yield 'a colon inside the value is kept' => [
            "data: {\"a\":\"b\"}\n\n",
            [['message', '{"a":"b"}']],
        ];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('provideIgnoresCommentsAndEmptyFramesCases')]
    public function testIgnoresCommentsAndEmptyFrames(string $stream, array $expected): void
    {
        self::assertSame($expected, $this->flatten((new SseFrameParser())->feed($stream)));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function provideIgnoresCommentsAndEmptyFramesCases(): iterable
    {
        yield 'the transport keep-alive frame' => [": keep-alive\n\n", []];

        yield 'a bare colon keep-alive' => [":\r\n\r\n", []];

        yield 'a comment between two frames' => [
            "data: one\n\n: keep-alive\n\ndata: two\n\n",
            [['message', 'one'], ['message', 'two']],
        ];

        yield 'an event with no data is not dispatched' => ["event: message\n\n", []];

        yield 'repeated blank lines dispatch nothing' => ["\n\n\n", []];
    }

    /**
     * @param list<string>                $chunks
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('provideHandlesLineTerminatorsCases')]
    public function testHandlesLineTerminators(array $chunks, array $expected): void
    {
        $parser = new SseFrameParser();
        $frames = [];

        foreach ($chunks as $chunk) {
            $frames = [...$frames, ...$parser->feed($chunk)];
        }

        self::assertSame($expected, $this->flatten($frames));
    }

    /**
     * @return iterable<string, array{list<string>, list<array{string, string}>}>
     */
    public static function provideHandlesLineTerminatorsCases(): iterable
    {
        yield 'CRLF throughout' => [["event: message\r\ndata: crlf\r\n\r\n"], [['message', 'crlf']]];

        yield 'bare CR throughout' => [["event: message\rdata: cr\r\r"], [['message', 'cr']]];

        yield 'mixed terminators' => [["data: a\r\ndata: b\n\r\n"], [['message', "a\nb"]]];

        yield 'a CRLF split across chunks' => [["data: split\r", "\n\r\n"], [['message', 'split']]];

        yield 'a CR ending a chunk followed by data' => [["data: one\r", "data: two\r\r"], [['message', "one\ntwo"]]];

        yield 'a CRLF split mid-frame' => [["data: a\r", "\ndata: b\r\n\r\n"], [['message', "a\nb"]]];

        yield 'a CRLF split after the event line' => [["event: progress\r", "\ndata: x\r\n\r\n"], [['progress', 'x']]];
    }

    public function testAbandonsAStreamThatOutgrowsTheFrameCap(): void
    {
        $parser = new SseFrameParser(maxFrameBytes: 32);

        self::assertSame([], $this->flatten($parser->feed(str_repeat('a', 32))));

        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessageIs('The response exceeded the 32 byte limit the client accepts.');

        $parser->feed('a');
    }

    public function testTheFrameCapCountsFromTheLastDispatchedFrame(): void
    {
        $parser = new SseFrameParser(maxFrameBytes: 32);

        for ($frame = 0; $frame < 20; ++$frame) {
            self::assertSame([['message', 'tick']], $this->flatten($parser->feed("data: tick\n\n")));
        }
    }

    public function testTheFrameCapCountsAcrossFieldLinesWithinOneFrame(): void
    {
        $parser = new SseFrameParser(maxFrameBytes: 32);

        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessageIs('The response exceeded the 32 byte limit the client accepts.');

        for ($line = 0; $line < 4; ++$line) {
            $parser->feed("data: aaaaaaaa\n");
        }
    }

    public function testAKeepAliveOnlyStreamNeverOutgrowsTheFrameCap(): void
    {
        $parser = new SseFrameParser(maxFrameBytes: 32);

        for ($tick = 0; $tick < 20; ++$tick) {
            self::assertSame([], $this->flatten($parser->feed(": keep-alive\n\n")));
        }

        self::assertSame([['message', 'still alive']], $this->flatten($parser->feed("data: still alive\n\n")));
    }

    public function testAPendingCarriageReturnSurvivesAnEmptyChunk(): void
    {
        $parser = new SseFrameParser();
        $frames = [];

        foreach (["data: a\r", '', "\ndata: b\n\n"] as $chunk) {
            $frames = [...$frames, ...$parser->feed($chunk)];
        }

        self::assertSame([['message', "a\nb"]], $this->flatten($frames));
    }

    public function testAConsumedCarriageReturnDoesNotSwallowTheNextChunksNewline(): void
    {
        $parser = new SseFrameParser();
        $frames = [];

        foreach (["data: a\r", "\n", "\ndata: b\n\n"] as $chunk) {
            $frames = [...$frames, ...$parser->feed($chunk)];
        }

        self::assertSame([['message', 'a'], ['message', 'b']], $this->flatten($frames));
    }

    public function testAssemblesAFrameSplitAcrossChunks(): void
    {
        $parser = new SseFrameParser();

        self::assertSame([], $this->flatten($parser->feed('event: mess')));
        self::assertSame([], $this->flatten($parser->feed("age\ndata: {\"a\":")));
        self::assertSame([], $this->flatten($parser->feed('1}')));
        self::assertSame([['message', '{"a":1}']], $this->flatten($parser->feed("\n\n")));
    }

    public function testAssemblesAFrameFedOneByteAtATime(): void
    {
        $parser = new SseFrameParser();
        $frames = [];

        foreach (str_split("event: message\ndata: drip\n\n") as $byte) {
            $frames = [...$frames, ...$parser->feed($byte)];
        }

        self::assertSame([['message', 'drip']], $this->flatten($frames));
    }

    public function testAnUnterminatedTrailingFrameIsNotDispatched(): void
    {
        self::assertSame([], $this->flatten((new SseFrameParser())->feed("event: message\ndata: truncated\n")));
    }

    /**
     * @param list<SseFrame> $frames
     *
     * @return list<array{string, string}>
     */
    private function flatten(array $frames): array
    {
        return array_map(static fn(SseFrame $frame): array => [$frame->event, $frame->data], $frames);
    }
}
