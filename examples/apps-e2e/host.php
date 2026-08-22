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
require __DIR__.'/../ProductionPosture.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Nexus\Assert\Assert;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Extension\Apps\Client\AppClient;
use Nexus\Mcp\Extension\Apps\Client\AppsClientExtension;

use function Amp\trapSignal;

ProductionPosture::force('MCP_APPS_EXAMPLE');

const APPS_HOST_ADDRESS = '127.0.0.1:8942';
const APPS_MCP_ENDPOINT = 'http://127.0.0.1:8941/mcp';

requireAppsServerReachable(APPS_MCP_ENDPOINT);

$logger = new PsrLogger();

$client = (new ClientBuilder())
    ->setLogger($logger)
    ->setClientInfo(name: 'nexus-apps-example-host', version: '0.1.0')
    ->enableExtension(new AppsClientExtension())
    ->build()
;

$client->connect(new StreamableHttpClientTransport(
    endpoint: APPS_MCP_ENDPOINT,
    logger: $logger,
    readTimeout: 30.0,
));
$client->discover();

$apps = new AppClient($client);

$handler = new ClosureRequestHandler(static function (Request $request) use ($client, $apps): Response {
    $path = $request->getUri()->getPath();

    try {
        if ('/' === $path) {
            $page = file_get_contents(__DIR__.'/host.html');

            if (false === $page) {
                return jsonError(500, 'Could not read host.html.');
            }

            return new Response(200, ['content-type' => 'text/html; charset=utf-8'], $page);
        }

        if ('/api/app-tools' === $path) {
            $appTools = [];

            foreach ($apps->findAppTools($client->listTools()) as $appTool) {
                $appTools[] = [
                    'name' => $appTool->tool->name,
                    'title' => $appTool->tool->title,
                    'description' => $appTool->tool->description,
                    'resourceUri' => $appTool->uiMeta->resourceUri,
                ];
            }

            return jsonResponse(['tools' => $appTools]);
        }

        if ('/api/call' === $path && $request->getMethod() === 'POST') {
            $payload = json_decode($request->getBody()->buffer(), true);

            if (! is_array($payload)) {
                return jsonError(400, 'The request body must be a JSON object.');
            }

            $name = $payload['name'] ?? null;

            if (! is_string($name) || '' === $name) {
                return jsonError(400, 'The "name" field must be a non-empty string.');
            }

            $arguments = $payload['arguments'] ?? null;
            Assert::that($arguments)
                ->nullOr()
                ->isArray('The "arguments" field must be an object or absent, {type} given.')
                ->isMap('The "arguments" field must be a string-keyed object.')
            ;

            return jsonResponse(['result' => $client->callTool($name, $arguments)->toArray()]);
        }

        if ('/api/resource' === $path) {
            parse_str($request->getUri()->getQuery(), $query);
            $uri = $query['uri'] ?? null;

            if (! is_string($uri) || '' === $uri) {
                return jsonError(400, 'The "uri" query parameter must be a non-empty string.');
            }

            $read = $apps->readAppResource($uri);

            if (! $read instanceof ReadResourceResult) {
                return jsonError(502, 'The server asked for input before serving the resource.');
            }

            $contents = $read->contents[0] ?? null;

            if (! $contents instanceof TextResourceContents) {
                return jsonError(502, 'The resource returned no text contents.');
            }

            return jsonResponse([
                'html' => $contents->text,
                'mimeType' => $contents->mimeType,
                'uiMeta' => $apps->resolveResourceMeta($contents)?->toArray(),
            ]);
        }

        return jsonError(404, sprintf('No route for "%s".', $path));
    } catch (InvalidArgumentException $exception) {
        return jsonError(400, $exception->getMessage());
    } catch (Throwable $throwable) {
        return jsonError(500, $throwable->getMessage());
    }
});

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(APPS_HOST_ADDRESS);
$httpServer->start($handler, new DefaultErrorHandler());

fwrite(\STDOUT, sprintf("Apps example host running: open http://%s in a browser (Ctrl-C to stop)\n", APPS_HOST_ADDRESS));

if (defined('SIGINT')) {
    trapSignal([\SIGINT, \SIGTERM]);
} else {
    (new DeferredFuture())->getFuture()->await();
}

$httpServer->stop();
$client->disconnect();

/**
 * @param array<string, mixed> $data
 */
function jsonResponse(array $data, int $status = 200): Response
{
    return new Response(
        $status,
        ['content-type' => 'application/json; charset=utf-8'],
        json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
    );
}

function jsonError(int $status, string $message): Response
{
    return jsonResponse(['error' => $message], $status);
}

function requireAppsServerReachable(string $endpoint): void
{
    $host = parse_url($endpoint, \PHP_URL_HOST);
    $port = parse_url($endpoint, \PHP_URL_PORT);

    if (! is_string($host) || ! is_int($port)) {
        fwrite(\STDERR, sprintf("Not a usable endpoint URL: %s\n", $endpoint));

        exit(1);
    }

    $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), timeout: 2.0);

    if (false === $socket) {
        fwrite(\STDERR, sprintf("Nothing is listening on %s:%d. Start `php examples/apps-e2e/server.php` first.\n", $host, $port));

        exit(1);
    }

    fclose($socket);
}
