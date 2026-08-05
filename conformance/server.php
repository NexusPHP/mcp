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
 * Serves `EverythingServer` and `MultiRoundServer` over Streamable HTTP for the
 * conformance referee.
 *
 * The endpoint answers on every path, so the referee's `--url` may end in
 * `/mcp` or anything else. Bind address comes from `HOST` and `PORT`.
 *
 * Prefer `./conformance/run-server.sh`, which also boots the referee and tears
 * this process down. To run it alone:
 *
 *     PORT=3000 php conformance/server.php
 *
 * Serves with xdebug off and assertions not executing, restarting itself once
 * through `composer/xdebug-handler` when the invoking PHP has xdebug active.
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

// The restarted listener is a child process the run scripts tear down by process group.
ProductionPosture::force('MCP_CONFORMANCE');

/**
 * Reads an environment variable, falling back when it is unset or empty.
 */
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
$builder = new ServerBuilder()
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

// `listen()` rather than `run()`: the transport is driven per HTTP request by the
// host, so attaching the dispatcher must not block.
$server->listen($transport);

// Both spellings of the loopback authority. The runner reaches the endpoint by whichever
// one its URL carries, and the DNS-rebinding scenario sends a matching Origin, so the two
// lists have to admit the same set or the accepted-Origin check fails on one spelling only.
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

// One connection per response on macOS only: a kept-alive loopback connection the
// referee reuses after an idle gap can stall into TCP retransmission for 10+ seconds
// there, tripping the referee's request timeout on checks that pause between requests.
// Other platforms keep connection reuse so the suite exercises it.
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

// With ext-pcntl loaded, Revolt's loop consumes a signal no callback is registered for, so
// an untrapped Ctrl-C leaves the fixture running and squatting the port.
if (defined('SIGINT')) {
    trapSignal([\SIGINT, \SIGTERM]);
} else {
    // ext-pcntl absent, so there is no signal to trap. Ctrl-C ends the process.
    new DeferredFuture()->getFuture()->await();
}

$httpServer->stop();
