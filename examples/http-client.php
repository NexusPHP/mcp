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
 * An MCP client on the Streamable HTTP transport, driving the `http-server.php`
 * example over the network.
 *
 * The typed `Client` surface is the same one `stdio-client.php` uses. What the
 * transport changes is underneath: every message is its own POST, and a call
 * that reports progress comes back as an SSE stream parsed frame by frame, so
 * the notifications arrive while the call is still running.
 *
 * `whoami` shows the one client behaviour unique to HTTP. Its `tenant` argument
 * declares `x-mcp-header`, so `listTools()` records the binding and `callTool()`
 * mirrors the argument into an `Mcp-Param-Tenant` header alongside the body.
 *
 * Start `php examples/http-server.php` first, then run with:
 *
 *     php examples/http-client.php [endpoint]
 */

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;

$endpoint = $argv[1] ?? 'http://127.0.0.1:8931/mcp';

requireReachable($endpoint);

$client = new ClientBuilder()
    ->setLogger(new ExampleLogger())
    ->setClientInfo(name: 'nexus-http-example-client', version: '0.1.0')
    ->build()
;

$transport = new StreamableHttpClientTransport(
    endpoint: $endpoint,
    logger: new ExampleLogger(),
    // Must exceed the server's SSE keep-alive interval (10s in `http-server.php`),
    // or a quiet stream is abandoned between keep-alive frames.
    readTimeout: 30.0,
);

$client->connect($transport);

try {
    $discoverResult = $client->discover();

    fwrite(\STDOUT, "=== Discovery ===\n");
    fwrite(\STDOUT, sprintf(
        "Connected to %s v%s over %s\n\n",
        $discoverResult->meta->serverInfo?->name ?? '(anonymous)',
        $discoverResult->meta->serverInfo?->version ?? '?',
        $endpoint,
    ));

    // `listTools()` also records each tool's `x-mcp-header` bindings, so it must
    // run before a tool that declares one is called.
    fwrite(\STDOUT, "=== tools/list ===\n");

    foreach ($client->listTools()->tools as $tool) {
        fwrite(\STDOUT, sprintf("- %s: %s\n", $tool->name, $tool->description ?? '(no description)'));
    }

    fwrite(\STDOUT, "\n=== tools/call multi_greet: an SSE stream, progress arrives mid-call ===\n");
    $greeting = $client->callTool(
        name: 'multi_greet',
        arguments: ['name' => 'Paul'],
        onProgress: static function (float $progress, ?float $total, ?string $message): void {
            $totalText = null !== $total ? sprintf('/%g', $total) : '';
            $messageText = null !== $message ? ' '.$message : '';

            fwrite(\STDOUT, sprintf("    [progress] %g%s%s\n", $progress, $totalText, $messageText));
        },
    );
    fwrite(\STDOUT, sprintf("    result: %s\n\n", renderText($greeting)));

    fwrite(\STDOUT, "=== tools/call whoami: the tenant argument is mirrored into Mcp-Param-Tenant ===\n");
    $identity = $client->callTool(name: 'whoami', arguments: ['tenant' => 'acme']);
    fwrite(\STDOUT, sprintf("    result: %s\n", renderText($identity)));
} finally {
    $client->disconnect();
}

function renderText(CallToolResult $result): string
{
    foreach ($result->content as $block) {
        if ($block instanceof TextContent) {
            return $block->text;
        }
    }

    return '(no text content)';
}

/**
 * Fails fast when nothing is listening.
 *
 * The transport reports an unreachable endpoint on its own, but only once the
 * connect attempts run out. Probing first turns that wait into an immediate
 * answer that names the thing to start.
 */
function requireReachable(string $endpoint): void
{
    $host = parse_url($endpoint, \PHP_URL_HOST);
    $port = parse_url($endpoint, \PHP_URL_PORT) ?? ('https' === parse_url($endpoint, \PHP_URL_SCHEME) ? 443 : 80);

    if (! is_string($host)) {
        fwrite(\STDERR, sprintf("Not a usable endpoint URL: %s\n", $endpoint));

        exit(1);
    }

    $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), timeout: 2.0);

    if (false === $socket) {
        fwrite(\STDERR, sprintf("Nothing is listening on %s:%d. Start `php examples/http-server.php` first.\n", $host, $port));

        exit(1);
    }

    fclose($socket);
}
