# Tasks

The client half of the tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) pairs two classes.
`TasksClientExtension` advertises the capability and gates the outbound `tasks/*` methods. The `TaskClient`
facade speaks them:

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Extension\Tasks\Client\TaskClient;
use Nexus\Mcp\Extension\Tasks\Client\TasksClientExtension;

$client = (new ClientBuilder())
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new TasksClientExtension())
    ->build();

$client->connect($transport);
$client->discover();

$tasks = new TaskClient($client);
```

Enabling the extension stamps `io.modelcontextprotocol/tasks` into the `_meta`
`io.modelcontextprotocol/clientCapabilities` envelope on every request. That is what permits the server to answer
a tool call with a task handle. After `discover()`, the outbound gate refuses `tasks/get`, `tasks/update`, and
`tasks/cancel` against a server that did not advertise the extension.

## Calling a tool that may become a task

Once the extension is enabled, route every tool that may carry a policy through the facade. The typed
`Client::callTool()` decodes with the core response envelope, which cannot represent a task handle. A call the
server diverts into a task therefore fails with a decode error while the task runs on unobserved.
`callToolAsTask()` decodes all three shapes the server may choose:

```php
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;

$outcome = $tasks->callToolAsTask('slow_compute', ['seconds' => 30, 'label' => 'report']);

if ($outcome instanceof CreateTaskResult) {
    $terminal = $tasks->awaitTask($outcome);
}
```

A `CallToolResult` is the ordinary synchronous answer. A `CreateTaskResult` is a task handle. An
`InputRequiredResult` is the synchronous multi-round input flow, which a task-supporting tool may still use
before it creates a task (see [answering an `InputRequiredResult`](input-required.md)). Answer it by re-issuing
the call with the continuation parameters. The resumed round may then become the task:

```php
$outcome = $tasks->callToolAsTask(
    'test_tool_with_task',
    inputResponses: ['task_user_name' => new ElicitResult(action: ElicitAction::Accept, content: ['name' => 'Alice'])],
    requestState: $outcome->requestState,
);
```

## Polling with `awaitTask()`

`awaitTask()` polls `tasks/get` at the server-suggested `pollIntervalMs` until the task settles. It returns the
terminal `GetTaskResult` whatever its status: `completed`, `failed`, or `cancelled`. When the task parks in
`input_required`, the new input requests are dispatched to the optional resolver, and its answers ride
`tasks/update`:

```php
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;

$terminal = $tasks->awaitTask($handle, static fn(array $inputRequests): array => [
    'confirm_delete' => new ElicitResult(action: ElicitAction::Accept, content: ['confirm' => true]),
]);
```

The resolver receives the unanswered requests keyed by their request key, and answers what it can. Anything
unanswered stays pending on the server.

Nothing but the `$cancellation` bounds a task that keeps `working`, so pass one carrying the deadline you can
afford, such as `new Amp\TimeoutCancellation(600)`. A server-suggested `pollIntervalMs` under the client's
`minPollIntervalMs` (`TaskClient::DEFAULT_MIN_POLL_INTERVAL_MS`, 100) is raised to it, so a server cannot
drive the client into polling at request rate, and one past `TaskClient::MAX_POLL_INTERVAL_MS` (one hour)
is held to that ceiling.

The waiting between polls goes through the `delay` constructor argument, a
`Nexus\Mcp\Client\Time\CancellableDelayInterface`: a [`nexusphp/clock`](https://github.com/NexusPHP/clock)
`Delay` whose `sleep()` also takes the optional `Amp\Cancellation`. The default `EventLoopDelay` suspends the
calling fiber on the event loop. A test passes a double that records the requested durations and returns
immediately, which makes an `awaitTask()` scenario run in microseconds.

### Keys and stalls

Keys already answered in the current park are not dispatched again. A key the server issues again after the task
resumes `working` is offered again. An answer for a key that was never offered is discarded rather than sent.

A task that stays `input_required` while the resolver sends nothing for `stallCeiling` consecutive polls throws
`StalledTaskException` rather than spin forever. The default is 60, and it is constructor-tunable. A `working`
poll breaks the streak.

A task that stays `working` is polled for as long as it takes. Pass an `Amp\Cancellation` as the third argument
to bound the wait. The loop aborts with `CancelledException` when it fires.

## The typed methods

`getTask()`, `updateTask()`, and `cancelTask()` are the direct counterparts of the three `tasks/*` methods. They
ride [`sendRequest()`](requests.md#the-escape-hatch-sendrequest) with the lifecycle `_meta` stamped.
`cancelTask()` is cooperative. The server acks immediately, and the task settles as `cancelled` once its fiber
observes the cancellation.

The server half is documented in [Server tasks](../server/tasks.md), and
[examples/tasks.php](../../examples/tasks.php) runs the whole loop in one process.
