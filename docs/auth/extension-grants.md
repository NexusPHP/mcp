# OAuth extension grants

Two ratified extensions from the official
[ext-auth](https://github.com/modelcontextprotocol/ext-auth) line ship in
`Nexus\Mcp\Extension\Auth`: OAuth client credentials
(`io.modelcontextprotocol/oauth-client-credentials`, SEP-1046) and enterprise-managed authorization
(`io.modelcontextprotocol/enterprise-managed-authorization`, SEP-990). Neither defines any JSON-RPC
surface. Each is a capability declaration plus an HTTP-layer grant that runs inside
[`AuthorizedHttpClient`](client.md) in place of the authorization-code round trip, so no
user is ever put in front of a consent screen.

Both grants implement the client's grant-strategy seam: pass one as `grantStrategy:` and leave the
user-authorization argument `null`. Renewal is unattended too. When the machine token expires, the
client runs the grant again rather than sending the request bare and waiting for a challenge. It
does so whether or not the authorization server issued a refresh token alongside the access token,
since rerunning the grant costs the same as redeeming one.

Each grant carries its own credential, so the client credentials grant refuses an
`AuthorizationOptions::$preRegistered` set alongside it rather than silently outranking it. The
enterprise grant is the one that reads `preRegistered`, for the resource authorization server.

## Client credentials (SEP-1046)

The machine-to-machine grant. Credentials are registered out of band (Dynamic Client Registration
is not used), and exactly two authentication methods exist: a signed JWT client assertion
(`private_key_jwt`, recommended) or a client secret over HTTP Basic (`client_secret_basic`).

```php
use Amp\Http\Client\HttpClientBuilder;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsGrant;
use Nexus\Mcp\Extension\Auth\ClientCredentials\PrivateKeyJwtCredential;

$http = new AuthorizedHttpClient(
    'https://mcp.example.com/mcp',
    new AuthorizationOptions(clientName: 'acme-worker'),
    null,
    new HttpClientBuilder(),
    grantStrategy: new ClientCredentialsGrant(new PrivateKeyJwtCredential(
        clientId: 'the-worker',
        privateKeyPem: $privateKeyPem,
        algorithm: 'ES256',
        keyId: 'key-1',
    )),
);
```

`ClientSecretCredential('the-worker', $secret)` is the Basic-auth counterpart. Signing needs the
suggested `firebase/php-jwt` package (the same one `JwksAccessTokenValidator` uses on the server
side), and a missing install surfaces as an actionable message on the first grant.

The assertion names the client as both `iss` and `sub`, is addressed to the authorization server's
issuer identifier, and lives for five minutes. The token request carries the RFC 8707 `resource`
parameter and the selected scopes, and with JWT authentication `client_id` stays out of the body:
the assertion itself names the client.

SEP-1046 makes `token_endpoint_auth_methods_supported` a mandatory discovery signal. The grant
refuses with `RuntimeException` when the authorization server's metadata omits the configured
method, or, for JWT, advertises a signing-algorithm list without the configured algorithm. A
published `grant_types_supported` list that omits `client_credentials` is refused the same way.

## Enterprise-managed authorization (SEP-990)

The ID-JAG profile: the user signs into the client application through the enterprise IdP, and
that sign-on, not a redirect to the resource's authorization server, is what authorizes MCP access.
The grant runs two legs:

1. An RFC 8693 token exchange at the enterprise IdP turns the sign-on's identity assertion into an
   ID-JAG, a JWT authorization grant the IdP subjects to admin policy.
2. An RFC 7523 JWT-bearer grant redeems that ID-JAG at the resource's authorization server for the
   access token.

The client's seam to its own sign-on is `IdentityAssertionProviderInterface`, asked for a current
assertion once per grant:

```php
use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertion;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionGrant;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionProviderInterface;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionType;

final class SessionAssertionProvider implements IdentityAssertionProviderInterface
{
    public function provideAssertion(Cancellation $cancellation): IdentityAssertion
    {
        return new IdentityAssertion(currentSession()->idToken(), IdentityAssertionType::IdToken);
    }
}

$http = new AuthorizedHttpClient(
    'https://mcp.example.com/mcp',
    new AuthorizationOptions(
        clientName: 'acme-app',
        preRegistered: new ClientRegistration(clientId: 'acme-app', clientSecret: $secret),
    ),
    null,
    new HttpClientBuilder(),
    grantStrategy: new IdentityAssertionGrant(
        'https://idp.example.com/token',
        new SessionAssertionProvider(),
        idpClientId: 'acme-app-at-idp',
    ),
);
```

An OIDC ID token rides the exchange as-is. A SAML sign-on is first exchanged for a refresh token
out of band, offered as `IdentityAssertionType::RefreshToken`. The exchange asks for
`urn:ietf:params:oauth:token-type:id-jag` with the resource authorization server's issuer as the
`audience` and the MCP server's resource identifier as `resource`, and the answer must say it
issued an ID-JAG, or the grant fails with `RuntimeException`. The ID-JAG
itself stays opaque to the client.

At the resource's authorization server the client authenticates with credentials registered out of
band (`preRegistered`) or with a [Client ID Metadata Document](client.md) URL, never
Dynamic Client Registration. A published `authorization_grant_profiles_supported` list without
`urn:ietf:params:oauth:grant-profile:id-jag` is refused. An absent list only logs, since most
authorization servers do not publish the field yet.

The IdP token endpoint must be HTTPS, and the grant checks that when you construct it rather than
at the first request that needs a token. Pass `allowInsecureLoopback: true` to admit a cleartext
loopback IdP for local development.

## Declaring and advertising the extensions

Each extension has a settings-free capability declaration for the client
(`ClientCredentialsClientExtension`, `EnterpriseAuthorizationClientExtension`) and an advertising
counterpart for the server (`ClientCredentialsServerExtension`,
`EnterpriseAuthorizationServerExtension`), enabled like [any extension](../client/extensions.md):

```php
$client = (new ClientBuilder())
    ->setClientInfo('acme-worker', '1.0.0')
    ->enableExtension(new ClientCredentialsClientExtension())
    ->build();
```

The declaration rides every request's `_meta` capabilities. The grants themselves do not require
it, since the whole flow is HTTP-layer, but declaring is what tells the server which authorization
model the client runs, and the server classes exist for the same discoverability.

## Writing your own grant

The seam the two shipped grants ride is public, so an OAuth grant this SDK does not model is a
class you can write yourself. Implement `GrantStrategyInterface`:

```php
use Amp\Cancellation;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\GrantStrategyInterface;

final readonly class DeviceCodeGrant implements GrantStrategyInterface
{
    public function grant(GrantContext $context, Cancellation $cancellation): AccessToken
    {
        $registration = $context->resolveRegistration($cancellation);

        return $context->requestToken($registration, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $this->pollForDeviceCode($context, $cancellation),
            'resource' => $context->resource->value,
        ], $cancellation);
    }

    public function renewsByFreshGrant(): bool
    {
        return true;
    }
}
```

`GrantContext` is what the coordinator hands a grant once discovery has run. It carries the
discovery result (`$context->discovered->server` for the authorization server's metadata,
`->metadata` for the MCP server's own), the `resource` the token is for, the `scopes` to ask for,
the `options` the client was built with, and an `httpClient` and `logger` for anything the grant
does on its own. The two authorization-server calls are methods on the context rather than
collaborators you assemble:

- `resolveRegistration()` resolves the `client_id` to present, walking pre-registered credentials,
  then a Client ID Metadata Document, then Dynamic Client Registration. Skip it when the grant
  carries its own credential, as `ClientCredentialsGrant` does.
- `requestToken()` posts the form body to the discovered token endpoint, applying client
  authentication, RFC 6749 error triage, and the token read. Pass a fourth `ScopeSet` argument when
  the token should fall back to scopes other than the context's.

`renewsByFreshGrant()` says how an expired token with no refresh token is renewed. Return `true`
for an unattended grant, which reruns rather than sending the request bare to draw a challenge.
Return `false` when the grant needs a user, so the challenge path is the cheaper one.

The built-in authorization-code grant is written against this same surface, so nothing about it is
reachable that your own grant cannot reach.

DPoP (SEP-1932) and workload identity federation (SEP-1933) are still open proposals upstream and
are not modelled.

All three referee scenarios for these extensions pass:
`auth/client-credentials-basic`, `auth/client-credentials-jwt`, and
`auth/enterprise-managed-authorization`, run via `composer conformance:extensions:client`.
