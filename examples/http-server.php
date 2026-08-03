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

/**
 * An MCP server on the Streamable HTTP transport, bound to a socket by
 * `amphp/http-server`.
 *
 * The SDK ships no HTTP server: `StreamableHttpServerTransport` is a PSR-15
 * handler, `SecuredHttpEndpoint` wraps it in the recommended security
 * middleware, and the host of your choice serves the result. Here that host is
 * `amphp/http-server`, bridged by `PsrHttpAdapter`. There is no router, so the
 * MCP endpoint answers on every path.
 *
 * Two tools show what the transport adds over stdio: `multi_greet` reports
 * progress mid-call, so its response is an SSE stream rather than a single JSON
 * object, and `whoami` declares `x-mcp-header` on an argument, so clients
 * mirror that argument into an `Mcp-Param-Tenant` header the endpoint
 * validates against the body.
 *
 * Run with:
 *
 *     php examples/http-server.php
 *
 * Then drive it from a second terminal:
 *
 *     php examples/http-client.php
 */

require __DIR__.'/bootstrap.php';
require __DIR__.'/PsrHttpAdapter.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

use function Amp\delay;
use function Amp\trapSignal;

const ADDRESS = '127.0.0.1:8931';

$logger = new PsrLogger();
$psr17 = new Psr17Factory();

/*
 * The endpoint validates `Mcp-Param-{Name}` headers against the call body, so it
 * needs the same tool store the server serves. Building the store up front and
 * handing it to both is what `setToolStore()` is for.
 */
$tools = new ToolStore([
    'multi_greet' => new ToolEntry(
        new Tool(
            name: 'multi_greet',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Person to greet.'],
                ],
                'required' => ['name'],
            ],
            description: 'Greets the named person, reporting progress as it works.',
        ),
        new ClosureToolExecutor(static function (?array $args, ServerContext $context): CallToolResult {
            $name = is_string($args['name'] ?? null) ? $args['name'] : 'stranger';

            foreach (['Preparing the greeting...', 'Composing the message...', 'Ready to greet.'] as $step => $message) {
                $context->reportProgress(progress: (float) $step, total: 2.0, message: $message);
                delay(0.4);
            }

            return new CallToolResult(content: [new TextContent(text: sprintf('Hello, %s!', $name))]);
        }),
    ),
    'whoami' => new ToolEntry(
        new Tool(
            name: 'whoami',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'tenant' => [
                        'type' => 'string',
                        'description' => 'Tenant the call belongs to.',
                        // Clients on the Streamable HTTP transport MUST mirror this
                        // argument into an `Mcp-Param-Tenant` header, so a gateway can
                        // route or rate-limit on it without parsing the body.
                        'x-mcp-header' => 'Tenant',
                    ],
                ],
                'required' => ['tenant'],
            ],
            description: 'Echoes the calling tenant, which clients also mirror into an Mcp-Param-Tenant header.',
        ),
        new ClosureToolExecutor(static function (?array $args, ServerContext $context): CallToolResult {
            $tenant = is_string($args['tenant'] ?? null) ? $args['tenant'] : 'unknown';

            return new CallToolResult(content: [new TextContent(text: sprintf('You are calling as "%s".', $tenant))]);
        }),
    ),
]);

$server = new ServerBuilder()
    ->setLogger($logger)
    ->setServerInfo(
        name: 'nexus-http-example',
        version: '0.1.0',
        description: 'A Nexus MCP SDK server demonstrating the Streamable HTTP transport.',
    )
    ->setToolStore($tools)
    ->build()
;

$transport = new StreamableHttpServerTransport(
    responseFactory: $psr17,
    streamFactory: $psr17,
    logger: $logger,
    keepAliveInterval: 10.0,
);

// `listen()` rather than `run()`: the transport is driven per HTTP request by the
// host, so attaching the dispatcher must not block.
$server->listen($transport);

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: ['http://localhost:6274'], // MCP Inspector's own origin.
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: [ADDRESS, 'localhost:8931'],
    maxBodyBytes: 1_048_576,
    toolStore: $tools,
    logger: $logger,
);

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(ADDRESS);
$httpServer->start(new PsrHttpAdapter($endpoint, $psr17, $psr17), new DefaultErrorHandler());

fwrite(\STDOUT, sprintf("MCP endpoint listening on http://%s (Ctrl-C to stop)\n", ADDRESS));

if (defined('SIGINT')) {
    trapSignal([\SIGINT, \SIGTERM]);
} else {
    // ext-pcntl absent, so there is no signal to trap. Ctrl-C ends the process.
    new DeferredFuture()->getFuture()->await();
}

$httpServer->stop();
$transport->close();
