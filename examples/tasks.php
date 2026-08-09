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

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Extension\Tasks\Client\TaskClient;
use Nexus\Mcp\Extension\Tasks\Client\TasksClientExtension;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Server\TasksServerExtension;
use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;

use function Amp\async;
use function Amp\delay;

[$serverSide, $clientSide] = InMemoryTransport::createPair();

$server = (new ServerBuilder())
    ->setServerInfo(name: 'nexus-tasks-example', version: '0.1.0')
    ->addTool(
        new Tool(
            name: 'slow_report',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'steps' => ['type' => 'integer', 'description' => 'Work steps to simulate.'],
                ],
            ],
            description: 'Produces a report after a few seconds of simulated work.',
        ),
        static function (?array $args, ServerContext $context): CallToolResult {
            $steps = is_int($args['steps'] ?? null) ? $args['steps'] : 5;

            for ($step = 1; $step <= $steps; ++$step) {
                delay(0.4, cancellation: $context->cancellation);
            }

            return new CallToolResult(
                content: [new TextContent(text: sprintf('Report ready after %d steps.', $steps))],
                structuredContent: ['steps' => $steps, 'finishedAt' => date(\DATE_ATOM)],
            );
        },
    )
    ->addTool(
        new Tool(
            name: 'endless_job',
            inputSchema: ['type' => 'object'],
            description: 'Runs until cancelled, to demonstrate tasks/cancel.',
        ),
        static function (?array $args, ServerContext $context): CallToolResult {
            delay(3_600.0, cancellation: $context->cancellation);

            return new CallToolResult(content: [new TextContent(text: 'Unreachable.')]);
        },
    )
    ->enableExtension(new TasksServerExtension(
        toolPolicies: [
            'slow_report' => new ToolTaskPolicy(support: TaskSupport::Optional),
            'endless_job' => new ToolTaskPolicy(support: TaskSupport::Optional),
        ],
        defaultTtlMs: 60_000,
        defaultPollIntervalMs: 200,
    ))
    ->build()
;

$client = (new ClientBuilder())
    ->setClientInfo(name: 'nexus-tasks-example-client', version: '0.1.0')
    ->enableExtension(new TasksClientExtension())
    ->build()
;

$serverRun = async(static fn() => $server->run($serverSide));

$client->connect($clientSide);
$tasks = new TaskClient($client);

try {
    $discoverResult = $client->discover();

    fwrite(\STDOUT, sprintf(
        "Connected to %s, advertising extensions: %s\n\n",
        $discoverResult->meta->serverInfo->name ?? '(anonymous)',
        implode(', ', array_keys($client->getServerCapabilities()->extensions ?? [])),
    ));

    fwrite(\STDOUT, "=== callToolAsTask slow_report: the server answers with a task handle ===\n");
    $outcome = $tasks->callToolAsTask('slow_report', ['steps' => 4]);

    if (! $outcome instanceof CreateTaskResult) {
        fwrite(\STDOUT, "    The server chose a synchronous answer.\n");

        exit(0);
    }

    fwrite(\STDOUT, sprintf(
        "    taskId: %s\n    status: %s\n    pollIntervalMs: %d, ttlMs: %s\n",
        $outcome->taskId,
        $outcome->status->value,
        $outcome->pollIntervalMs ?? 1_000,
        null !== $outcome->ttlMs ? (string) $outcome->ttlMs : 'unlimited',
    ));

    fwrite(\STDOUT, "\n=== getTask: one typed poll while the tool still works ===\n");
    fwrite(\STDOUT, sprintf("    status: %s\n", $tasks->getTask($outcome->taskId)->status->value));

    fwrite(\STDOUT, "\n=== awaitTask: polling at the server-suggested interval until terminal ===\n");
    $terminal = $tasks->awaitTask($outcome);
    fwrite(\STDOUT, sprintf(
        "    status: %s\n    result: %s\n",
        $terminal->status->value,
        json_encode($terminal->result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
    ));

    fwrite(\STDOUT, "\n=== tasks/cancel: cooperative cancellation of endless_job ===\n");
    $endless = $tasks->callToolAsTask('endless_job');

    if ($endless instanceof CreateTaskResult) {
        $tasks->cancelTask($endless->taskId);
        $cancelled = $tasks->awaitTask($endless);
        fwrite(\STDOUT, sprintf("    status: %s\n", $cancelled->status->value));
    }
} finally {
    $client->disconnect();
}

$serverRun->await();
