# Okta

Okta needs a custom authorization server for API audiences, and its dynamic client registration requires an API
token, so clients are usually pre-registered.

## Configure the org

- Create, or reuse, a **custom authorization server** under **Security** > **API**. Its issuer is
  `https://{org}.okta.com/oauth2/{authServerId}`. Set its **audience** to the MCP server's canonical URI.
- Define the scopes on that server (`mcp:use` below), and an access policy that grants them.
- Register an **application** per MCP client with its redirect URI. Okta's registration endpoint demands an API
  token rather than accept anonymous registration, so hand the SDK client pre-registered credentials (see
  [choosing a client identifier](client.md#choosing-a-client-identifier)).

## Validate the tokens

Metadata is published at both well-known suffixes under the issuer, so the SDK's discovery finds it either way.
Validate with the shipped [`JwksAccessTokenValidator`](server.md#validating-tokens) against the `jwks_uri` that
metadata names. Give it the authorization server's issuer (`https://{org}.okta.com/oauth2/{authServerId}`) as the
expected issuer.

Okta puts the granted scopes in `scp` as a JSON array rather than a space-joined `scope` string, and the
authorizing client rides the `cid` claim. The validator reads both shapes.

## Publish the metadata

Pass the authorization server's configured audience as the canonical resource to
`BearerAuthenticationMiddleware`, and name the issuer in the
[protected resource metadata document](server.md#publishing-the-metadata-document). The org authorization server
(`https://{org}.okta.com`) does not take custom audiences, which is why the custom one is not optional here.
