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

require __DIR__.'/../bootstrap.php';
require __DIR__.'/../PsrHttpAdapter.php';
require __DIR__.'/../ProductionPosture.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Extension\Apps\Schema\Enum\ToolVisibility;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;
use Nexus\Mcp\Extension\Apps\Server\AppsServerExtension;
use Nexus\Mcp\Extension\Apps\Server\UiResource;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

use function Amp\trapSignal;

ProductionPosture::force('MCP_APPS_EXAMPLE');

const APPS_SERVER_ADDRESS = '127.0.0.1:8941';

$logger = new PsrLogger();
$psr17 = new Psr17Factory();
$bootedAt = time();
$statusCalls = 0;

$statusPanel = new UiResource(
    name: 'status-panel',
    uri: 'ui://apps-example/status-panel',
    title: 'Server status panel',
    description: 'Renders the system_status metrics as an interactive view.',
    uiMeta: new UiResourceMeta(prefersBorder: true),
);

$server = (new ServerBuilder())
    ->setLogger($logger)
    ->setServerInfo(
        name: 'nexus-apps-example',
        version: '0.1.0',
        description: 'A Nexus MCP SDK server demonstrating the MCP Apps extension.',
    )
    ->enableExtension(new AppsServerExtension())
    ->addTool(
        new Tool(
            name: 'system_status',
            inputSchema: ['type' => 'object'],
            description: 'Reports live process metrics. Linked to the status-panel view, with a text fallback for hosts without UI support.',
            meta: new PayloadMetaObject(extras: [
                Apps::META_KEY => (new UiToolMeta(
                    resourceUri: $statusPanel->resource->uri,
                    visibility: [ToolVisibility::Model, ToolVisibility::App],
                ))->toArray(),
            ]),
        ),
        static function (?array $args, ServerContext $context) use ($bootedAt, &$statusCalls): CallToolResult {
            ++$statusCalls;
            $metrics = [
                'phpVersion' => \PHP_VERSION,
                'uptimeSeconds' => time() - $bootedAt,
                'memoryBytes' => memory_get_usage(true),
                'statusCalls' => $statusCalls,
                'generatedAt' => date(\DATE_ATOM),
            ];

            return new CallToolResult(
                content: [new TextContent(text: sprintf(
                    'PHP %s, up %ds, %d bytes in use, %d status calls.',
                    $metrics['phpVersion'],
                    $metrics['uptimeSeconds'],
                    $metrics['memoryBytes'],
                    $metrics['statusCalls'],
                ))],
                structuredContent: $metrics,
            );
        },
    )
    ->addResource(
        $statusPanel->resource,
        static function (string $uri, ServerContext $context) use ($statusPanel): ReadResourceResult {
            $html = file_get_contents(__DIR__.'/dashboard.html');

            if (false === $html) {
                throw new RuntimeException('Could not read dashboard.html.');
            }

            return new ReadResourceResult(
                contents: [new TextResourceContents(
                    uri: $uri,
                    text: $html,
                    mimeType: Apps::MIME_TYPE,
                    meta: $statusPanel->resource->meta,
                )],
                ttlMs: 0,
                cacheScope: CacheScope::Private,
            );
        },
    )
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
    allowedOrigins: ['http://127.0.0.1:8942'],
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: [APPS_SERVER_ADDRESS, 'localhost:8941'],
    maxBodyBytes: 1_048_576,
    logger: $logger,
);

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(APPS_SERVER_ADDRESS);
$httpServer->start(new PsrHttpAdapter($endpoint, $psr17, $psr17), new DefaultErrorHandler());

fwrite(\STDOUT, sprintf("Apps example MCP server listening on http://%s (Ctrl-C to stop)\n", APPS_SERVER_ADDRESS));

if (defined('SIGINT')) {
    trapSignal([\SIGINT, \SIGTERM]);
} else {
    (new DeferredFuture())->getFuture()->await();
}

$httpServer->stop();
$transport->close();
