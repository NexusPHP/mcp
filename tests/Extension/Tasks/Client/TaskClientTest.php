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

namespace Nexus\Mcp\Tests\Extension\Tasks\Client;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Nexus\Assert\Assert;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Tasks\Client\Exception\StalledTaskException;
use Nexus\Mcp\Extension\Tasks\Client\TaskClient;
use Nexus\Mcp\Extension\Tasks\Client\TasksClientExtension;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(TaskClient::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskClientTest extends AbstractMcpTestCase
{
    public function testCallToolAsTaskDecodesATaskHandleAndAdvertisesTheExtension(): void
    {
        [$tasks, $transport] = self::buildTaskClient();

        $call = async(static fn(): CallToolResult|CreateTaskResult|InputRequiredResult => $tasks->callToolAsTask('slow_compute', ['seconds' => 2]));
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame('tools/call', $sent::getMethod());
        self::assertStringContainsString(
            '"extensions":{"io.modelcontextprotocol/tasks":{}}',
            json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => [...self::buildTaskPayload('working'), 'resultType' => 'task'],
        ]);

        $result = $call->await();

        self::assertInstanceOf(CreateTaskResult::class, $result);
        self::assertSame('task-1', $result->taskId);
    }

    public function testCallToolAsTaskCarriesTheContinuationParameters(): void
    {
        [$tasks, $transport] = self::buildTaskClient();

        $call = async(static fn(): CallToolResult|CreateTaskResult|InputRequiredResult => $tasks->callToolAsTask(
            'test_tool_with_task',
            inputResponses: ['task_user_name' => new ElicitResult(action: ElicitAction::Accept, content: ['name' => 'Alice'])],
            requestState: 'token-1',
        ));
        $call->ignore();
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        $encoded = json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('"inputResponses":{"task_user_name":{"action":"accept","content":{"name":"Alice"}}}', $encoded);
        self::assertStringContainsString('"requestState":"token-1"', $encoded);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => [...self::buildTaskPayload('working'), 'resultType' => 'task'],
        ]);

        self::assertInstanceOf(CreateTaskResult::class, $call->await());
    }

    public function testAwaitTaskAbortsWhenItsCancellationFires(): void
    {
        [$tasks, $transport] = self::buildTaskClient();
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));
        $cancellation = new DeferredCancellation();

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, cancellation: $cancellation->getCancellation()));
        $await->ignore();

        self::respondToNextTasksGet($transport, self::buildTaskPayload('working'), 0);
        $cancellation->cancel();

        $this->expectException(CancelledException::class);

        $await->await();
    }

    public function testCallToolAsTaskDecodesADirectResult(): void
    {
        [$tasks, $transport] = self::buildTaskClient();

        $call = async(static fn(): CallToolResult|CreateTaskResult|InputRequiredResult => $tasks->callToolAsTask('greet'));
        $transport->nextSend()->await();
        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => ['resultType' => 'complete', 'content' => []],
        ]);

        self::assertInstanceOf(CallToolResult::class, $call->await());
    }

    public function testGetTaskDecodesTheState(): void
    {
        [$tasks, $transport] = self::buildTaskClient();

        $call = async(static fn(): GetTaskResult => $tasks->getTask('task-1'));
        $transport->nextSend()->await();
        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame('tasks/get', $sent::getMethod());
        self::assertSame(1, $sent->id->id);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => self::buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]),
        ]);

        $state = $call->await();
        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
    }

    public function testUpdateAndCancelSendTheirMethodsAndSwallowTheAck(): void
    {
        [$tasks, $transport] = self::buildTaskClient();

        $update = async(static function () use ($tasks): void {
            $tasks->updateTask('task-1', []);
        });
        $transport->nextSend()->await();
        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame('tasks/update', $sent::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $sent->id->id, 'result' => ['resultType' => 'complete']]);
        $update->await();

        $cancel = async(static function () use ($tasks): void {
            $tasks->cancelTask('task-1');
        });
        $transport->nextSend()->await();
        self::assertArrayHasKey(1, $transport->sent);
        $sent = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame('tasks/cancel', $sent::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $sent->id->id, 'result' => ['resultType' => 'complete']]);
        $cancel->await();

        self::assertCount(2, $transport->sent);
    }

    public function testAwaitTaskPollsUntilTheTerminalState(): void
    {
        [$tasks, $transport] = self::buildTaskClient();
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));

        self::respondToNextTasksGet($transport, self::buildTaskPayload('working'), 0);
        self::respondToNextTasksGet($transport, self::buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 1);

        $state = $await->await();

        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
        self::assertSame(['resultType' => 'complete', 'content' => []], $state->result);
    }

    public function testAwaitTaskDispatchesInputRequestsThroughTheResolver(): void
    {
        [$tasks, $transport] = self::buildTaskClient();
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));

        $seenKeys = null;
        $resolver = static function (array $requests) use (&$seenKeys): array {
            $seenKeys = array_keys($requests);

            return ['confirm' => new ElicitResult(action: ElicitAction::Accept)];
        };
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, $resolver));

        self::respondToNextTasksGet($transport, self::buildTaskPayload('input_required', ['inputRequests' => [
            'confirm' => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Confirm?',
                    'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ],
        ]]), 0);

        // The resolver's answers ride a tasks/update before polling resumes.
        self::awaitSendCount($transport, 2);
        self::assertArrayHasKey(1, $transport->sent);
        $update = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        self::respondToNextTasksGet($transport, self::buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 2);

        $state = $await->await();

        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
        self::assertSame(['confirm'], $seenKeys);
    }

    public function testAwaitTaskSleepsTheFallbackThenTheServerSuggestedInterval(): void
    {
        $slept = [];
        [$tasks, $transport] = self::buildTaskClient(sleep: static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        // Neither the handle nor the first poll carries an interval, so the
        // first sleep uses the fallback and the second the suggested interval.
        $payload = self::buildTaskPayload('working');
        unset($payload['pollIntervalMs']);
        $handle = CreateTaskResult::fromArray($payload);

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $first = self::buildTaskPayload('working');
        unset($first['pollIntervalMs']);
        self::respondToNextTasksGet($transport, $first, 0);
        self::respondToNextTasksGet($transport, self::buildTaskPayload('working'), 1);
        self::respondToNextTasksGet($transport, self::buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 2);

        $await->await();

        self::assertSame([1.0, 0.001], $slept);
    }

    public function testAwaitTaskStallsAtTheDefaultCeiling(): void
    {
        $stopped = new \ArrayObject([false]);
        [$tasks, $transport] = self::buildTaskClient(sleep: static function (): void {});
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));
        $responder = self::startInputRequiredResponder($transport, $stopped);

        try {
            $tasks->awaitTask($handle);
            self::fail('Expected the default stall ceiling to trip.');
        } catch (StalledTaskException $e) {
            self::assertSame('Task "task-1" stayed input_required for 60 polls without new input requests.', $e->getMessage());
            self::assertCount(60, $transport->sent);
        } finally {
            $stopped[0] = true;
            $responder->await();
        }
    }

    public function testAwaitTaskStallsWhenTheResolverKeepsAnsweringNothing(): void
    {
        $stopped = new \ArrayObject([false]);
        [$tasks, $transport] = self::buildTaskClient(stallCeiling: 2, sleep: static function (): void {});
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));
        $responder = self::startInputRequiredResponder($transport, $stopped);

        try {
            $tasks->awaitTask($handle, static fn(array $requests): array => []);
            self::fail('Expected the stall ceiling to trip on an unproductive resolver.');
        } catch (StalledTaskException $e) {
            self::assertSame('Task "task-1" stayed input_required for 2 polls without new input requests.', $e->getMessage());
            self::assertCount(2, $transport->sent);
        } finally {
            $stopped[0] = true;
            $responder->await();
        }
    }

    public function testAwaitTaskResetsItsStallCounterAfterProgress(): void
    {
        $stopped = new \ArrayObject([false]);
        [$tasks, $transport] = self::buildTaskClient(stallCeiling: 1, sleep: static function (): void {});
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));
        $responder = self::startInputRequiredResponder($transport, $stopped);

        try {
            $tasks->awaitTask($handle, static fn(array $requests): array => [
                'confirm' => new ElicitResult(action: ElicitAction::Accept),
            ]);
            self::fail('Expected the stall ceiling to trip after the answered round.');
        } catch (StalledTaskException $e) {
            self::assertSame('Task "task-1" stayed input_required for 1 polls without new input requests.', $e->getMessage());
            // One answered round (poll + update) plus exactly one stalled poll.
            self::assertCount(3, $transport->sent);
        } finally {
            $stopped[0] = true;
            $responder->await();
        }
    }

    public function testAwaitTaskOnlyOffersUnansweredRequestsAndKeepsItsLedger(): void
    {
        $seen = [];
        $resolver = static function (array $requests) use (&$seen): array {
            Assert::that($requests)->keys()->isIntOrNonEmptyString();

            $seen[] = array_keys($requests);
            $answers = [];

            foreach (array_keys($requests) as $key) {
                $answers[$key] = new ElicitResult(action: ElicitAction::Accept);
            }

            return $answers;
        };

        [$tasks, $transport] = self::buildTaskClient(sleep: static function (): void {});
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, $resolver));
        // Should the loop fail before `await()`, an unobserved future error
        // would poison the event loop for every later test in the process.
        $await->ignore();

        // Round 1: 'confirm' requested and answered.
        self::respondToNextTasksGet($transport, self::buildTaskPayload('input_required', ['inputRequests' => self::buildInputRequestsPayload()]), 0);
        self::awaitSendCount($transport, 2);
        self::assertArrayHasKey(1, $transport->sent);
        $update = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        // Round 2: the answered 'confirm' is still listed next to a new key,
        // so only 'extra' may reach the resolver.
        self::respondToNextTasksGet($transport, self::buildTaskPayload('input_required', ['inputRequests' => [
            ...self::buildInputRequestsPayload(),
            ...self::buildInputRequestsPayload('extra'),
        ]]), 2);
        self::awaitSendCount($transport, 4);
        self::assertArrayHasKey(3, $transport->sent);
        $update = $transport->sent[3]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        // Round 3: both answered keys are listed again. The accumulated ledger
        // leaves nothing unanswered, so this poll stalls without a resolver call.
        self::respondToNextTasksGet($transport, self::buildTaskPayload('input_required', ['inputRequests' => [
            ...self::buildInputRequestsPayload(),
            ...self::buildInputRequestsPayload('extra'),
        ]]), 4);

        self::respondToNextTasksGet($transport, self::buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 5);

        $await->await();

        self::assertSame([['confirm'], ['extra']], $seen);
        self::assertCount(6, $transport->sent);
    }

    public function testAwaitTaskSleepsForRealByDefault(): void
    {
        [$tasks, $transport] = self::buildTaskClient();
        $handle = CreateTaskResult::fromArray([...self::buildTaskPayload('working'), 'pollIntervalMs' => 1]);

        $started = hrtime(true);
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        self::respondToNextTasksGet($transport, self::buildTaskPayload('working', ['pollIntervalMs' => 1]), 0);
        self::respondToNextTasksGet($transport, self::buildTaskPayload('working', ['pollIntervalMs' => 1]), 1);
        self::respondToNextTasksGet($transport, self::buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []], 'pollIntervalMs' => 1]), 2);

        $await->await();

        self::assertGreaterThanOrEqual(0.002, (hrtime(true) - $started) / 1e9);
    }

    public function testAwaitTaskThrowsOnceTheStallCeilingIsReached(): void
    {
        [$tasks, $transport] = self::buildTaskClient(stallCeiling: 2);
        $handle = CreateTaskResult::fromArray(self::buildTaskPayload('working'));

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        self::respondToNextTasksGet($transport, self::buildTaskPayload('input_required', ['inputRequests' => [
            'confirm' => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Confirm?',
                    'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ],
        ]]), 0);
        self::respondToNextTasksGet($transport, self::buildTaskPayload('input_required', ['inputRequests' => [
            'confirm' => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Confirm?',
                    'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ],
        ]]), 1);

        $this->expectException(StalledTaskException::class);
        $this->expectExceptionMessageIs('Task "task-1" stayed input_required for 2 polls without new input requests.');

        $await->await();
    }

    /**
     * @param null|\Closure(float): void $sleep
     *
     * @return array{TaskClient, RecordingTransport}
     */
    private static function buildTaskClient(?int $stallCeiling = null, ?\Closure $sleep = null): array
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            // A finite request timeout turns an exhausted response script into
            // a fast failure instead of a hang.
            ->setRequestTimeout(0.5)
            ->enableExtension(new TasksClientExtension())
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $tasks = null === $stallCeiling
            ? new TaskClient($client, sleep: $sleep)
            : new TaskClient($client, stallCeiling: $stallCeiling, sleep: $sleep);

        return [$tasks, $transport];
    }

    /**
     * @param non-empty-string $key
     *
     * @return array<string, array<string, mixed>>
     */
    private static function buildInputRequestsPayload(string $key = 'confirm'): array
    {
        return [
            $key => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Confirm?',
                    'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    /**
     * Answers every `tasks/get` with the same parked `input_required` state
     * and every `tasks/update` with an ack, until stopped. The response cap
     * refuses a runaway poll loop: past it the client's next request starves
     * into its request timeout instead of sustaining an endless exchange.
     *
     * @param \ArrayObject<int, bool> $stopped
     *
     * @return Future<mixed>
     */
    private static function startInputRequiredResponder(RecordingTransport $transport, \ArrayObject $stopped): Future
    {
        return async(static function () use ($transport, $stopped): void {
            $index = 0;

            while (true !== $stopped[0] && $index < 70) {
                if (\count($transport->sent) < $index + 1) {
                    delay(0.000_5);

                    continue;
                }

                $sent = $transport->sent[$index]['message'] ?? null;

                if (! $sent instanceof JsonRpcRequest) {
                    return;
                }

                $method = $sent::getMethod();
                $transport->emitMessage([
                    'jsonrpc' => '2.0',
                    'id' => $sent->id->id,
                    'result' => 'tasks/update' === $method
                        ? ['resultType' => 'complete']
                        : self::buildTaskPayload('input_required', ['inputRequests' => self::buildInputRequestsPayload()]),
                ]);
                ++$index;
            }
        });
    }

    private static function awaitSendCount(RecordingTransport $transport, int $count): void
    {
        $deadline = hrtime(true) + 2_000_000_000;

        while ($count > \count($transport->sent)) {
            if ($deadline <= hrtime(true)) {
                self::fail(\sprintf('Timed out waiting for %d sends.', $count));
            }

            delay(0.001);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function respondToNextTasksGet(RecordingTransport $transport, array $payload, int $index): void
    {
        self::awaitSendCount($transport, $index + 1);
        self::assertArrayHasKey($index, $transport->sent);
        $sent = $transport->sent[$index]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame('tasks/get', $sent::getMethod());

        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $sent->id->id, 'result' => $payload]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function buildTaskPayload(string $status, array $extra = []): array
    {
        return [
            'resultType' => 'complete',
            'taskId' => 'task-1',
            'status' => $status,
            'createdAt' => '2026-08-04T12:00:00+00:00',
            'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
            'ttlMs' => 300_000,
            'pollIntervalMs' => 1,
            ...$extra,
        ];
    }
}
