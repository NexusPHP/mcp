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
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;

$argument = $argv[1] ?? '';
$endpoint = '' !== $argument ? $argument : 'http://127.0.0.1:8931/mcp';

requireReachable($endpoint);

$client = (new ClientBuilder())
    ->setLogger(new PsrLogger())
    ->setClientInfo(name: 'nexus-http-example-client', version: '0.1.0')
    ->build()
;

$transport = new StreamableHttpClientTransport(
    endpoint: $endpoint,
    logger: new PsrLogger(),
    readTimeout: 30.0,
);

$client->connect($transport);

try {
    $discoverResult = $client->discover();

    fwrite(\STDOUT, "=== Discovery ===\n");
    fwrite(\STDOUT, sprintf(
        "Connected to %s v%s over %s\n\n",
        $discoverResult->meta->serverInfo->name ?? '(anonymous)',
        $discoverResult->meta->serverInfo->version ?? '?',
        $endpoint,
    ));

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

function renderText(CallToolResult|InputRequiredResult $result): string
{
    if ($result instanceof InputRequiredResult) {
        return '(the server asked for input first)';
    }

    foreach ($result->content as $block) {
        if ($block instanceof TextContent) {
            return $block->text;
        }
    }

    return '(no text content)';
}

function requireReachable(string $endpoint): void
{
    $host = parse_url($endpoint, \PHP_URL_HOST);
    $port = parse_url($endpoint, \PHP_URL_PORT);
    $port = is_int($port) ? $port : (parse_url($endpoint, \PHP_URL_SCHEME) === 'https' ? 443 : 80);

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
