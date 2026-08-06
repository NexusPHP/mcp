# Scopes and step-up

Scope selection starts from the `scope` a challenge names, falling back to `defaultScopes` if you declared any,
then to the resource's `scopes_supported`, and omitting the parameter entirely if none of those names anything.
The first two rungs are the spec's. `defaultScopes` is an extra tier the SDK adds so you can avoid asking for
everything a resource happens to advertise when your client only needs part of it. Since servers *should* name
a `scope` in the challenge, `defaultScopes` mostly bites against servers that omit it. Whatever the baseline,
the scopes already granted are unioned on top, and they survive a token being dropped, so re-authorizing never
asks for less than the client already holds.

What comes back is authoritative. A token response that omits `scope` grants exactly what was asked
(RFC 6749 section 3.3), and one that names a narrower `scope` is the server withdrawing the difference: the
remembered set is replaced by the token's scopes rather than merged, so a scope the server has stopped
granting is dropped from the next grant's ask instead of being re-requested forever.

`offline_access` is decided by the client alone: it is stripped from that union and added back only when you
pass `requestOfflineAccess: true` *and* the authorization server lists it, so a resource cannot talk you into
holding a refresh token. That is what gets a refresh token issued, and a refresh token outlives the session, so
asking for one stays your call. Asking for it also sends `prompt=consent`, because a server that can answer
silently from a prior grant generally will, and a silent answer carries no refresh token.

A `403` carrying `error="insufficient_scope"` triggers a step-up. The SDK unions the challenged scopes with
those already granted, so a fresh grant never costs permissions other operations depend on, and retries.
`maxScopeUpgrades` caps how many rounds that may take (default `2`) before the client raises
`InsufficientScopeException` naming the scopes the server wants.

A challenge that names no scope the token is missing, including one that names no scope at all, raises the
same exception rather than retrying. Asking again would produce the same token and the same `403`, and the
only thing the round trip would buy the user is a second consent screen.

Pass `onInsufficientScope: InsufficientScopePolicy::Fail` to be told instead of asked. The SDK then raises
`InsufficientScopeException` immediately, without running discovery or opening a consent screen, which is
what an unattended process usually wants. It is raised for every insufficient-scope answer, before any of
the rounds `Reauthorize` would have attempted. That covers the `403` path only. To refuse every prompt,
including the one a `401` provokes, throw from your `UserAuthorizationInterface` instead.

Through the Streamable HTTP transport, the exception reaches a typed call wrapped: `callTool()` and its
siblings throw `OutboundRequestFailedException`, with the `InsufficientScopeException` and its `required`
scopes as the `previous`:

```php
try {
    $client->callTool(name: 'deploy');
} catch (OutboundRequestFailedException $e) {
    $cause = $e->getPrevious();

    if ($cause instanceof InsufficientScopeException) {
        promptForConsent($cause->required);
    }
}
```

A `401` re-authorizes once, and carries the rejected token's scopes into the new grant for the same reason. A
second `401` on the token that came back is taken as the server's answer and returned to the caller.

Everything that writes the token runs one at a time per `AuthorizedHttpClient`, renewals included. Concurrent requests
that all hit a `401` therefore see one flow: the first runs discovery, registration and the browser round
trip, and the rest take the token it obtained rather than opening a second consent screen, registering a
second client, or racing to redeem one rotating refresh token. A caller that waited its turn only takes
another's token when it covers what that caller was refused for, so a step-up reaching past the running grant
still asks for its own. Two MCP servers never wait on each other. That lock lives on the client, not on the
store, so two `AuthorizedHttpClient` instances built for the *same* MCP server and handed the same token
store can still both authorize. Build one per MCP server.

What discovery finds is kept for the life of the client, so a step-up goes straight back to the token endpoint
instead of re-reading both metadata documents. A `401` drops it again, since a fresh challenge is the one
moment the server gets to name a different authorization server.
