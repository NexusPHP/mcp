# Okta

Okta needs a custom authorization server for API audiences, and its dynamic client registration requires
an API token, so clients are usually pre-registered.

Configure the org:

- Create (or reuse) a **custom authorization server** under **Security** > **API**. Its issuer is
  `https://{org}.okta.com/oauth2/{authServerId}`. Set its **audience** to the MCP server's canonical URI.
- Define the scopes on that server (`mcp:use` below) and an access policy granting them.
- Register an **application** per MCP client with its redirect URI. Okta's registration endpoint demands
  an API token rather than accepting anonymous registration, so hand the SDK client pre-registered
  credentials (see [choosing a client identifier](client.md#choosing-a-client-identifier)).

Metadata is published at both well-known suffixes under the issuer, so the SDK's discovery finds it
either way. Validate against the `jwks_uri` that metadata names. Okta puts granted scopes in `scp` as a
JSON array, not a space-joined `scope` string:

```php
return new VerifiedAccessToken(
    audience: (array) ($claims['aud'] ?? []),
    scopes: (array) ($claims['scp'] ?? []),
    subject: $claims['sub'] ?? null,
    clientId: $claims['cid'] ?? null,
);
```

The authorizing client rides the `cid` claim. Pass the authorization server's configured audience as the
canonical resource to `BearerAuthenticationMiddleware`, and name the issuer in the
[protected resource metadata document](server.md#publishing-the-metadata-document). The org authorization
server (`https://{org}.okta.com`) does not take custom audiences, which is why the custom one is not
optional here.
