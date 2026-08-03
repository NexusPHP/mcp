# Resource server

How the server validates tokens and publishes its metadata.

## Validating tokens

For JWT-minting authorization servers, the SDK ships `JwksAccessTokenValidator`. It rides the suggested
`firebase/php-jwt` package, which stays out of the SDK's own requirements, so install it alongside:

```console
composer require firebase/php-jwt:^7.0
```

```php
use Firebase\JWT\CachedKeySet;
use Nexus\Mcp\Server\Auth\JwksAccessTokenValidator;

$validator = new JwksAccessTokenValidator(new CachedKeySet(
    'https://auth.example.com/.well-known/jwks.json',
    $httpClient,          // any PSR-18 client
    $requestFactory,      // any PSR-17 request factory
    $cache,               // any PSR-6 cache
    300,
));
```

Constructing it without the package installed throws a `MissingSuggestedDependencyException` naming that
install command. The validator checks signature and expiry through the key set and maps the claim
spellings the common providers use: `scope` or `scp` (string or list) for scopes, and `azp`, `client_id`,
or `cid` for the authorizing client. The [provider recipes](../authorization.md#guide) name each
provider's JWKS URL and quirks.

For anything else (opaque tokens, introspection endpoints, provider SDKs), verification stays yours:

```php
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;

final class JwtAccessTokenValidator implements AccessTokenValidatorInterface
{
    public function validate(string $token): ?VerifiedAccessToken
    {
        $claims = $this->verifySignatureAndExpiry($token);

        return null === $claims ? null : new VerifiedAccessToken(
            audience: $claims['aud'],
            scopes: explode(' ', $claims['scope'] ?? ''),
            subject: $claims['sub'] ?? null,
            clientId: $claims['client_id'] ?? null,
        );
    }
}
```

The validator owns signature checking and expiry. Two checks are not its job:
`BearerAuthenticationMiddleware` binds the returned audience to this server, and enforces the scopes the
endpoint requires. A token minted for another resource is refused even if the validator accepts it.

Mount it on the endpoint:

```php
use Nexus\Mcp\Server\Transport\Http\Middleware\BearerAuthenticationMiddleware;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;

$endpoint = new SecuredHttpEndpoint(
    $transport,
    ['https://app.example.com'],
    $responseFactory,
    $streamFactory,
    authentication: new BearerAuthenticationMiddleware(
        new JwtAccessTokenValidator(),
        'https://mcp.example.com/mcp',
        'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
        $responseFactory,
        requiredScopes: ['mcp:use'],
    ),
);
```

Authentication runs after CORS and DNS-rebinding protection and before anything reads the body, so an
unauthorized request is turned away without being parsed.

## Publishing the metadata document

Clients find your authorization server by reading a metadata document. Route
`ProtectedResourceMetadataHandler` at both well-known paths and name the same URL in the middleware above:

```php
use Nexus\Mcp\Server\Transport\Http\ProtectedResourceMetadataHandler;

$metadata = new ProtectedResourceMetadataHandler(
    'https://mcp.example.com/mcp',
    ['https://auth.example.com'],
    $responseFactory,
    $streamFactory,
    scopesSupported: ['mcp:use'],
    resourceName: 'Example MCP Server',
);
```

| Path | Served by |
| --- | --- |
| `/mcp` | `SecuredHttpEndpoint` |
| `/.well-known/oauth-protected-resource/mcp` | `ProtectedResourceMetadataHandler` |
| `/.well-known/oauth-protected-resource` | `ProtectedResourceMetadataHandler` |

The handler serves the document only at those two paths, which RFC 9728 derives from the MCP server's own URL.
Mounting it anywhere else answers `404` rather than publishing the same document under a name no client will
look it up by.

Serving both well-known paths is worth the two lines: a client that never saw a `WWW-Authenticate` header
falls back to probing them, path-scoped first.

## Reading the token in a handler

The validated token reaches handlers on the receive context:

```php
$builder->addTool(new Tool(name: 'whoami'), function (CallToolRequest $request, ServerContext $context) {
    $subject = $context->receiveContext->authInfo?->subject ?? 'anonymous';

    return new CallToolResult(content: [new TextContent(text: $subject)]);
});
```

It is `null` on an unprotected endpoint and over stdio.
