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
 * HTTP client double answering from a queued script.
 *
 * @internal
 */
final class RecordingHttpClient implements DelegateHttpClient
{
    /**
     * @var list<Request>
     */
    public array $requests = [];

    /**
     * Absent at a request index whose answer was never drained.
     *
     * @var array<int, bool>
     */
    public array $drainedBodies = [];

    /**
     * @var list<Cancellation>
     */
    public array $cancellations = [];

    /**
     * @var list<array{
     *   status: int,
     *   headers: array<non-empty-string, list<string>|string>,
     *   chunks: list<string>,
     *   open?: bool,
     *   fails?: HttpException,
     *   gate?: Future<mixed>,
     *   hops?: non-empty-list<string>,
     *   resume?: null|Future<mixed>,
     *   later?: list<string>,
     * }|HttpException>
     */
    private array $script = [];

    /**
     * @param array<string, mixed>|string $body
     * @param null|Future<mixed>          $gate
     */
    public function willAnswerJson(array|string $body, int $status = 200, ?Future $gate = null): self
    {
        return $this->scheduleAnswer(
            $status,
            ['content-type' => 'application/json'],
            [\is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR)],
            $gate,
        );
    }

    /**
     * @param list<string> $chunks
     */
    public function willAnswerStream(array $chunks, int $status = 200): self
    {
        return $this->scheduleAnswer($status, ['content-type' => 'text/event-stream'], $chunks);
    }

    /**
     * @param non-empty-string $contentType
     * @param list<string>     $chunks
     */
    public function willAnswerWithContentType(string $contentType, array $chunks): self
    {
        return $this->scheduleAnswer(200, ['content-type' => $contentType], $chunks);
    }

    /**
     * @param list<string>       $chunks
     * @param null|Future<mixed> $resume
     * @param list<string>       $later
     */
    public function willAnswerOpenStream(array $chunks, ?Future $resume = null, array $later = []): self
    {
        $this->script[] = [
            'status' => 200,
            'headers' => ['content-type' => 'text/event-stream'],
            'chunks' => $chunks,
            'open' => true,
            'resume' => $resume,
            'later' => $later,
        ];

        return $this;
    }

    public function willChallenge(int $status, string $challenge, string $body = '{}'): self
    {
        return $this->scheduleAnswer($status, ['content-type' => 'application/json', 'www-authenticate' => $challenge], [$body]);
    }

    public function willChallengeWithAnUnreadableBody(int $status, string $challenge, HttpException $failure): self
    {
        $this->script[] = [
            'status' => $status,
            'headers' => ['content-type' => 'application/json', 'www-authenticate' => $challenge],
            'chunks' => [],
            'fails' => $failure,
        ];

        return $this;
    }

    public function willAcceptNotification(): self
    {
        return $this->scheduleAnswer(202, [], []);
    }

    public function willFail(HttpException $exception): self
    {
        $this->script[] = $exception;

        return $this;
    }

    public function willAnswer404WithBody(string $body): self
    {
        return $this->scheduleAnswer(404, ['content-type' => 'application/json'], [$body]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function willAnswerFrom(string $url, array $body = []): self
    {
        return $this->willAnswerAfterHops([$url], $body);
    }

    /**
     * @param non-empty-list<string> $hops
     * @param array<string, mixed>   $body
     */
    public function willAnswerAfterHops(array $hops, array $body = []): self
    {
        return $this->scheduleAnswer(
            200,
            ['content-type' => 'application/json'],
            [json_encode($body, \JSON_THROW_ON_ERROR)],
            hops: $hops,
        );
    }

    public function willRedirectTo(string $location, int $status = 302): self
    {
        return $this->scheduleAnswer($status, ['location' => $location], ['']);
    }

    /**
     * @param array<non-empty-string, list<string>|string> $headers
     */
    public function willAnswerWithHeaders(int $status, array $headers, string $body = ''): self
    {
        return $this->scheduleAnswer($status, $headers, [$body]);
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

        $failure = $step['fails'] ?? null;
        $body = match (true) {
            $step['open'] ?? false => $this->openStream($step['chunks'], $step['resume'] ?? null, $step['later'] ?? []),
            null !== $failure => $this->failPartway($step['chunks'], $failure),
            default => $this->trackDrain(\count($this->requests) - 1, $step['chunks']),
        };

        $chain = [$request];

        foreach ($step['hops'] ?? [] as $hop) {
            $chain[] = new Request($hop, $request->getMethod());
        }

        $previous = null;

        foreach (\array_slice($chain, 0, -1) as $redirected) {
            $previous = new Response('2', 302, null, [], new ReadableIterableStream([]), $redirected, null, $previous);
        }

        return new Response(
            '2',
            $step['status'],
            null,
            $step['headers'],
            new ReadableIterableStream($body),
            end($chain),
            null,
            $previous,
        );
    }

    public function readRequest(int $index = 0): Request
    {
        return $this->requests[$index] ?? throw new \OutOfBoundsException(\sprintf('No request was recorded at index %d.', $index));
    }

    /**
     * @return array<string, mixed>
     */
    public function readSentEnvelope(int $index = 0): array
    {
        $decoded = json_decode(buffer($this->readRequest($index)->getBody()->getContent()), associative: true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param list<string> $chunks
     *
     * @return \Traversable<int, string>
     */
    private function trackDrain(int $index, array $chunks): \Traversable
    {
        yield from $chunks;

        // Emitting one more chunk parks this generator until the consumer asks for it, so an abandoned body is never marked drained.
        yield '';

        $this->drainedBodies[$index] = true;
    }

    /**
     * @param list<string> $chunks
     *
     * @return \Traversable<int, string>
     */
    private function failPartway(array $chunks, HttpException $failure): \Traversable
    {
        yield from $chunks;

        throw $failure;
    }

    /**
     * @param list<string>       $chunks
     * @param null|Future<mixed> $resume
     * @param list<string>       $later
     *
     * @return \Traversable<int, string>
     */
    private function openStream(array $chunks, ?Future $resume = null, array $later = []): \Traversable
    {
        yield from $chunks;

        if (null !== $resume) {
            $resume->await();

            yield from $later;
        }

        // Never completed, so the stream stays open without holding a timer the test would have to cancel.
        (new DeferredFuture())->getFuture()->await();
    }

    /**
     * @param array<non-empty-string, list<string>|string> $headers
     * @param list<string>                                 $chunks
     * @param null|Future<mixed>                           $gate
     * @param null|non-empty-list<string>                  $hops
     */
    private function scheduleAnswer(
        int $status,
        array $headers,
        array $chunks,
        ?Future $gate = null,
        ?array $hops = null,
    ): self {
        $step = ['status' => $status, 'headers' => $headers, 'chunks' => $chunks];

        if (null !== $gate) {
            $step['gate'] = $gate;
        }

        if (null !== $hops) {
            $step['hops'] = $hops;
        }

        $this->script[] = $step;

        return $this;
    }
}
