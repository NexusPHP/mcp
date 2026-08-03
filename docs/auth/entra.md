# Microsoft Entra ID

Entra ID has no dynamic client registration and no `resource` parameter, so both sides lean on
configuration the tenant admin does once.

Configure the tenant:

- Register an **app registration** for the MCP server, expose an API with an Application ID URI
  (`api://mcp.example.com`), and define a scope on it (`mcp:use` below).
- Register a second app for each MCP client, grant it the API's scope, and add the client's redirect URI.
- The issuer is `https://login.microsoftonline.com/{tenant-id}/v2.0`.

With no registration endpoint, the SDK client falls back to the pre-registered credentials you pass it
(see [choosing a client identifier](client.md#choosing-a-client-identifier)). Entra ignores the
RFC 8707 `resource` parameter the client sends and derives the audience from the scope instead, so
request the exposed scope by its full name: `api://mcp.example.com/mcp:use`.

Validate with the shipped [`JwksAccessTokenValidator`](server.md#validating-tokens) against the tenant's
JWKS, discovered through
`https://login.microsoftonline.com/{tenant-id}/v2.0/.well-known/openid-configuration`. Entra puts granted
scopes in `scp` rather than `scope` and names the authorizing client in `azp`, both spellings the
validator already reads.

The token's `aud` is the Application ID URI (or the API app's client id), so pass that same value as the
canonical resource to `BearerAuthenticationMiddleware`, and name the issuer in the
[protected resource metadata document](server.md#publishing-the-metadata-document). Ask for v2 tokens
(`accessTokenAcceptedVersion: 2` on the API app) so the claims above hold.
