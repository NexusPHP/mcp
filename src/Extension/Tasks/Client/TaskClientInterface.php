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

namespace Nexus\Mcp\Extension\Tasks\Client;

use Amp\Cancellation;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Tasks\Client\Exception\StalledTaskException;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;

/**
 * Client-side surface of the tasks extension: task-aware tool calls, the
 * typed `tasks/*` methods, and a polling loop that drives a task to a
 * terminal state.
 */
interface TaskClientInterface
{
    /**
     * Calls a tool, decoding the server's per-request choice: the direct
     * result, a synchronous input request, or a task handle. The continuation
     * parameters re-issue a call that answered an `InputRequiredResult`.
     *
     * @param non-empty-string                                $name
     * @param null|array<array-key, mixed>                    $arguments
     * @param null|array<int|non-empty-string, InputResponse> $inputResponses
     */
    public function callToolAsTask(string $name, ?array $arguments = null, ?array $inputResponses = null, ?string $requestState = null): CallToolResult|CreateTaskResult|InputRequiredResult;

    /**
     * Polls the current state of a task.
     *
     * @param non-empty-string $taskId
     */
    public function getTask(string $taskId): GetTaskResult;

    /**
     * Supplies input responses to a task waiting in `input_required`.
     *
     * @param non-empty-string                           $taskId
     * @param array<int|non-empty-string, InputResponse> $inputResponses
     */
    public function updateTask(string $taskId, array $inputResponses): void;

    /**
     * Requests cooperative cancellation of a task.
     *
     * @param non-empty-string $taskId
     */
    public function cancelTask(string $taskId): void;

    /**
     * Polls a task at the server-suggested interval until it settles,
     * dispatching new input requests to `$resolveInputRequests` and answering
     * through `tasks/update`. Returns the terminal state, whichever terminal
     * status it carries. Cancelling `$cancellation` aborts the poll loop.
     *
     * @param null|\Closure(array<int|non-empty-string, InputRequest>): array<int|non-empty-string, InputResponse> $resolveInputRequests
     *
     * @throws StalledTaskException
     */
    public function awaitTask(CreateTaskResult $task, ?\Closure $resolveInputRequests = null, ?Cancellation $cancellation = null): GetTaskResult;
}
