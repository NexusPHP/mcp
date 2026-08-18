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
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolStore;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\delay;

[$serverSide, $clientSide] = InMemoryTransport::createPair();

$subscriptions = new SubscriptionStore(
    toolsListChanged: true,
    resourceSubscriptions: true,
);

$builder = (new ServerBuilder())
    ->setLogger(new PsrLogger())
    ->setServerInfo(name: 'nexus-subscriptions-example', version: '0.1.0')
    ->addTool(
        new Tool(name: 'ping', inputSchema: ['type' => 'object'], description: 'Answers pong.'),
        static fn(?array $args, ServerContext $context): CallToolResult => new CallToolResult(content: [
            new TextContent(text: 'pong'),
        ]),
    )
    ->setSubscriptionStore($subscriptions)
;

$server = $builder->build();
$toolStore = $builder->getToolStore();
assert($toolStore instanceof ToolStore);

$client = (new ClientBuilder())
    ->setLogger(new NullLogger())
    ->setClientInfo(name: 'nexus-subscriptions-example-client', version: '0.1.0')
    ->build()
;

$serverRun = async(static fn() => $server->run($serverSide));

$client->connect($clientSide);

try {
    $client->discover();

    fwrite(\STDOUT, "=== subscriptions/listen (toolsListChanged + config://demo) ===\n");

    $stream = $client->listen(
        new SubscriptionFilter(
            toolsListChanged: true,
            resourceSubscriptions: ['config://demo'],
        ),
        static function (JsonRpcNotification $notification): void {
            fwrite(\STDOUT, sprintf("    <- %s\n", $notification::getMethod()));
        },
    );

    // listen() returns as soon as the request is away, so let the server open the stream first.
    delay(0.1);

    fwrite(\STDOUT, "\n=== the server grows a tool at runtime ===\n");
    $toolStore->addTool(
        new Tool(name: 'echo', inputSchema: ['type' => 'object'], description: 'Echoes its input.'),
        new ClosureToolExecutor(static fn(?array $args, ServerContext $context): CallToolResult => new CallToolResult(content: [
            new TextContent(text: 'echo'),
        ])),
    );

    // Announcements coalesce per event-loop tick, so give the loop one between mutations.
    delay(0.1);

    fwrite(\STDOUT, "\n=== the server publishes a resource-contents change ===\n");
    $subscriptions->emitResourceUpdated('config://demo');

    delay(0.1);

    $stream->close();
} finally {
    $client->disconnect();
}

$serverRun->await();
