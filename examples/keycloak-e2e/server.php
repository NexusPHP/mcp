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
 * An MCP server protected by the Keycloak realm `compose.yaml` stands up.
 *
 * Every request must present a bearer token minted by the realm, validated by
 * `JwksAccessTokenValidator` against the realm's JWKS, bound to this server's
 * canonical URI by `BearerAuthenticationMiddleware`, and scoped `mcp:use`.
 * `ProtectedResourceMetadataHandler` publishes the metadata document clients
 * walk to find the realm on their own.
 *
 * Run with:
 *
 *     docker compose -f examples/keycloak-e2e/compose.yaml up --wait
 *     php examples/keycloak-e2e/server.php
 *
 * Then drive it from a second terminal:
 *
 *     php examples/keycloak-e2e/client.php
 */

require __DIR__.'/../bootstrap.php';
require __DIR__.'/../PsrHttpAdapter.php';

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Firebase\JWT\JWK;
use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Auth\JwksAccessTokenValidator;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\Http\Middleware\BearerAuthenticationMiddleware;
use Nexus\Mcp\Server\Transport\Http\ProtectedResourceMetadataHandler;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Amp\trapSignal;

const ADDRESS = '127.0.0.1:8973';
const RESOURCE = 'http://127.0.0.1:8973/mcp';
const METADATA_URL = 'http://127.0.0.1:8973/.well-known/oauth-protected-resource/mcp';
const ISSUER = 'http://localhost:8080/realms/mcp';
const REQUIRED_SCOPE = 'mcp:use';

$logger = new PsrLogger();
$psr17 = new Psr17Factory();

/*
 * One startup fetch of the realm's signing keys. A production server refreshes
 * them instead (`Firebase\JWT\CachedKeySet` with any PSR-18 client and PSR-6
 * cache), which survives Keycloak rotating a key while the server runs.
 */
$jwksUrl = ISSUER.'/protocol/openid-connect/certs';
$jwksJson = @file_get_contents($jwksUrl);

if (false === $jwksJson) {
    fwrite(\STDERR, sprintf(
        "Could not fetch the realm JWKS from %s.\nStart Keycloak first: docker compose -f %s/compose.yaml up --wait\n",
        $jwksUrl,
        __DIR__,
    ));

    exit(1);
}

$jwks = json_decode($jwksJson, true, flags: \JSON_THROW_ON_ERROR);
Assert::that($jwks)->isArray('The JWKS document must decode to an array, {type} given.');

$keys = JWK::parseKeySet($jwks);

$server = (new ServerBuilder())
    ->setLogger($logger)
    ->setServerInfo(
        name: 'nexus-keycloak-example',
        version: '0.1.0',
        description: 'A Nexus MCP SDK server protected by Keycloak.',
    )
    ->addTool(
        new Tool(
            name: 'whoami',
            inputSchema: ['type' => 'object'],
            description: 'Reports the authenticated subject, client, and scopes of the calling token.',
        ),
        static function (?array $args, ServerContext $context): CallToolResult {
            $token = $context->receiveContext->authInfo;

            $text = null === $token ? 'Unauthenticated.' : sprintf(
                'Subject %s, authorized for client %s, with scopes [%s].',
                $token->subject ?? '(none)',
                $token->clientId ?? '(none)',
                implode(', ', $token->scopes),
            );

            return new CallToolResult(content: [new TextContent(text: $text)]);
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

// `listen()` rather than `run()`: the transport is driven per HTTP request by the
// host, so attaching the dispatcher must not block.
$server->listen($transport);

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: ['http://'.ADDRESS, 'http://localhost:8973'],
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: [ADDRESS, 'localhost:8973'],
    maxBodyBytes: 1_048_576,
    logger: $logger,
    authentication: new BearerAuthenticationMiddleware(
        new JwksAccessTokenValidator($keys, ISSUER),
        RESOURCE,
        METADATA_URL,
        $psr17,
        requiredScopes: [REQUIRED_SCOPE],
    ),
);

$metadata = new ProtectedResourceMetadataHandler(
    RESOURCE,
    [ISSUER],
    $psr17,
    $psr17,
    scopesSupported: [REQUIRED_SCOPE],
    resourceName: 'Nexus Keycloak example server',
);

/*
 * The metadata document lives beside the endpoint, not behind it: RFC 9728
 * lookups are unauthenticated GETs, so they must not pass through the bearer
 * middleware. The handler itself answers 404 off its two well-known paths.
 */
$router = new readonly class ($endpoint, $metadata) implements RequestHandlerInterface {
    public function __construct(private RequestHandlerInterface $endpoint, private RequestHandlerInterface $metadata)
    {
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = str_starts_with($request->getUri()->getPath(), '/.well-known/oauth-protected-resource')
            ? $this->metadata
            : $this->endpoint;

        return $handler->handle($request);
    }
};

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(ADDRESS);
$httpServer->start(new PsrHttpAdapter($router, $psr17, $psr17), new DefaultErrorHandler());

fwrite(\STDOUT, sprintf("Protected MCP endpoint listening on %s (Ctrl-C to stop)\n", RESOURCE));

if (defined('SIGINT')) {
    trapSignal([\SIGINT, \SIGTERM]);
} else {
    // ext-pcntl absent, so there is no signal to trap. Ctrl-C ends the process.
    (new DeferredFuture())->getFuture()->await();
}

$httpServer->stop();
$transport->close();
