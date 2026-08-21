# Authorization (OAuth 2.1)

MCP protects HTTP transports with OAuth 2.1. An MCP server is an OAuth **resource server**. An MCP client is an
OAuth **client**. The authorization server is a third party that neither of them implements. Stdio servers are
out of scope: they take credentials from the environment instead.

The SDK ships both halves. Neither is enabled by default, and neither adds a dependency. PKCE, `state`, and the
metadata fetches all ride on machinery the SDK already carries.

## Guide

- **[Client authorization](auth/client.md)**: composing an authorized client, the user-agent leg, choosing a
  client identifier, and talking to a local authorization server.
- **[Persisting tokens and registrations](auth/persistence.md)**: the store interfaces and what to keep where.
- **[Scopes and step-up](auth/scopes.md)**: scope selection, insufficient-scope retries, and what a new grant
  means for scopes granted earlier.
- **[Resource server](auth/server.md)**: validating tokens, publishing the metadata document, and reading the
  token in a handler.
- **[OAuth extension grants](auth/extension-grants.md)**: the ratified client credentials (SEP-1046) and
  enterprise-managed authorization (SEP-990) extensions. They are unattended machine grants for
  `AuthorizedHttpClient`, each paired with a [server-side advertisement](server/extensions.md#official-extensions).

### Runnable flows

[`examples/authorization.php`](../examples/authorization.php) runs the whole client flow in one process against a
stub authorization server. It needs no Docker and no browser. [`examples/keycloak-e2e/`](../examples/keycloak-e2e/)
runs the same flow against a real identity provider.

### Provider recipes

Each recipe covers the provider-side configuration, the token validator, and the quirks the generic pages cannot
know:

- **[Keycloak](auth/keycloak.md)**: the self-hostable reference, with anonymous client registration.
- **[Microsoft Entra ID](auth/entra.md)**: pre-registered clients and `scp` claims.
- **[Auth0](auth/auth0.md)**: audience through tenant configuration rather than RFC 8707.
- **[Okta](auth/okta.md)**: custom authorization servers and array-valued `scp`.

## What the SDK enforces

These checks are not optional. They are the checks implementations most often skip.

### PKCE `S256`

The client refuses an authorization server that does not advertise `code_challenge_methods_supported`. It never
downgrades.

### Issuer validation

The client rejects a metadata document that names an issuer other than the URL it came from. It compares the
authorization response's `iss` by exact string, with no URL normalization. On a mismatch, the client never
surfaces the error the response carries.

### `state`

The client sends `state` on every authorization request and verifies it on the response.

### Resource indicators

The canonical server URI travels on both the authorization request and the token request. The client rejects a
metadata document that names a different resource.

### HTTPS

Every authorization server URL must be HTTPS: the issuer, the metadata URLs derived from it, and the
authorization, token, and registration endpoints it publishes.

The redirect URI is the one URL the spec lets address a loopback listener over plain HTTP, so a local development
callback keeps working.

This check covers the transport a URL names, not where it leads. The client admits an HTTPS URL that names a
private-network or link-local address. An operator who needs those blocked should block them in the HTTP client
handed to the decorator. See
[Talking to a local authorization server](auth/client.md#talking-to-a-local-authorization-server) for the one
opt-out.

### No fragment

The client refuses an authorization server URL that carries a fragment. The `state` and `code_challenge` this
client appends to the authorization endpoint would land in the fragment and never reach the server.

### Same origin

The client reads the Protected Resource Metadata document only from the MCP server's own origin. That is what
binds the document to the server it describes. The client drops a `resource_metadata` URL that a challenge
advertises off that origin, and probes the well-known URLs instead.

This leg follows the MCP server's own scheme. A loopback MCP server reached over plain HTTP serves its metadata
over plain HTTP too.

### No redirects

On the authorization legs, the client trusts nothing read from an answer that arrived from a URL other than the
one it was sent to. Every leg, metadata discovery included, runs on a client that follows no redirect.

A `3xx` on a credentialed request comes back to the decorator. The decorator refuses it unless the target is the
canonical resource or a path under it, so the credential never travels to the target. A redirected well-known
probe reads as nothing served there, and the client tries the next candidate.

An origin carries its scheme, so the decorator refuses a downgrade from `https` to cleartext on the same host like
any other hop. A request the decorator sent no credential with is left alone, redirects and all.

### Audience binding

On the server side, the SDK refuses a token whose audience does not name this server. On the client side, the SDK
sends a token only to the resource it was obtained for, or a path under it. Another path on the same origin,
another tenant included, gets the request without the credential.

### Bearer tokens only

The client refuses a token of any other type. It never sends one as a bearer token.

## Errors

Every exception below implements `McpExceptionInterface`.

| Exception | Raised when |
| --- | --- |
| `UntrustedAuthorizationMetadataException` | A document named a resource or issuer other than the one it was served for, or an authorization server URL is not HTTPS or carries a fragment. |
| `PkceNotSupportedException` | The authorization server does not advertise `S256`. |
| `InsecureAuthorizationEndpointException` | The configured redirect URI is plain HTTP on a non-loopback host. |
| `ClientRegistrationRequiredException` | The server offers no registration mechanism the client can use. |
| `AuthorizationServerMismatchException` | Supplied credentials belong to a different authorization server. |
| `InvalidAuthorizationResponseException` | The response failed `state`, `iss`, or code validation. |
| `AuthorizationGrantRejectedException` | The token endpoint refused the request because the grant is spent. |
| `ClientRegistrationRejectedException` | The token endpoint does not recognise the client identifier presented to it. |
| `MalformedAuthorizationResponseException` | An authorization or metadata endpoint answered with something other than a JSON object. |
| `RedirectRefusedException` | A response arrived from a URL other than the one the request was sent to. |
| `InsufficientScopeException` | The server answered `insufficient_scope` and asking the resource owner cannot help: the client is set to report rather than ask, the upgrade budget is spent, or the challenge names nothing the token lacks. |
| `RuntimeException` | Every remaining flow failure whose message is the whole diagnostic: no probed URL served a metadata document, Dynamic Client Registration was refused, the authorization server answered with an OAuth error, or the token endpoint refused on terms granting again will not clear. |

The SDK raises `AuthorizationGrantRejectedException` for the RFC 6749 codes that mean granting again would help:
`invalid_grant` and `invalid_scope`. That is the split the SDK acts on. A refresh that hits one of those codes
drops the stored token and re-authorizes.

The other codes surface to you with the token left alone: `invalid_client`, `unsupported_grant_type`,
`server_error`, and the rest. No number of browser round trips fixes a misconfigured client or an authorization
server that is down.

## See also

- [Transports](transports.md): the Streamable HTTP transport both halves attach to.
- [Client API](client.md): the client this decorator sits under.
- [Error handling](error-handling.md): the wider exception model.
