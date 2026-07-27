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

namespace Nexus\Mcp\Tests\Fixtures\Client\Http;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

use function Amp\ByteStream\buffer;

/**
 * HTTP client double that records the requests it was handed and answers each from a queued script.
 *
 * @internal
 */
final class RecordingHttpClient implements DelegateHttpClient
{
    /**
     * @var list<Request>
     */
    public private(set) array $requests = [];

    /**
     * Whether the body of the answer at each index was read to its end, keyed by request index. A body that
     * was never drained leaves no entry, which is how amphp decides to tear the connection down.
     *
     * @var array<int, bool>
     */
    public private(set) array $drainedBodies = [];

    /**
     * The cancellation each request was handed, keyed by request index.
     *
     * @var list<Cancellation>
     */
    public private(set) array $cancellations = [];

    /**
     * @var list<array{status: int, headers: array<non-empty-string, string>, chunks: list<string>, open?: bool, gate?: Future<mixed>, answeredFrom?: string}|HttpException>
     */
    private array $script = [];

    /**
     * Queues a buffered JSON response, optionally withheld until `$gate` completes, which parks the caller
     * mid-flow so a second one can be let in behind it.
     *
     * @param array<string, mixed>|string $body
     * @param ?Future<mixed>              $gate
     */
    public function willAnswerJson(array|string $body, int $status = 200, ?Future $gate = null): self
    {
        return $this->willAnswer(
            $status,
            ['content-type' => 'application/json'],
            [\is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR)],
            $gate,
        );
    }

    /**
     * Queues an SSE response, one stream chunk per entry, so a frame can be split across chunks.
     *
     * @param list<string> $chunks
     */
    public function willAnswerStream(array $chunks, int $status = 200): self
    {
        return $this->willAnswer($status, ['content-type' => 'text/event-stream'], $chunks);
    }

    /**
     * Queues a response whose media type is spelled exactly as given, so content-type sniffing can be
     * exercised.
     *
     * @param non-empty-string $contentType
     * @param list<string>     $chunks
     */
    public function willAnswerWithContentType(string $contentType, array $chunks): self
    {
        return $this->willAnswer(200, ['content-type' => $contentType], $chunks);
    }

    /**
     * Queues an SSE response that emits its chunks then stays open, the way a `subscriptions/listen` stream
     * does. Reading it after the chunks run out suspends until the caller's cancellation fires.
     *
     * @param list<string> $chunks
     */
    public function willAnswerOpenStream(array $chunks): self
    {
        $this->script[] = ['status' => 200, 'headers' => ['content-type' => 'text/event-stream'], 'chunks' => $chunks, 'open' => true];

        return $this;
    }

    /**
     * Queues a response carrying a `WWW-Authenticate` challenge, the way a protected MCP server answers a
     * request it will not serve.
     */
    public function willChallenge(int $status, string $challenge, string $body = '{}'): self
    {
        return $this->willAnswer($status, ['content-type' => 'application/json', 'www-authenticate' => $challenge], [$body]);
    }

    /**
     * Queues a bodiless `202 Accepted`, the answer to a notification POST.
     */
    public function willAcceptNotification(): self
    {
        return $this->willAnswer(202, [], []);
    }

    /**
     * Queues a transport-level failure.
     */
    public function willFail(HttpException $exception): self
    {
        $this->script[] = $exception;

        return $this;
    }

    /**
     * Queues a miss whose body is larger than a caller is willing to drain.
     */
    public function willAnswer404WithBody(string $body): self
    {
        return $this->willAnswer(404, ['content-type' => 'application/json'], [$body]);
    }

    /**
     * Queues the answer a client that follows redirects returns: the response names the URL it was finally
     * answered from rather than the one the caller sent to.
     *
     * @param array<string, mixed> $body
     */
    public function willAnswerFrom(string $url, array $body = []): self
    {
        return $this->willAnswer(
            200,
            ['content-type' => 'application/json'],
            [json_encode($body, \JSON_THROW_ON_ERROR)],
            answeredFrom: $url,
        );
    }

    #[\Override]
    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->requests[] = $request;
        $this->cancellations[] = $cancellation;
        $step = array_shift($this->script);

        if ($step instanceof HttpException) {
            throw $step;
        }

        if (null === $step) {
            throw new HttpException('The script queued no answer for this request.');
        }

        ($step['gate'] ?? null)?->await();

        $body = ($step['open'] ?? false)
            ? self::openStream($step['chunks'])
            : $this->trackDrain(\count($this->requests) - 1, $step['chunks']);

        $answeredFrom = $step['answeredFrom'] ?? null;

        return new Response(
            '2',
            $step['status'],
            null,
            $step['headers'],
            new ReadableIterableStream($body),
            null === $answeredFrom ? $request : new Request($answeredFrom, $request->getMethod()),
        );
    }

    /**
     * The recorded request at `$index`.
     */
    public function readRequest(int $index = 0): Request
    {
        return $this->requests[$index] ?? throw new \OutOfBoundsException(\sprintf('No request was recorded at index %d.', $index));
    }

    /**
     * The body the recorded request at `$index` carried.
     *
     * @return array<string, mixed>
     */
    public function readSentEnvelope(int $index = 0): array
    {
        $decoded = json_decode(buffer($this->readRequest($index)->getBody()->getContent()), associative: true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * Yields the chunks, then records that the consumer read the body to its end.
     *
     * @param list<string> $chunks
     *
     * @return \Traversable<int, string>
     */
    private function trackDrain(int $index, array $chunks): \Traversable
    {
        yield from $chunks;

        // Emitting one more chunk parks this generator until the consumer asks for it, so a consumer that
        // gives up after the last real chunk never reaches the line below. Recording straight after the
        // chunks would instead mark an abandoned body drained.
        yield '';

        $this->drainedBodies[$index] = true;
    }

    /**
     * Yields the chunks, then suspends forever. A read past the end unblocks only when the caller cancels,
     * which is how a real long-lived stream behaves.
     *
     * @param list<string> $chunks
     *
     * @return \Traversable<int, string>
     */
    private static function openStream(array $chunks): \Traversable
    {
        yield from $chunks;

        // Never completed, so the stream stays open without holding a timer the test would have to cancel.
        new DeferredFuture()->getFuture()->await();
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param list<string>                    $chunks
     * @param ?Future<mixed>                  $gate
     */
    private function willAnswer(
        int $status,
        array $headers,
        array $chunks,
        ?Future $gate = null,
        ?string $answeredFrom = null,
    ): self {
        $step = ['status' => $status, 'headers' => $headers, 'chunks' => $chunks];

        if (null !== $gate) {
            $step['gate'] = $gate;
        }

        if (null !== $answeredFrom) {
            $step['answeredFrom'] = $answeredFrom;
        }

        $this->script[] = $step;

        return $this;
    }
}
