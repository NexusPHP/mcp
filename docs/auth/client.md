# Client authorization

How the client obtains and presents a token.

## Composing an authorized client

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
    new HttpClientBuilder(),
);

$client = $builder->build();
$client->connect(new StreamableHttpClientTransport($endpoint, $http));
```

It takes the builder rather than a built client because it derives two: metadata discovery follows redirects,
and everything carrying a credential runs on one that does not, so a hop off this server's origin is refused
before the credential travels. A downgrade from `https` to `http` on the same host is such a hop, and it is
the one an ordinary client would follow while keeping the `Authorization` header, since it strips headers
only when the authority changes and an authority carries no scheme.

Configure the transport on the builder as usual (`usingPool()`, `intercept()`, `interceptNetwork()`,
`retry()`). To route everything through a client of your own, short-circuit with an interceptor:

```php
use Amp\Cancellation;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

final class MyTransport implements ApplicationInterceptor
{
    public function __construct(private readonly DelegateHttpClient $mine) {}

    public function request(Request $request, Cancellation $cancellation, DelegateHttpClient $next): Response
    {
        return $this->mine->request($request, $cancellation);
    }
}

$builder = (new HttpClientBuilder())->intercept(new MyTransport($mine));
```

Do not pass a builder carrying your own redirect follower for credentialed traffic. The decorator resolves
redirects itself precisely so it can refuse one before sending.

Nothing happens until the server challenges. On the first `401` the decorator reads the `WWW-Authenticate`
header, discovers the authorization server, obtains a token, and replays the request with it. Later requests
present the stored token directly.

The token goes only to the origin the decorator was built for, so handing the same client a request aimed
somewhere else sends it unauthenticated rather than leaking the credential. A request that does reach that
origin has its redirects resolved here, whether or not a token was ready: a hop leaving the origin is
refused with `RedirectRefusedException` before it is sent, and running out of hops raises
`TooManyRedirectsException` as the client's own follower would.

The inner client carries the authorization traffic too, so an interceptor placed on it (proxy, logging,
custom TLS) applies to discovery, registration, and token requests as well.

The authorization-code round trip is the default. A machine client with no user swaps it for an
unattended grant strategy instead: pass `null` for the user authorization and one of the
[OAuth extension grants](../client/auth-extensions.md) (client credentials, enterprise-managed
authorization) as `grantStrategy:`. An unattended grant also renews itself when its token expires,
since no refresh token is issued to redeem. The seam is public, so a grant this SDK does not model
is one you can [write yourself](../client/auth-extensions.md#writing-your-own-grant).

## Implementing the user-agent leg

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

## Choosing a client identifier

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

## Talking to a local authorization server

The spec exempts only the redirect URI from HTTPS, so an authorization server on `http://localhost` is
refused by default even when the MCP server is local too. That is correct for production and useless for
development, where the authorization server is usually a container on a loopback port. Opt out for those
runs:

```php
new AuthorizationOptions(
    clientName: 'Example MCP Client',
    redirectUri: 'http://127.0.0.1:8765/callback',
    allowInsecureLoopback: true,
);
```

It admits cleartext on `localhost`, `127.0.0.0/8`, and `[::1]`, and nothing else. A remote cleartext host
is still refused, so is a private-network address, and so is a URL carrying a fragment. Never set it in
production: it is the difference between a token that cannot leave the machine and one an observer on the
network can read.
