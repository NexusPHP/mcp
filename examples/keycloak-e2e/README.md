# Keycloak end-to-end example

A protected MCP server and a client that starts with nothing (no registration,
no token) and earns its way in. Keycloak runs in a container. The server and
client are the SDK, run on your machine.

What one `php client.php` run walks through:

1. The first request hits the server and is refused with a `401` naming the
   [protected resource metadata](../../docs/auth/server.md) document.
2. The SDK reads it, discovers the realm's authorization-server metadata, and
   registers itself through anonymous Dynamic Client Registration.
3. The authorization-code exchange runs with PKCE. The one leg the SDK cannot
   do itself, a user logging in at the realm's consent page, is played by
   [KeycloakLogin.php](KeycloakLogin.php), which posts the demo credentials to
   the login form headlessly.
4. The minted token comes back bound to the server's canonical URI (stamped
   into `aud` by the realm's mapper) and scoped `mcp:use`. The SDK replays the
   request with it, and the `whoami` tool reports what the validated token
   carried.

## Running it

```console
docker compose -f examples/keycloak-e2e/compose.yaml up --wait
php examples/keycloak-e2e/server.php
```

Then, in a second terminal:

```console
php examples/keycloak-e2e/client.php
```

Expected output ends with something like:

```text
Connected to nexus-keycloak-example v0.1.0 after the authorization flow.
Subject 5f13…, authorized for client 9f19…, with scopes [mcp:use].
```

Tear down with `docker compose -f examples/keycloak-e2e/compose.yaml down -v`.

## The realm

[keycloak/mcp-realm.json](keycloak/mcp-realm.json) is imported on first start
and holds everything the [Keycloak recipe](../../docs/auth/keycloak.md)
describes:

- a `demo` / `demo-password` user,
- an `mcp:use` client scope, granted to every registered client by default,
  whose mappers stamp the MCP server's canonical URI into `aud` and the user
  id into `sub`,
- anonymous client registration open to any host. A production realm lists its
  trusted hosts here instead.

The admin console is at <http://localhost:8080> (`admin` / `admin`) for poking
at what the client's registration actually created.

## CI

The `keycloak-e2e` workflow runs this exact flow on every change to the auth
surfaces, so the example is a regression gate, not documentation that can rot.
