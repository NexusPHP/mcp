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

/*
 * Runs a server and a client in a single process over `InMemoryTransport::createPair()`,
 * with no subprocess. The server runs in a background coroutine while the client
 * drives it through the typed surface. This is the pattern for embedding an MCP
 * server inside a host application or exercising one in tests.
 *
 * Run with:
 *
 *     php examples/in-memory.php
 */

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\NullLogger;

use function Amp\async;

[$serverSide, $clientSide] = InMemoryTransport::createPair();

$server = new ServerBuilder()
    ->setLogger(new ExampleLogger())
    ->setServerInfo(name: 'nexus-in-memory-example', version: '0.1.0')
    ->addTool(
        new Tool(
            name: 'add',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'number'],
                    'b' => ['type' => 'number'],
                ],
                'required' => ['a', 'b'],
            ],
            description: 'Adds two numbers and returns the sum.',
        ),
        static function (?array $args, ServerContext $context): CallToolResult {
            $a = is_numeric($args['a'] ?? null) ? (float) $args['a'] : 0.0;
            $b = is_numeric($args['b'] ?? null) ? (float) $args['b'] : 0.0;

            return new CallToolResult(content: [
                new TextContent(text: sprintf('%g + %g = %g', $a, $b, $a + $b)),
            ]);
        },
    )
    ->build()
;

$client = new ClientBuilder()
    ->setLogger(new NullLogger())
    ->setClientInfo(name: 'nexus-in-memory-example-client', version: '0.1.0')
    ->build()
;

// The server blocks while running its loop, so it lives in a background
// coroutine. The client's awaiting calls below yield to it.
$serverRun = async(static fn() => $server->run($serverSide));

$client->connect($clientSide);

try {
    $discoverResult = $client->discover();

    fwrite(\STDOUT, sprintf(
        "Connected in-process to %s v%s (protocol versions: %s)\n\n",
        $discoverResult->meta->serverInfo?->name ?? '(anonymous)',
        $discoverResult->meta->serverInfo?->version ?? '?',
        implode(', ', $discoverResult->supportedVersions),
    ));

    fwrite(\STDOUT, "=== tools/list ===\n");

    foreach ($client->listTools()->tools as $tool) {
        fwrite(\STDOUT, sprintf("- %s: %s\n", $tool->name, $tool->description ?? '(no description)'));
    }

    fwrite(\STDOUT, "\n=== tools/call add (a=2, b=3) ===\n");
    $result = $client->callTool(name: 'add', arguments: ['a' => 2, 'b' => 3]);

    foreach ($result->content as $block) {
        if ($block instanceof TextContent) {
            fwrite(\STDOUT, sprintf("    result: %s\n", $block->text));
        }
    }
} finally {
    $client->disconnect();
}

$serverRun->await();
