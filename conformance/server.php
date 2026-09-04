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
 * Serves the conformance fixtures over Streamable HTTP on every path, bound by `HOST` and `PORT`.
 *
 * Prefer `./conformance/run-server.sh`, which also boots the referee. To run it alone:
 *
 *     PORT=3000 php conformance/server.php
 */

require __DIR__.'/bootstrap.php';
require __DIR__.'/../examples/ProductionPosture.php';
require __DIR__.'/ElicitationHelpers.php';
require __DIR__.'/EverythingServer.php';
require __DIR__.'/MultiRoundServer.php';
require __DIR__.'/TasksServer.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Nexus\Mcp\Extension\Tasks\Server\TasksServerExtension;
use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use Nexus\Mcp\Server\Prompt\MutablePromptStoreInterface;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Server\Tool\MutableToolStoreInterface;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

use function Amp\trapSignal;

ProductionPosture::force('MCP_CONFORMANCE');

function conformanceEnv(string $name, string $fallback): string
{
    $value = getenv($name);

    return is_string($value) && '' !== $value ? $value : $fallback;
}

$host = conformanceEnv('HOST', '127.0.0.1');
$port = (int) conformanceEnv('PORT', '3000');
$address = sprintf('%s:%d', $host, $port);

$logger = new PsrLogger();
$psr17 = new Psr17Factory();

$everythingServer = new EverythingServer();
$builder = (new ServerBuilder())
    ->setLogger($logger)
    ->register($everythingServer, new MultiRoundServer(), new TasksServer())
    ->enableExtension(new TasksServerExtension(
        toolPolicies: [
            'slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional),
            'failing_job' => new ToolTaskPolicy(support: TaskSupport::Required),
            'protocol_error_job' => new ToolTaskPolicy(support: TaskSupport::Optional),
            'confirm_delete' => new ToolTaskPolicy(support: TaskSupport::Optional),
            'multi_input' => new ToolTaskPolicy(support: TaskSupport::Optional),
            'test_tool_with_task' => new ToolTaskPolicy(support: TaskSupport::Required, resolvesInputFirst: true),
        ],
        logger: $logger,
    ))
;

$subscriptions = new SubscriptionStore(
    toolsListChanged: true,
    promptsListChanged: true,
    resourcesListChanged: true,
    resourceSubscriptions: true,
);

$builder->setSubscriptionStore($subscriptions);

$server = $builder->build();

$toolStore = $builder->getToolStore();
$promptStore = $builder->getPromptStore();

if ($toolStore instanceof MutableToolStoreInterface && $promptStore instanceof MutablePromptStoreInterface) {
    $everythingServer->useStores($toolStore, $promptStore);
}

$transport = new StreamableHttpServerTransport(
    responseFactory: $psr17,
    streamFactory: $psr17,
    logger: $logger,
    keepAliveInterval: 10.0,
);

// `listen()` rather than `run()`: the host drives the transport per HTTP request, so attaching must not block.
$server->listen($transport);

$authorities = [$address, sprintf('localhost:%d', $port)];
$origins = [];

foreach ($authorities as $authority) {
    $origins[] = sprintf('http://%s', $authority);
}

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: $origins,
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: $authorities,
    maxBodyBytes: 1_048_576,
    toolStore: $builder->getToolStore(),
    logger: $logger,
);

// One connection per response on macOS only, where a reused idle loopback connection can stall for 10+ seconds.
$handler = new PsrHttpAdapter($endpoint, $psr17, $psr17);

if (PHP_OS_FAMILY === 'Darwin') {
    $handler = new class ($handler) implements RequestHandler {
        public function __construct(private readonly RequestHandler $inner)
        {
        }

        #[Override]
        public function handleRequest(Request $request): Response
        {
            $response = $this->inner->handleRequest($request);
            $response->setHeader('connection', 'close');

            return $response;
        }
    };
}

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose($address);
$httpServer->start($handler, new DefaultErrorHandler());

fwrite(\STDERR, sprintf("Conformance server listening on http://%s\n", $address));

// With ext-pcntl loaded, Revolt consumes untrapped signals, so an unhandled Ctrl-C would squat the port.
if (defined('SIGINT')) {
    trapSignal([\SIGINT, \SIGTERM]);
} else {
    (new DeferredFuture())->getFuture()->await();
}

// Closing the transport first ends every open stream, since the HTTP server's stop() waits for them.
$transport->close();
$httpServer->stop();
