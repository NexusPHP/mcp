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

The token goes only to the origin the decorator was built for, so handing the same client a request aimed
somewhere else sends it unauthenticated rather than leaking the credential. If an answer arrives from a
different origin than the request was sent to, which is what an HTTP client that follows redirects will do,
the response is refused with `RedirectRefusedException` instead of trusted.

The inner client carries the authorization traffic too, so an interceptor placed on it (proxy, logging,
custom TLS) applies to discovery, registration, and token requests as well.

### Implementing the user-agent leg

The SDK cannot open a browser, and it ships no HTTP server to catch the redirect. That one leg is yours:

```php
use Amp\ByteStream;
use Amp\Cancellation;
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;

final class ConsoleUserAuthorization implements UserAuthorizationInterface
{
    public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
    {
        ByteStream\getStdout()->write("Open this URL to authorize:\n".$redirect->url."\nPaste the URL you were redirected to: ");

        return new AuthorizationCallback(trim((string) ByteStream\getStdin()->read($cancellation)));
    }
}
```

An implementation only opens `$redirect->url` and reports where the user-agent landed. The SDK owns the PKCE
pair, the `state` value, the expected issuer, and every validation of the response, so a callback that answers
a different request, names a different issuer, or carries an error is rejected before the code is redeemed.

Read the answer without blocking the event loop. A bare `fgets(\STDIN)` halts every fiber in the process,
including the SSE streams the transport is holding open. `$cancellation` fires when the request that needs the
token is abandoned, so honouring it is what lets a client shut down while a consent screen is still open.

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
   resulting identifier against the issuer that minted it. Two clients sharing one registration store may
   both register against the same authorization server at once, since nothing outside a single client can
   serialise them. Both registrations are valid and the store keeps the last.

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
    public function forget(string $issuer): void;
}
```

Tokens are keyed by the MCP server, and registrations by the issuer. Each `AccessToken` carries the `issuer`
that minted it, which is what makes an authorization server change safe: a token stamped with an issuer the
resource no longer names is dropped rather than presented or refreshed at the new one. Reading a token back
from a store therefore costs one discovery round trip before the first request goes out, because the SDK must
not send a token to a server other than the one that issued it, and until discovery has run it cannot tell.
Later requests in the same process present the stored token directly. A registration the authorization server
stops recognising is dropped from the store rather than presented again, so an expired one heals on the next
request instead of bricking the client. Store both confidentially. They are credentials.

### Scopes and step-up

Scope selection starts from the `scope` a challenge names, falling back to `defaultScopes` if you declared any,
then to the resource's `scopes_supported`, and omitting the parameter entirely if none of those names anything.
The first two rungs are the spec's; `defaultScopes` is an extra tier the SDK adds so you can avoid asking for
everything a resource happens to advertise when your client only needs part of it. Since servers *should* name
a `scope` in the challenge, `defaultScopes` mostly bites against servers that omit it. Whatever the baseline,
the scopes already granted are unioned on top, and they survive a token being dropped, so re-authorizing never
narrows what the client may do.

`offline_access` is decided by the client alone: it is stripped from that union and added back only when you
pass `requestOfflineAccess: true` *and* the authorization server lists it, so a resource cannot talk you into
holding a refresh token. That is what gets a refresh token issued, and a refresh token outlives the session, so
asking for one stays your call. Asking for it also sends `prompt=consent`, because a server that can answer
silently from a prior grant generally will, and a silent answer carries no refresh token.

A `403` carrying `error="insufficient_scope"` triggers a step-up. The SDK unions the challenged scopes with
those already granted, so a fresh grant never costs permissions other operations depend on, and retries.
`maxScopeUpgrades` caps how many rounds that may take (default `2`) before the `403` is returned to the caller.

A challenge that names no scope the token is missing, including one that names no scope at all, is returned
rather than retried. Asking again would produce the same token and the same `403`, and the only thing the
round trip would buy the user is a second consent screen.

Pass `onInsufficientScope: InsufficientScopePolicy::Fail` to be told instead of asked. The SDK then raises
`InsufficientScopeException` naming the scopes the server wants, without running discovery or opening a
consent screen, which is what an unattended process usually wants. It is raised for every insufficient-scope
answer, including ones `maxScopeUpgrades` would otherwise have swallowed. That covers the `403` path only. To
refuse every prompt, including the one a `401` provokes, throw from your `UserAuthorizationInterface` instead.

A `401` re-authorizes once, and carries the rejected token's scopes into the new grant for the same reason. A
second `401` on the token that came back is taken as the server's answer and returned to the caller.

Everything that writes the token runs one at a time per `AuthorizedHttpClient`, renewals included. Concurrent requests
that all hit a `401` therefore see one flow: the first runs discovery, registration and the browser round
trip, and the rest take the token it obtained rather than opening a second consent screen, registering a
second client, or racing to redeem one rotating refresh token. A caller that waited its turn only takes
another's token when it covers what that caller was refused for, so a step-up reaching past the running grant
still asks for its own. Two MCP servers never wait on each other. That lock lives on the client, not on the
store, so two `AuthorizedHttpClient` instances built for the *same* MCP server and handed the same token
store can still both authorize. Build one per MCP server.

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
- **HTTPS.** Every authorization endpoint is required to be HTTPS. A URL the operator configured may be
  loopback so a local development authorization server stays reachable. A URL an MCP server or an
  authorization server advertised may be loopback only when the MCP server is itself on loopback, so a peer
  on the public internet cannot steer the client at an address it could not otherwise reach.
- **Same origin.** The `resource_metadata` URL a challenge advertises must share the MCP server's origin.
- **No redirects.** Nothing read from an answer that arrived from a URL other than the one it was sent to is
  trusted, on the authorization legs and on the MCP request alike.
- **Audience binding.** Server-side, a token whose audience does not name this server is refused. Client-side,
  a token is sent only to the origin it was obtained for.
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
| `TokenRequestFailedException` | The token endpoint refused the request on terms granting again will not clear. |
| `AuthorizationGrantRejectedException` | The token endpoint refused the request because the grant is spent. |
| `ClientRegistrationRejectedException` | The token endpoint does not recognise the client identifier presented to it. |
| `MalformedAuthorizationResponseException` | An authorization or metadata endpoint answered with something other than a JSON object. |
| `RedirectRefusedException` | A response arrived from a URL other than the one the request was sent to. |
| `InsufficientScopeException` | The server wants scopes the token lacks, and the client is set to report rather than ask. |

`AuthorizationGrantRejectedException` extends `TokenRequestFailedException` and is raised for the RFC 6749
codes that mean granting again would help: `invalid_grant` and `invalid_scope`. That is the split the SDK acts
on. A refresh that hits one of those drops the stored token and re-authorizes, while `invalid_client`,
`unsupported_grant_type`, `server_error` and the rest surface to you with the token left alone, because no
number of browser round trips fixes a misconfigured client or an authorization server that is down.

## See also

- [Transports](transports.md): the Streamable HTTP transport both halves attach to.
- [Client API](client.md): the client this decorator sits under.
- [Error handling](error-handling.md): the wider exception model.
