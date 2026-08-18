# Authorization (OAuth 2.1)

MCP protects HTTP transports with OAuth 2.1. An MCP server is an OAuth **resource server**, an MCP client is
an OAuth **client**, and the authorization server is a third party neither of them implements. Stdio servers
are out of scope: they take credentials from the environment instead.

The SDK ships both halves. Neither is enabled by default, and neither adds a dependency: PKCE, `state`, and
the metadata fetches all ride on machinery the SDK already carries.

## Guide

- **[Client authorization](auth/client.md)**: composing an authorized client, the user-agent leg, choosing
  a client identifier, and talking to a local authorization server.
- **[Persisting tokens and registrations](auth/persistence.md)**: the store interfaces and what to keep
  where.
- **[Scopes and step-up](auth/scopes.md)**: scope selection, insufficient-scope retries, and what a new
  grant means for scopes granted earlier.
- **[Resource server](auth/server.md)**: validating tokens, publishing the metadata document, and reading
  the token in a handler.
- **[OAuth extension grants](client/auth-extensions.md)**: the ratified client credentials (SEP-1046) and
  enterprise-managed authorization (SEP-990) extensions, unattended machine grants for
  `AuthorizedHttpClient`, with the [server-side advertisement](server/extensions.md#official-extensions)
  each pairs with.

Provider recipes, each covering the provider-side configuration, the token validator, and the quirks the
generic pages cannot know:

- **[Keycloak](auth/keycloak.md)**, the self-hostable reference with anonymous client registration.
- **[Microsoft Entra ID](auth/entra.md)**, pre-registered clients and `scp` claims.
- **[Auth0](auth/auth0.md)**, audience via tenant configuration rather than RFC 8707.
- **[Okta](auth/okta.md)**, custom authorization servers and array-valued `scp`.

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
- **HTTPS.** Every authorization server URL is required to be HTTPS: the issuer, the metadata URLs derived
  from it, and the authorization, token, and registration endpoints it publishes. The redirect URI is the
  one URL the spec lets address a loopback listener over plain HTTP, so a local development callback keeps
  working. This checks the transport a URL names, not where it leads. An HTTPS URL naming a
  private-network or link-local address is admitted, so an operator who needs those blocked should block
  them in the HTTP client handed to the decorator. See
  [Talking to a local authorization server](auth/client.md#talking-to-a-local-authorization-server) for the one opt-out.
- **No fragment.** An authorization server URL carrying a fragment is refused, because the `state` and
  `code_challenge` this client appends to the authorization endpoint would land in the fragment and never
  reach the server.
- **Same origin.** The Protected Resource Metadata document is read only from the MCP server's own origin,
  which is what binds it to the server it describes. A `resource_metadata` URL that a challenge advertises
  off that origin is dropped, and the well-known URLs are probed in its place. That leg follows the MCP
  server's own scheme, so a loopback MCP server reached over plain HTTP serves its metadata over plain HTTP
  too.
- **No redirects.** On the authorization legs, nothing read from an answer that arrived from a URL other than
  the one it was sent to is trusted. Whenever a token or client secret is attached, the request runs on a
  client that follows no redirect: a `3xx` comes back to the decorator, which refuses it unless the target is
  the MCP server's own origin. The credential therefore never travels to the target, and since an origin
  carries its scheme, a downgrade from `https` to cleartext on the same host is refused like any other hop.
  A request the decorator sent no credential with is left alone, redirects and all.
- **Audience binding.** Server-side, a token whose audience does not name this server is refused. Client-side,
  a token is sent only to the origin it was obtained for.
- **Bearer tokens only.** A token of any other type is refused rather than sent as one.

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

`AuthorizationGrantRejectedException` is raised for the RFC 6749 codes that mean granting again would
help: `invalid_grant` and `invalid_scope`. That is the split the SDK acts
on. A refresh that hits one of those drops the stored token and re-authorizes, while `invalid_client`,
`unsupported_grant_type`, `server_error` and the rest surface to you with the token left alone, because no
number of browser round trips fixes a misconfigured client or an authorization server that is down.

## See also

- [Transports](transports.md): the Streamable HTTP transport both halves attach to.
- [Client API](client.md): the client this decorator sits under.
- [Error handling](error-handling.md): the wider exception model.
