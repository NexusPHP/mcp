# Auth0

Auth0 supports dynamic client registration and OIDC discovery, and mints RS256 tokens against a JWKS, so
most of the flow is the SDK's discovery doing its usual walk. The one seam is the audience.

Configure the tenant:

- Create an **API** whose identifier is the MCP server's canonical URI (`https://mcp.example.com/mcp`).
  The identifier becomes the token's `aud`.
- Define the API's scopes (`mcp:use` below).
- Enable **OIDC Dynamic Application Registration** if MCP clients should register themselves. Otherwise
  register an application per client and hand out its credentials.
- The issuer is `https://{tenant}.auth0.com/`.

Auth0 selects the audience from its own `audience` request parameter, not from the RFC 8707 `resource`
parameter the SDK client sends. Set the API as the tenant's **default audience** so authorization requests
that carry no `audience` still mint tokens for it. Without that, Auth0 answers with an opaque token for
the userinfo endpoint, and your validator refuses it.

Validate against `https://{tenant}.auth0.com/.well-known/jwks.json`. Auth0 uses the standard `scope`
claim, so the [Keycloak validator](keycloak.md) works as written apart from the key-set URL, with one
difference: the authorizing client is `azp` only when the token carries more than one audience, so fall
back to `client_id`:

```php
clientId: $claims['azp'] ?? $claims['client_id'] ?? null,
```

Publish the [protected resource metadata document](server.md#publishing-the-metadata-document) naming the
tenant issuer, and pass the API identifier as the canonical resource to `BearerAuthenticationMiddleware`.
