# Keycloak

Keycloak fronts an MCP server well because it supports everything the SDK's client discovers on its own:
authorization-server metadata, anonymous dynamic client registration, and standard `scope` claims.

Configure a realm:

- Create a realm (`mcp` below). Its issuer is `https://kc.example.com/realms/mcp`.
- Enable **Client registration** > **Anonymous access policies** if MCP clients should register themselves.
  Skip this to require pre-registered clients instead.
- Define a client scope (`mcp:use` below) and, through a **mapper**, stamp the MCP server's canonical URI
  into the token's `aud` claim, so the audience binding below has something to bind.

Validate the RS256 tokens against the realm's JWKS, published at
`/realms/mcp/protocol/openid-connect/certs`. The SDK ships no crypto, so bring a JWT library
([firebase/php-jwt](https://github.com/firebase/php-jwt) below):

```php
use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;

final class KeycloakAccessTokenValidator implements AccessTokenValidatorInterface
{
    public function __construct(private readonly CachedKeySet $keys) {}

    public function validate(string $token): ?VerifiedAccessToken
    {
        try {
            $claims = (array) JWT::decode($token, $this->keys);
        } catch (\Exception) {
            return null;
        }

        return new VerifiedAccessToken(
            audience: (array) ($claims['aud'] ?? []),
            scopes: explode(' ', $claims['scope'] ?? ''),
            subject: $claims['sub'] ?? null,
            clientId: $claims['azp'] ?? null,
        );
    }
}
```

Mount it exactly as [Validating tokens](server.md#validating-tokens) shows, and publish a
[protected resource metadata document](server.md#publishing-the-metadata-document) naming the realm issuer
under `authorization_servers`. The SDK client then walks discovery, registration, PKCE, and the token
exchange without Keycloak-specific configuration.

Two gotchas:

- Keycloak identifies the authorizing client in `azp`, not `client_id`.
- A token's `aud` defaults to `account` unless a mapper adds your resource URI. Without the mapper,
  `BearerAuthenticationMiddleware` refuses every token, which is the audience binding doing its job.

All of this exists as a runnable whole in the
[Keycloak end-to-end example](../../examples/keycloak-e2e/README.md): a realm export with the scope,
mapper, and registration policies configured, a compose file that imports it, and the protected
server plus flow-walking client.
