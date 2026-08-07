# Keycloak

Keycloak fronts an MCP server well because it supports everything the SDK's client discovers on its own:
authorization-server metadata, anonymous dynamic client registration, and standard `scope` claims.

Configure a realm:

- Create a realm (`mcp` below). Its issuer is `https://kc.example.com/realms/mcp`.
- Enable **Client registration** > **Anonymous access policies** if MCP clients should register themselves.
  Skip this to require pre-registered clients instead.
- Define a client scope (`mcp:use` below) and, through a **mapper**, stamp the MCP server's canonical URI
  into the token's `aud` claim, so the audience binding below has something to bind.

Validate the RS256 tokens with the shipped
[`JwksAccessTokenValidator`](server.md#validating-tokens) against the realm's JWKS, published at
`/realms/mcp/protocol/openid-connect/certs`:

```php
use Firebase\JWT\CachedKeySet;
use Nexus\Mcp\Server\Auth\JwksAccessTokenValidator;

$validator = new JwksAccessTokenValidator(
    new CachedKeySet(
        'https://kc.example.com/realms/mcp/protocol/openid-connect/certs',
        $httpClient,
        $requestFactory,
        $cache,
        300,
    ),
    'https://kc.example.com/realms/mcp',  // the realm issuer
);
```

Mount it exactly as [Validating tokens](server.md#validating-tokens) shows, and publish a
[protected resource metadata document](server.md#publishing-the-metadata-document) naming the realm issuer
under `authorization_servers`. The SDK client then walks discovery, registration, PKCE, and the token
exchange without Keycloak-specific configuration.

Two gotchas:

- Keycloak identifies the authorizing client in `azp`, not `client_id`. The shipped validator reads both.
- A token's `aud` defaults to `account` unless a mapper adds your resource URI. Without the mapper,
  `BearerAuthenticationMiddleware` refuses every token, which is the audience binding doing its job.

All of this exists as a runnable whole in the
[Keycloak end-to-end example](../../examples/keycloak-e2e/README.md): a realm export with the scope,
mapper, and registration policies configured, a compose file that imports it, and the protected
server plus flow-walking client.
