# Authorization (OAuth 2.1)

MCP protects HTTP transports with OAuth 2.1. An MCP server is an OAuth **resource server**, an MCP client is
an OAuth **client**, and the authorization server is a third party neither of them implements. Stdio servers
are out of scope: they take credentials from the environment instead.

The SDK ships both halves. Neither is enabled by default, and neither adds a dependency: PKCE, `state`, and
the metadata fetches all ride on machinery the SDK already carries.

- [Client](#client)
  - [Composing an authorized client](#composing-an-authorized-client)
  - [Implementing the user-agent leg](#implementing-the-user-agent-leg)
  - [Choosing a client identifier](#choosing-a-client-identifier)
  - [Persisting tokens and registrations](#persisting-tokens-and-registrations)
  - [Scopes and step-up](#scopes-and-step-up)
- [Server](#server)
  - [Validating tokens](#validating-tokens)
  - [Publishing the metadata document](#publishing-the-metadata-document)
  - [Reading the token in a handler](#reading-the-token-in-a-handler)
- [What the SDK enforces](#what-the-sdk-enforces)
- [Errors](#errors)

## Client

### Composing an authorized client

`AuthorizedHttpClient` is a decorator around the HTTP client the Streamable HTTP transport already takes. Wrap
the client, hand the wrapper to the transport, and the rest of the SDK is unchanged.

```php
use Amp\Http\Client\HttpClientBuilder;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;

$endpoint = 'https://mcp.example.com/mcp';

$http = new AuthorizedHttpClient(
    $endpoint,
    new AuthorizationOptions(
        clientName: 'Example MCP Client',
        redirectUri: 'http://127.0.0.1:8765/callback',
    ),
    new ConsoleUserAuthorization(),
    HttpClientBuilder::buildDefault(),
);

$client = $builder->build();
$client->connect(new StreamableHttpClientTransport($endpoint, $http));
```

Nothing happens until the server challenges. On the first `401` the decorator reads the `WWW-Authenticate`
header, discovers the authorization server, obtains a token, and replays the request with it. Later requests
present the stored token directly.

The inner client carries the authorization traffic too, so an interceptor placed on it (proxy, logging,
custom TLS) applies to discovery, registration, and token requests as well.

### Implementing the user-agent leg

The SDK cannot open a browser, and it ships no HTTP server to catch the redirect. That one leg is yours:

```php
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;

final class ConsoleUserAuthorization implements UserAuthorizationInterface
{
    public function authorize(AuthorizationRedirect $redirect): AuthorizationCallback
    {
        echo "Open this URL to authorize:\n", $redirect->url, "\n";
        echo "Paste the URL you were redirected to: ";

        return new AuthorizationCallback(trim((string) fgets(\STDIN)));
    }
}
```

An implementation only opens `$redirect->url` and reports where the user-agent landed. The SDK owns the PKCE
pair, the `state` value, the expected issuer, and every validation of the response, so a callback that answers
a different request, names a different issuer, or carries an error is rejected before the code is redeemed.

A host that runs a loopback listener returns `new AuthorizationCallback((string) $request->getUri())` instead
of prompting.

### Choosing a client identifier

Clients are identified in one of three ways, and the SDK walks them in the order the spec fixes:

1. **Pre-registration.** Credentials issued out of band, passed as `preRegistered`. They are bound to one
   authorization server: presenting them to a different one is refused rather than silently attempted.
2. **Client ID Metadata Documents.** Host a JSON document at an HTTPS URL and pass that URL as
   `clientIdMetadataDocumentUrl`. The URL *is* the `client_id`, so it works with any authorization server that
   advertises `client_id_metadata_document_supported`, and it needs no registration at all.
3. **Dynamic Client Registration.** Used when the server publishes a `registration_endpoint`. The SDK declares
   `application_type` (defaulting to `native`, which is what loopback redirect URIs need) and stores the
   resulting identifier against the issuer that minted it.

When none of the three applies, `ClientRegistrationRequiredException` says so rather than failing obscurely.

```php
new AuthorizationOptions(
    clientName: 'Example MCP Client',
    redirectUri: 'http://127.0.0.1:8765/callback',
    clientIdMetadataDocumentUrl: 'https://app.example.com/oauth/client.json',
);
```

### Persisting tokens and registrations

Both stores default to memory, so a restart authorizes again. Implement the interfaces to outlive the process:

```php
interface TokenStoreInterface
{
    public function read(string $resource): ?AccessToken;
    public function write(string $resource, AccessToken $token): void;
    public function forget(string $resource): void;
}

interface ClientRegistrationStoreInterface
{
    public function read(string $issuer): ?ClientRegistration;
    public function write(string $issuer, ClientRegistration $registration): void;
}
```

Tokens are keyed by the MCP server, and registrations by the issuer. Each `AccessToken` carries the `issuer`
that minted it, so a store written in one process is usable in the next without repeating discovery first:
hand back a stored token and the SDK presents it straight away. That stamp is also what makes an authorization
server change safe. Once discovery has run and found the resource has moved, a token stamped with the former
issuer is dropped rather than refreshed at the new one. A stored token is presented before any discovery has
happened, so if the resource moved between processes the first request spends a `401` before the client
notices and re-authorizes. Store both confidentially. They are credentials.

### Scopes and step-up

Scope selection follows the spec's priority: the `scope` a challenge names wins, otherwise `defaultScopes` if
you declared any, otherwise the resource's `scopes_supported`, otherwise the parameter is omitted entirely.
Declaring `defaultScopes` is how you avoid asking for everything a resource happens to advertise when your
client only needs part of it. `offline_access` is added only when you pass
`requestOfflineAccess: true` *and* the authorization server lists it. That is what gets a refresh token issued,
and a refresh token outlives the session, so asking for one stays your call.

A `403` carrying `error="insufficient_scope"` triggers a step-up. The SDK unions the challenged scopes with
those already granted, so a fresh grant never costs permissions other operations depend on, and retries.
`maxScopeUpgrades` caps how many rounds that may take (default `2`) before the `403` is returned to the caller.

A challenge that names no scope the token is missing, including one that names no scope at all, is returned
rather than retried. Asking again would produce the same token and the same `403`, and the only thing the
round trip would buy the user is a second consent screen.

A `401` re-authorizes once, and carries the rejected token's scopes into the new grant for the same reason. A
second `401` on the token that came back is taken as the server's answer and returned to the caller.

Concurrent requests that all hit a `401` share one flow. The first runs discovery, registration and the
browser round trip, and the rest wait on its result rather than opening a second consent screen or registering
a second client. Renewals share the same way, so a rotating refresh token is redeemed once instead of raced.

What discovery finds is kept for the life of the client, so a step-up goes straight back to the token endpoint
instead of re-reading both metadata documents. A `401` drops it again, since a fresh challenge is the one
moment the server gets to name a different authorization server.

## Server

### Validating tokens

The SDK ships no signature or introspection machinery. Verification is the one thing your authorization server
dictates, so it stays yours:

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

### Publishing the metadata document

Clients find your authorization server by reading a metadata document. Route
`ProtectedResourceMetadataHandler` at both well-known paths and name the same URL in the middleware above:

```php
use Nexus\Mcp\Server\Auth\ProtectedResourceMetadataHandler;

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

Serving both well-known paths is worth the two lines: a client that never saw a `WWW-Authenticate` header
falls back to probing them, path-scoped first.

### Reading the token in a handler

The validated token reaches handlers on the receive context:

```php
$builder->addTool(new Tool(name: 'whoami'), function (CallToolRequest $request, ServerContext $context) {
    $subject = $context->receiveContext->authInfo?->subject ?? 'anonymous';

    return new CallToolResult(content: [new TextContent(text: $subject)]);
});
```

It is `null` on an unprotected endpoint and over stdio.

## What the SDK enforces

These are not optional, and they are the checks implementations most often skip:

- **PKCE `S256`.** An authorization server that does not advertise `code_challenge_methods_supported` is
  refused rather than downgraded.
- **Issuer validation.** A metadata document naming an issuer other than the URL it came from is rejected,
  and the authorization response's `iss` is compared by exact string, with no URL normalization. On a
  mismatch the error the response carries is never surfaced.
- **`state`.** Sent on every authorization request and verified on the response.
- **Resource indicators.** The canonical server URI travels on both the authorization and the token request,
  and a metadata document naming a different resource is rejected.
- **HTTPS.** Every authorization endpoint is required to be HTTPS. Loopback hosts are exempt so a local
  development authorization server stays reachable.
- **Audience binding.** Server-side, a token whose audience does not name this server is refused.
- **Bearer tokens only.** A token of any other type is refused rather than sent as one.

## Errors

Every exception below implements `McpExceptionInterface`.

| Exception | Raised when |
| --- | --- |
| `AuthorizationDiscoveryFailedException` | No probed URL served the metadata document. |
| `UntrustedAuthorizationMetadataException` | A document named a resource or issuer other than the one it was served for. |
| `PkceNotSupportedException` | The authorization server does not advertise `S256`. |
| `InsecureAuthorizationEndpointException` | An endpoint would be contacted over plain HTTP from a non-loopback host. |
| `ClientRegistrationRequiredException` | The server offers no registration mechanism the client can use. |
| `ClientRegistrationFailedException` | Dynamic Client Registration was refused, or granted on unusable terms. |
| `AuthorizationServerMismatchException` | Supplied credentials belong to a different authorization server. |
| `InvalidAuthorizationResponseException` | The response failed `state`, `iss`, or code validation. |
| `AuthorizationDeniedException` | The authorization server answered with an OAuth error. |
| `TokenRequestFailedException` | The token endpoint refused the request. |
| `AuthorizationGrantRejectedException` | The token endpoint refused the request because the grant is spent. |

`AuthorizationGrantRejectedException` extends `TokenRequestFailedException` and is raised for the RFC 6749
codes that mean granting again would help: `invalid_grant` and `invalid_scope`. That is the split the SDK acts
on. A refresh that hits one of those drops the stored token and re-authorizes, while `invalid_client`,
`unsupported_grant_type`, `server_error` and the rest surface to you with the token left alone, because no
number of browser round trips fixes a misconfigured client or an authorization server that is down.

## See also

- [Transports](transports.md): the Streamable HTTP transport both halves attach to.
- [Client API](client.md): the client this decorator sits under.
- [Error handling](error-handling.md): the wider exception model.
