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
use Nexus\Mcp\Client\Time\CancellableDelayInterface;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Tasks\Client\Exception\StalledTaskException;
use Nexus\Mcp\Extension\Tasks\Client\TaskClient;
use Nexus\Mcp\Extension\Tasks\Client\TasksClientExtension;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\UpdateTaskRequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Time\RecordingDelay;
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
        [$tasks, $transport] = $this->buildTaskClient();

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
            'result' => [...$this->buildTaskPayload('working'), 'resultType' => 'task'],
        ]);

        $result = $call->await();

        self::assertInstanceOf(CreateTaskResult::class, $result);
        self::assertSame('task-1', $result->taskId);
    }

    public function testCallToolAsTaskCarriesTheContinuationParameters(): void
    {
        [$tasks, $transport] = $this->buildTaskClient();

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
            'result' => [...$this->buildTaskPayload('working'), 'resultType' => 'task'],
        ]);

        self::assertInstanceOf(CreateTaskResult::class, $call->await());
    }

    public function testAwaitTaskAbortsWhenItsCancellationFires(): void
    {
        [$tasks, $transport] = $this->buildTaskClient();
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $cancellation = new DeferredCancellation();

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, cancellation: $cancellation->getCancellation()));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working'), 0);
        $cancellation->cancel();

        $this->expectException(CancelledException::class);

        $await->await();
    }

    public function testCallToolAsTaskDecodesADirectResult(): void
    {
        [$tasks, $transport] = $this->buildTaskClient();

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
        [$tasks, $transport] = $this->buildTaskClient();

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
            'result' => $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]),
        ]);

        $state = $call->await();
        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
    }

    public function testUpdateAndCancelSendTheirMethodsAndSwallowTheAck(): void
    {
        [$tasks, $transport] = $this->buildTaskClient();

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
        [$tasks, $transport] = $this->buildTaskClient();
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working'), 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 1);

        $state = $await->await();

        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
        self::assertSame(['resultType' => 'complete', 'content' => []], $state->result);
    }

    public function testAwaitTaskDispatchesInputRequestsThroughTheResolver(): void
    {
        [$tasks, $transport] = $this->buildTaskClient();
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));

        $seenKeys = null;
        $resolver = static function (array $requests) use (&$seenKeys): array {
            $seenKeys = array_keys($requests);

            return ['confirm' => new ElicitResult(action: ElicitAction::Accept)];
        };
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, $resolver));

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => [
            'confirm' => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Confirm?',
                    'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ],
        ]]), 0);

        $this->awaitSendCount($transport, 2);
        self::assertArrayHasKey(1, $transport->sent);
        $update = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 2);

        $state = $await->await();

        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
        self::assertSame(['confirm'], $seenKeys);
    }

    public function testAwaitTaskSleepsTheFallbackThenTheServerSuggestedInterval(): void
    {
        $delay = new RecordingDelay();
        [$tasks, $transport] = $this->buildTaskClient(delay: $delay);

        $payload = $this->buildTaskPayload('working');
        unset($payload['pollIntervalMs']);
        $handle = CreateTaskResult::fromArray($payload);

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $first = $this->buildTaskPayload('working');
        unset($first['pollIntervalMs']);
        $this->respondToNextTasksGet($transport, $first, 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working'), 1);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 2);

        $await->await();

        self::assertSame([1.0, 0.1], $delay->sleeps, 'The 1 ms suggestion is raised to the default floor.');
    }

    public function testAwaitTaskFollowsAServerSuggestedIntervalAboveTheFloor(): void
    {
        $delay = new RecordingDelay();
        [$tasks, $transport] = $this->buildTaskClient(delay: $delay, minPollIntervalMs: 50);

        $handle = CreateTaskResult::fromArray([...$this->buildTaskPayload('working'), 'pollIntervalMs' => 250]);

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working', ['pollIntervalMs' => 50]), 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working', ['pollIntervalMs' => 49]), 1);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 2);

        $await->await();

        self::assertSame([0.05, 0.05], $delay->sleeps, 'An interval at the floor passes, one under it is raised to the floor.');
    }

    public function testAwaitTaskHoldsAnAbsurdSuggestedIntervalToTheCeiling(): void
    {
        $delay = new RecordingDelay();
        [$tasks, $transport] = $this->buildTaskClient(delay: $delay);

        $handle = CreateTaskResult::fromArray([...$this->buildTaskPayload('working'), 'pollIntervalMs' => \PHP_INT_MAX]);

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working', ['pollIntervalMs' => \PHP_INT_MAX]), 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 1);

        $await->await();

        self::assertSame([3_600.0], $delay->sleeps);
    }

    public function testConstructorRejectsANonPositiveMinimumPollInterval(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Task minimum poll interval must be a positive integer, 0 given.');

        // @phpstan-ignore argument.type
        new TaskClient($client, minPollIntervalMs: 0);
    }

    public function testConstructorRejectsANonPositiveStallCeiling(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Task stall ceiling must be a positive integer, 0 given.');

        // @phpstan-ignore argument.type
        new TaskClient($client, stallCeiling: 0);
    }

    public function testAwaitTaskStallsAtTheDefaultCeiling(): void
    {
        $stopped = new \ArrayObject([false]);
        [$tasks, $transport] = $this->buildTaskClient(delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $responder = $this->startInputRequiredResponder($transport, $stopped);

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
        [$tasks, $transport] = $this->buildTaskClient(stallCeiling: 2, delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $responder = $this->startInputRequiredResponder($transport, $stopped);

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
        [$tasks, $transport] = $this->buildTaskClient(stallCeiling: 1, delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $responder = $this->startInputRequiredResponder($transport, $stopped);

        try {
            $tasks->awaitTask($handle, static fn(array $requests): array => [
                'confirm' => new ElicitResult(action: ElicitAction::Accept),
            ]);
            self::fail('Expected the stall ceiling to trip after the answered round.');
        } catch (StalledTaskException $e) {
            self::assertSame('Task "task-1" stayed input_required for 1 polls without new input requests.', $e->getMessage());
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

        [$tasks, $transport] = $this->buildTaskClient(delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, $resolver));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 0);
        $this->awaitSendCount($transport, 2);
        self::assertArrayHasKey(1, $transport->sent);
        $update = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => [
            ...$this->buildInputRequestsPayload(),
            ...$this->buildInputRequestsPayload('extra'),
        ]]), 2);
        $this->awaitSendCount($transport, 4);
        self::assertArrayHasKey(3, $transport->sent);
        $update = $transport->sent[3]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => [
            ...$this->buildInputRequestsPayload(),
            ...$this->buildInputRequestsPayload('extra'),
        ]]), 4);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 5);

        $await->await();

        self::assertSame([['confirm'], ['extra']], $seen);
        self::assertCount(6, $transport->sent);
    }

    public function testAwaitTaskStallsWhenTheResolverAnswersOutsideTheOfferedSet(): void
    {
        $stopped = new \ArrayObject([false]);
        [$tasks, $transport] = $this->buildTaskClient(stallCeiling: 2, delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $responder = $this->startInputRequiredResponder($transport, $stopped);

        try {
            $tasks->awaitTask($handle, static fn(array $requests): array => [
                'bogus' => new ElicitResult(action: ElicitAction::Accept),
            ]);
            self::fail('Expected the stall ceiling to trip on answers outside the offered set.');
        } catch (StalledTaskException $e) {
            self::assertSame('Task "task-1" stayed input_required for 2 polls without new input requests.', $e->getMessage());
            self::assertCount(2, $transport->sent);
        } finally {
            $stopped[0] = true;
            $responder->await();
        }
    }

    public function testAwaitTaskSendsOnlyTheOfferedKeys(): void
    {
        [$tasks, $transport] = $this->buildTaskClient(delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, static fn(array $requests): array => [
            'confirm' => new ElicitResult(action: ElicitAction::Accept),
            'bogus' => new ElicitResult(action: ElicitAction::Accept),
        ]));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 0);
        $this->awaitSendCount($transport, 2);
        self::assertArrayHasKey(1, $transport->sent);
        $update = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());

        self::assertInstanceOf(UpdateTaskRequestParams::class, $update->params);

        self::assertSame(['confirm'], array_keys($update->params->inputResponses));
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 2);

        $state = $await->await();
        self::assertInstanceOf(GetTaskResult::class, $state);
        self::assertSame(TaskStatus::Completed, $state->status);
    }

    public function testAwaitTaskOffersAKeyReissuedAfterAWorkingRound(): void
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

        [$tasks, $transport] = $this->buildTaskClient(delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle, $resolver));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 0);
        $this->awaitSendCount($transport, 2);
        self::assertArrayHasKey(1, $transport->sent);
        $update = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working'), 2);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 3);
        $this->awaitSendCount($transport, 5);
        self::assertArrayHasKey(4, $transport->sent);
        $update = $transport->sent[4]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $update);
        self::assertSame('tasks/update', $update::getMethod());
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $update->id->id, 'result' => ['resultType' => 'complete']]);

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []]]), 5);

        $await->await();

        self::assertSame([['confirm'], ['confirm']], $seen);
        self::assertCount(6, $transport->sent);
    }

    public function testAwaitTaskStallStreakBreaksOnAWorkingPoll(): void
    {
        [$tasks, $transport] = $this->buildTaskClient(stallCeiling: 2, delay: new RecordingDelay());
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working'), 1);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 2);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]), 3);

        $this->expectException(StalledTaskException::class);
        $this->expectExceptionMessageIs('Task "task-1" stayed input_required for 2 polls without new input requests.');

        $await->await();
    }

    public function testAwaitTaskSleepsForRealByDefault(): void
    {
        [$tasks, $transport] = $this->buildTaskClient(minPollIntervalMs: 1);
        $handle = CreateTaskResult::fromArray([...$this->buildTaskPayload('working'), 'pollIntervalMs' => 1]);

        $started = hrtime(true);
        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working', ['pollIntervalMs' => 1]), 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('working', ['pollIntervalMs' => 1]), 1);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('completed', ['result' => ['resultType' => 'complete', 'content' => []], 'pollIntervalMs' => 1]), 2);

        $await->await();

        self::assertGreaterThanOrEqual(0.002, (hrtime(true) - $started) / 1e9);
    }

    public function testAwaitTaskThrowsOnceTheStallCeilingIsReached(): void
    {
        [$tasks, $transport] = $this->buildTaskClient(stallCeiling: 2);
        $handle = CreateTaskResult::fromArray($this->buildTaskPayload('working'));

        $await = async(static fn(): GetTaskResult => $tasks->awaitTask($handle));
        $await->ignore();

        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => [
            'confirm' => [
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Confirm?',
                    'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ],
        ]]), 0);
        $this->respondToNextTasksGet($transport, $this->buildTaskPayload('input_required', ['inputRequests' => [
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
     * @param null|int<1, max> $stallCeiling
     * @param null|int<1, max> $minPollIntervalMs
     *
     * @return array{TaskClient, RecordingTransport}
     */
    private function buildTaskClient(?int $stallCeiling = null, ?CancellableDelayInterface $delay = null, ?int $minPollIntervalMs = null): array
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestTimeout(0.5)
            ->enableExtension(new TasksClientExtension())
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $arguments = [];

        if (null !== $delay) {
            $arguments['delay'] = $delay;
        }

        if (null !== $stallCeiling) {
            $arguments['stallCeiling'] = $stallCeiling;
        }

        if (null !== $minPollIntervalMs) {
            $arguments['minPollIntervalMs'] = $minPollIntervalMs;
        }

        $tasks = new TaskClient($client, ...$arguments);

        return [$tasks, $transport];
    }

    /**
     * @param non-empty-string $key
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildInputRequestsPayload(string $key = 'confirm'): array
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
     * Capped so a runaway poll starves instead of looping forever.
     *
     * @param \ArrayObject<int, bool> $stopped
     *
     * @return Future<mixed>
     */
    private function startInputRequiredResponder(RecordingTransport $transport, \ArrayObject $stopped): Future
    {
        return async(function () use ($transport, $stopped): void {
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
                        : $this->buildTaskPayload('input_required', ['inputRequests' => $this->buildInputRequestsPayload()]),
                ]);
                ++$index;
            }
        });
    }

    private function awaitSendCount(RecordingTransport $transport, int $count): void
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
    private function respondToNextTasksGet(RecordingTransport $transport, array $payload, int $index): void
    {
        $this->awaitSendCount($transport, $index + 1);
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
    private function buildTaskPayload(string $status, array $extra = []): array
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
