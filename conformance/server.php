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
 */

require __DIR__.'/bootstrap.php';
require __DIR__.'/EverythingServer.php';
require __DIR__.'/MultiRoundServer.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Completion\CompletionStore;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

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

$logger = new ExampleLogger();
$psr17 = new Psr17Factory();

/*
 * Completions have no discovery attribute, so this is the one capability the
 * fixture registers the explicit way. Both prompt arguments complete from the
 * same canned list the referee expects to see filtered.
 */
$completeArgument = static function (string $value, ?array $arguments, ServerContext $context): CompleteResult {
    $candidates = ['alpha', 'beta', 'gamma', 'delta'];
    $matches = [];

    foreach ($candidates as $candidate) {
        if ('' === $value || str_starts_with($candidate, $value)) {
            $matches[] = $candidate;
        }
    }

    return new CompleteResult(completion: ['values' => $matches, 'total' => count($matches), 'hasMore' => false]);
};

$builder = new ServerBuilder()
    ->setLogger($logger)
    ->register(new EverythingServer(), new MultiRoundServer())
    ->setCompletionStore(new CompletionStore(
        promptCompletions: [
            'test_prompt_with_arguments' => ['arg1' => $completeArgument, 'arg2' => $completeArgument],
        ],
        templateCompletions: [
            'test://template/{id}/data' => ['id' => $completeArgument],
        ],
    ))
;

$server = $builder->build();

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
    // The referee drives the server from its own process rather than a browser, so
    // it sends no Origin. The DNS-rebinding scenario asserts that a *mismatched*
    // Origin is refused, which this list still does.
    allowedOrigins: [sprintf('http://%s', $address)],
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: [$address, sprintf('localhost:%d', $port)],
    maxBodyBytes: 1_048_576,
    toolStore: $builder->getToolStore(),
    logger: $logger,
);

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose($address);
$httpServer->start(new PsrHttpAdapter($endpoint, $psr17, $psr17), new DefaultErrorHandler());

fwrite(\STDERR, sprintf("Conformance server listening on http://%s\n", $address));

// The runner script owns this process's lifetime and terminates it with a signal,
// so there is nothing to wait on but the loop itself.
new DeferredFuture()->getFuture()->await();
