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

Validate with the shipped [`JwksAccessTokenValidator`](server.md#validating-tokens) against
`https://{tenant}.auth0.com/.well-known/jwks.json`. Auth0 uses the standard `scope` claim, and names the
authorizing client in `azp` only when the token carries more than one audience, falling back to
`client_id` otherwise. The validator walks exactly that fallback chain.

Publish the [protected resource metadata document](server.md#publishing-the-metadata-document) naming the
tenant issuer, and pass the API identifier as the canonical resource to `BearerAuthenticationMiddleware`.
