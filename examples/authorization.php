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
require __DIR__.'/PsrHttpAdapter.php';

use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\Http\Middleware\BearerAuthenticationMiddleware;
use Nexus\Mcp\Server\Transport\Http\ProtectedResourceMetadataHandler;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

const ADDRESS = '127.0.0.1:8974';
const ISSUER = 'http://127.0.0.1:8974';
const RESOURCE = 'http://127.0.0.1:8974/mcp';
const METADATA_URL = 'http://127.0.0.1:8974/.well-known/oauth-protected-resource/mcp';
const REQUIRED_SCOPE = 'mcp:use';

/**
 * An in-process OAuth 2.1 authorization server that registers any client and consents to any user,
 * so the SDK's real client flow can run with no external identity provider.
 */
final class StubAuthorizationServer implements AccessTokenValidatorInterface, RequestHandlerInterface
{
    /**
     * @var array<string, array{challenge: non-empty-string, clientId: non-empty-string, scope: string}>
     */
    private array $codes = [];

    /**
     * @var array<string, VerifiedAccessToken>
     */
    private array $tokens = [];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/.well-known/oauth-authorization-server' => $this->json([
                'issuer' => ISSUER,
                'authorization_endpoint' => ISSUER.'/authorize',
                'token_endpoint' => ISSUER.'/token',
                'registration_endpoint' => ISSUER.'/register',
                'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
                'authorization_response_iss_parameter_supported' => true,
                'scopes_supported' => [REQUIRED_SCOPE],
            ]),
            '/register' => $this->register(),
            '/authorize' => $this->authorize($request),
            '/token' => $this->token($request),
            default => $this->json(['error' => 'not_found'], 404),
        };
    }

    #[Override]
    public function validate(string $token): ?VerifiedAccessToken
    {
        return $this->tokens[$token] ?? null;
    }

    private function register(): ResponseInterface
    {
        return $this->json([
            'client_id' => 'stub-client-'.bin2hex(random_bytes(4)),
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    private function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $clientId = $this->text($query, 'client_id');
        $redirectUri = $this->text($query, 'redirect_uri');
        $challenge = $this->text($query, 'code_challenge');

        if ('' === $clientId || '' === $redirectUri || '' === $challenge
            || $this->text($query, 'code_challenge_method') !== 'S256') {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        $scope = $this->text($query, 'scope');
        $code = bin2hex(random_bytes(16));
        $this->codes[$code] = [
            'challenge' => $challenge,
            'clientId' => $clientId,
            'scope' => '' === $scope ? REQUIRED_SCOPE : $scope,
        ];

        $location = sprintf(
            '%s?%s',
            $redirectUri,
            http_build_query(['code' => $code, 'state' => $this->text($query, 'state'), 'iss' => ISSUER]),
        );

        return $this->responseFactory->createResponse(302)->withHeader('Location', $location);
    }

    private function token(ServerRequestInterface $request): ResponseInterface
    {
        parse_str((string) $request->getBody(), $form);
        $code = $this->text($form, 'code');
        $grant = $this->codes[$code] ?? null;
        $verifier = $this->text($form, 'code_verifier');
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (null === $grant
            || $this->text($form, 'grant_type') !== 'authorization_code'
            || $grant['challenge'] !== $expectedChallenge) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }

        unset($this->codes[$code]);

        $scopes = [];

        foreach (explode(' ', $grant['scope']) as $scope) {
            if ('' !== $scope) {
                $scopes[] = $scope;
            }
        }

        $token = bin2hex(random_bytes(16));
        $this->tokens[$token] = new VerifiedAccessToken(
            audience: [RESOURCE],
            scopes: $scopes,
            subject: 'demo-user',
            clientId: $grant['clientId'],
            expiresAt: time() + 3_600,
        );

        return $this->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3_600,
            'scope' => $grant['scope'],
        ]);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function text(array $params, string $name): string
    {
        $value = $params[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($payload, \JSON_THROW_ON_ERROR)))
        ;
    }
}

/**
 * A user-agent with no browser: it visits the authorization URL, the stub consents on sight, and the
 * redirect it answers with is the callback.
 */
final readonly class HeadlessUserAgent implements UserAuthorizationInterface
{
    #[Override]
    public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
    {
        fwrite(\STDOUT, "  the user-agent visits the authorization URL, and the stub consents\n");

        $response = (new HttpClientBuilder())->followRedirects(0)->build()
            ->request(new Request($redirect->url), $cancellation)
        ;

        if ($response->getStatus() !== 302) {
            throw new RuntimeException(sprintf('The stub answered the authorization request with %d.', $response->getStatus()));
        }

        return new AuthorizationCallback($response->getHeader('location') ?? '');
    }
}

$logger = new PsrLogger();
$psr17 = new Psr17Factory();
$stub = new StubAuthorizationServer($psr17, $psr17);

$server = (new ServerBuilder())
    ->setLogger($logger)
    ->setServerInfo(name: 'nexus-authorization-example', version: '0.1.0')
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

$transport = new StreamableHttpServerTransport(responseFactory: $psr17, streamFactory: $psr17, logger: $logger);
$server->listen($transport);

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: ['http://'.ADDRESS],
    responseFactory: $psr17,
    streamFactory: $psr17,
    logger: $logger,
    authentication: new BearerAuthenticationMiddleware($stub, RESOURCE, METADATA_URL, $psr17, requiredScopes: [REQUIRED_SCOPE]),
);

$metadata = new ProtectedResourceMetadataHandler(
    RESOURCE,
    [ISSUER],
    $psr17,
    $psr17,
    scopesSupported: [REQUIRED_SCOPE],
    resourceName: 'Nexus authorization example server',
);

$router = new readonly class ($endpoint, $metadata, $stub) implements RequestHandlerInterface {
    public function __construct(
        private RequestHandlerInterface $endpoint,
        private RequestHandlerInterface $metadata,
        private RequestHandlerInterface $stub,
    ) {
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_starts_with($path, '/.well-known/oauth-protected-resource')) {
            return $this->metadata->handle($request);
        }

        if ('/mcp' === $path) {
            return $this->endpoint->handle($request);
        }

        return $this->stub->handle($request);
    }
};

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(ADDRESS);
$httpServer->start(new PsrHttpAdapter($router, $psr17, $psr17), new DefaultErrorHandler());

fwrite(\STDOUT, sprintf("Stub authorization server and protected MCP endpoint on %s\n\n", ISSUER));
fwrite(\STDOUT, "=== the client starts tokenless and walks the whole flow ===\n");

$http = new AuthorizedHttpClient(
    RESOURCE,
    new AuthorizationOptions(
        clientName: 'Nexus authorization example client',
        redirectUri: 'http://127.0.0.1:8765/callback',
        allowInsecureLoopback: true,
    ),
    new HeadlessUserAgent(),
    new HttpClientBuilder(),
    logger: $logger,
);

$client = (new ClientBuilder())
    ->setLogger($logger)
    ->setClientInfo(name: 'nexus-authorization-example-client', version: '0.1.0')
    ->build()
;

$client->connect(new StreamableHttpClientTransport(endpoint: RESOURCE, client: $http, logger: $logger));

try {
    $client->discover();

    $identity = $client->callTool(name: 'whoami');

    if ($identity instanceof CallToolResult) {
        foreach ($identity->content as $block) {
            if ($block instanceof TextContent) {
                fwrite(\STDOUT, sprintf("    result: %s\n", $block->text));
            }
        }
    }
} finally {
    $client->disconnect();
}

$httpServer->stop();
$transport->close();
