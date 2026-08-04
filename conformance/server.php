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
require __DIR__.'/EverythingServer.php';
require __DIR__.'/MultiRoundServer.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Composer\XdebugHandler\XdebugHandler;
use Nexus\Mcp\Server\Prompt\MutablePromptStoreInterface;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Server\Tool\MutableToolStoreInterface;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

use function Amp\trapSignal;

// xdebug's mode is fixed at process start, so forcing it off takes one restart. The handler
// re-runs this script with the extension dropped from the loaded ini, which leaves the
// restarted listener as a child process the run scripts tear down by process group.
$xdebugHandler = new XdebugHandler('MCP_CONFORMANCE');
$xdebugHandler->check();
unset($xdebugHandler);

// `-1` is only reachable at startup, so runtime lowering stops at "compiled but not executed".
if (ini_get('zend.assertions') !== '-1') {
    ini_set('zend.assertions', '0');
}

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
    ->register($everythingServer, new MultiRoundServer())
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

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose($address);
$httpServer->start(new PsrHttpAdapter($endpoint, $psr17, $psr17), new DefaultErrorHandler());

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
