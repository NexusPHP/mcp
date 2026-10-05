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

$validator = new JwksAccessTokenValidator(
    new CachedKeySet(
        'https://auth.example.com/.well-known/jwks.json',
        $httpClient,          // any PSR-18 client
        $requestFactory,      // any PSR-17 request factory
        $cache,               // any PSR-6 cache
        300,
        rateLimit: true,
    ),
    'https://auth.example.com',  // the `iss` every accepted token must carry
    'https://mcp.example.com/mcp',  // the resource every accepted token's `aud` must name
);
```

Constructing it without the package installed throws a `LogicException` that names the install command.

`CachedKeySet` fetches the JWKS synchronously the first time it meets a `kid` it does not hold, and it meets
that `kid` before the signature is checked, so an unsigned token naming an unknown one costs a fetch. A PSR-18
client that blocks holds the event loop, and every other fiber with it, for the whole round trip. Keep
`rateLimit: true`, which bounds those fetches to ten a minute per cache, and prefer a PSR-18 client built on
`amphp/http-client`, which yields instead.

### What the validator refuses

The validator refuses a token whose signature does not verify, whose `iss` is absent or is not the issuer you
named, whose `aud` does not name the resource, or which carries no `exp` at all.

A key set may sign for several issuers, so the issuer is what bounds the tenant rather than the audience alone.
A token minted with no expiry would otherwise be a permanent credential.

The validator maps the claim spellings used by the common providers: `scope` or `scp` (string or list) for scopes,
and `azp`, `client_id`, or `cid` for the authorizing client. The [provider recipes](../authorization.md#guide)
name each provider's JWKS URL and quirks.

### Your own validator

For anything else (opaque tokens, introspection endpoints, provider SDKs), verification stays yours:

```php
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;

final class JwtAccessTokenValidator implements AccessTokenValidatorInterface
{
    public function validate(string $token): ?VerifiedAccessToken
    {
        $claims = $this->verifySignature($token);

        if (null === $claims || ($claims['iss'] ?? null) !== $this->expectedIssuer) {
            return null;
        }

        if (! $this->resource->matchesAudience(array_values(array_filter((array) ($claims['aud'] ?? []), is_string(...))))) {
            return null;
        }

        // Most JWT libraries check an expiry only when present, so an absent one never expires.
        if (! is_numeric($claims['exp'] ?? null) || $claims['exp'] < time()) {
            return null;
        }

        // An identity claim that is absent, empty, or not a string names nobody, so normalise all three to null.
        $subject = $claims['sub'] ?? null;
        $clientId = $claims['client_id'] ?? null;

        return new VerifiedAccessToken(
            audience: $claims['aud'],
            scopes: explode(' ', $claims['scope'] ?? ''),
            subject: is_string($subject) && '' !== $subject ? $subject : null,
            clientId: is_string($clientId) && '' !== $clientId ? $clientId : null,
            expiresAt: (int) $claims['exp'],
        );
    }
}
```

The validator owns signature checking, the issuer, the audience, and expiry. `BearerAuthenticationMiddleware`
checks the audience again and enforces the endpoint's own rules on top. A token minted for another resource is
refused even if a validator of your own lets it through, and so is a token handed over already expired.

The middleware's expiry check tolerates no clock skew by default, and a validator's own tolerance does not reach
it. If you set `JWT::$leeway` for `firebase/php-jwt`, or your validator allows skew some other way, pass the same
allowance as `expiryLeewaySeconds` to `BearerAuthenticationMiddleware`. Otherwise it refuses what the validator
deliberately accepted.

### Mounting the middleware

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

Authentication runs after CORS and DNS-rebinding protection, and before anything reads the body. An unauthorized
request is turned away without being parsed.

### Scopes for one operation

`requiredScopes` applies to every request. To ask more of a single tool, prompt, or resource, add
`OperationScopeMiddleware`. A client then starts with the endpoint's scopes and steps up only when it reaches an
operation that needs more, which is the least-privilege model recommended by the spec.

```php
use Nexus\Mcp\Server\Transport\Http\Middleware\OperationScopeMiddleware;

$endpoint = new SecuredHttpEndpoint(
    $transport,
    ['https://app.example.com'],
    $responseFactory,
    $streamFactory,
    authentication: $bearerMiddleware,
    operationScopes: new OperationScopeMiddleware(
        'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
        $responseFactory,
        $streamFactory,
        tools: ['delete_file' => ['files:write']],
        prompts: ['release_notes' => ['repo:read']],
        resources: [
            'config://deploy' => ['deploy:read'],
            'file:///reports/{name}' => ['files:read'],
        ],
        endpointScopes: ['mcp:use'],
    ),
);
```

A request whose token lacks a listed scope is answered `403` with `error="insufficient_scope"`, before the handler
runs. An operation with no entry needs nothing beyond `requiredScopes`.

| Entry | Requests it covers |
| --- | --- |
| `tools` | `tools/call` |
| `prompts` | `prompts/get`, and `completion/complete` for that prompt |
| `resources` | `resources/read`, `completion/complete` for a matching template, and `subscriptions/listen` naming a matching resource |

Pass the bearer middleware's `requiredScopes` as `endpointScopes`. The challenge then names them ahead of the
operation's own, every scope and not only the missing ones, so a client that asks for exactly what a challenge
names still ends up with a token accepted by the endpoint.

A resource key is a URI or a URI template in the form that `addResourceTemplate()` takes. A URI that matches
several keys needs the scopes of all of them. A URI is matched as sent and again percent-decoded, since a template
binds either form to the same value.

Each scope must be an RFC 6749 `scope-token`, so two scopes joined by a space are refused when the middleware is
built rather than becoming a requirement that no token can meet.

The keys are not checked against what the server serves. A key that names nothing is ignored, so a renamed tool
keeps its scopes only if its key is renamed with it.

The middleware reads the body, so it runs after the body-size cap. It checks the token validated by an
authentication middleware, and `SecuredHttpEndpoint` refuses `operationScopes` without `authentication`. An
authenticator of your own must store the `VerifiedAccessToken` on the request attribute
`VerifiedAccessToken::REQUEST_ATTRIBUTE`, as `BearerAuthenticationMiddleware` does. A scoped operation that
arrives without one throws `LogicException` rather than challenge a client that did nothing wrong.

Over stdio there is no token and no HTTP status to answer with, so the declarations do not apply there.

## Publishing the metadata document

Clients find your authorization server by reading a metadata document. Route `ProtectedResourceMetadataHandler`
at both well-known paths, and name the same URL in the middleware above:

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
Mounting it anywhere else answers `404` rather than publish the same document under a name that no client will look
it up by.

Serve both well-known paths. A client that never saw a `WWW-Authenticate` header falls
back to probing them, path-scoped first.

## Reading the token in a handler

The validated token reaches handlers on the receive context:

```php
$builder->addTool(new Tool(name: 'whoami'), function (CallToolRequest $request, ServerContext $context) {
    $authInfo = $context->receiveContext->authInfo;
    $subject = match (true) {
        null === $authInfo => 'anonymous',
        null === $authInfo->subject => 'authenticated, unnamed',
        default => $authInfo->subject,
    };

    return new CallToolResult(content: [new TextContent(text: $subject)]);
});
```

`authInfo` is `null` on an unprotected endpoint and over stdio. Its `subject` is separately `null` for an accepted
token that carries no non-empty string `sub` claim. That is why the two are tested apart above. An authenticated
caller that the token cannot name is not an anonymous one.
