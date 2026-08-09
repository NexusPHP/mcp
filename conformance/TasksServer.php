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

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\BooleanSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\RequestStateSigner;
use Nexus\Mcp\Server\ServerContext;

use function Amp\delay;

/**
 * Tools exercised by the referee's `tasks-*` scenarios (SEP-2663).
 */
final class TasksServer
{
    use ElicitationHelpers;

    private readonly RequestStateSigner $states;

    public function __construct()
    {
        $this->states = RequestStateSigner::generate();
    }

    #[AsTool(name: 'greet', description: 'Greets the given name synchronously.')]
    public function greet(string $name): string
    {
        return sprintf('Hello, %s!', $name);
    }

    #[AsTool(name: 'slow_compute', description: 'Computes for the given number of seconds.')]
    public function slowCompute(float $seconds, string $label, ServerContext $context): string
    {
        if ($seconds > 0) {
            delay($seconds, cancellation: $context->cancellation);
        }

        return sprintf('Computed %s after %s seconds.', $label, $seconds);
    }

    /**
     * The referee asserts the failure lands as a `completed` task whose result
     * carries `isError`, never as the `failed` status.
     */
    #[AsTool(name: 'failing_job', description: 'Runs briefly, then fails as a tool error.')]
    public function failingJob(ServerContext $context): never
    {
        delay(1.0, cancellation: $context->cancellation);

        throw new RuntimeException('This job intentionally fails for testing');
    }

    /**
     * The referee asserts a protocol-level throw lands as the `failed` status
     * with an inlined error object.
     */
    #[AsTool(name: 'protocol_error_job', description: 'Fails at the protocol level.')]
    public function protocolErrorJob(ServerContext $context): never
    {
        throw new InvalidParamsException($context->requestId, 'This job fails at the protocol level.');
    }

    /**
     * @param string $filename The file whose deletion needs confirming
     */
    #[AsTool(name: 'confirm_delete', description: 'Asks for confirmation, then deletes.')]
    public function confirmDelete(string $filename, ServerContext $context): CallToolResult|InputRequiredResult
    {
        if (self::readAcceptedAnswer($context, 'confirm_delete', 'confirm') === true) {
            return new CallToolResult(content: [new TextContent(text: sprintf('Deleted %s.', $filename))]);
        }

        if (self::hasAnyAnswer($context, 'confirm_delete')) {
            return new CallToolResult(content: [new TextContent(text: sprintf('Left %s in place.', $filename))]);
        }

        return self::ask('confirm_delete', sprintf('Delete %s?', $filename), 'confirm', new BooleanSchema(), $this->states->sign('confirm_delete'));
    }

    #[AsTool(name: 'multi_input', description: 'Fans out two input requests at once.')]
    public function multiInput(ServerContext $context): CallToolResult|InputRequiredResult
    {
        $name = self::readAcceptedAnswer($context, 'multi_name', 'name');
        $confirmed = self::readAcceptedAnswer($context, 'multi_confirm', 'confirm');

        if (is_string($name) && true === $confirmed) {
            return new CallToolResult(content: [new TextContent(text: sprintf('Ran for %s.', $name))]);
        }

        if (self::hasAnyAnswer($context, 'multi_name') || self::hasAnyAnswer($context, 'multi_confirm')) {
            return new CallToolResult(content: [new TextContent(text: 'Run cancelled.')]);
        }

        return new InputRequiredResult(
            inputRequests: [
                'multi_name' => self::buildInputRequest('What is your name?', 'name', new StringSchema()),
                'multi_confirm' => self::buildInputRequest('Confirm the run?', 'confirm', new BooleanSchema()),
            ],
            requestState: $this->states->sign('multi_input'),
        );
    }

    /**
     * MRTR composes before task creation: the first round resolves its input
     * synchronously and only the resumed round runs as a task.
     */
    #[AsTool(name: 'test_tool_with_task', description: 'Asks for a name synchronously, then greets it from a task.')]
    public function toolWithTask(ServerContext $context): CallToolResult|InputRequiredResult
    {
        $name = self::readAcceptedAnswer($context, 'task_user_name', 'name');

        if (is_string($name) && '' !== $name) {
            return new CallToolResult(content: [new TextContent(text: sprintf('Hello, %s!', $name))]);
        }

        if (self::hasAnyAnswer($context, 'task_user_name')) {
            return new CallToolResult(content: [new TextContent(text: 'Hello, stranger!')]);
        }

        return self::ask('task_user_name', 'What is your name?', 'name', state: $this->states->sign('task_user_name'));
    }
}
