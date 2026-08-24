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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Amp\delay;

if ('cli' === \PHP_SAPI) {
    fwrite(\STDERR, "This is a front controller for a web SAPI. Run it as:\n  php -S 127.0.0.1:8932 examples/http-server-sapi.php\n");

    exit(1);
}

$logger = new PsrLogger();
$psr17 = new Psr17Factory();

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

$server = (new ServerBuilder())
    ->setLogger($logger)
    ->setServerInfo(
        name: 'nexus-http-sapi-example',
        version: '0.1.0',
        description: 'A Nexus MCP SDK server mounted on a traditional SAPI front controller.',
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

$server->listen($transport);

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: ['http://localhost:6274'],
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: ['127.0.0.1:8932', 'localhost:8932'],
    toolStore: $tools,
    logger: $logger,
);

emitResponse($endpoint->handle(readServerRequest($psr17)));
$transport->close();

function readServerRequest(Psr17Factory $psr17): ServerRequestInterface
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $target = $_SERVER['REQUEST_URI'] ?? '/';

    $request = $psr17->createServerRequest(
        is_string($method) ? $method : 'GET',
        is_string($target) ? $target : '/',
        $_SERVER,
    );

    foreach (getallheaders() as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $request = $request->withHeader($name, $value);
        }
    }

    return $request->withBody($psr17->createStream((string) file_get_contents('php://input')));
}

function emitResponse(ResponseInterface $response): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()),
        true,
        $response->getStatusCode(),
    );

    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header(sprintf('%s: %s', $name, $value), false);
        }
    }

    $body = $response->getBody();
    $frame = $body->read(8_192);

    while ('' !== $frame) {
        echo $frame;
        flush();

        $frame = $body->read(8_192);
    }
}
