# Persisting tokens and registrations

Both stores default to memory (the shipped `InMemoryTokenStore` and `InMemoryClientRegistrationStore`),
so a restart authorizes again. Implement the interfaces to outlive the process:

```php
interface TokenStoreInterface
{
    public function read(string $resource): ?AccessToken;
    public function write(string $resource, AccessToken $token): void;
    public function forget(string $resource): void;
}

interface ClientRegistrationStoreInterface
{
    public function read(string $issuer): ?ClientRegistration;
    public function write(string $issuer, ClientRegistration $registration): void;
    public function forget(string $issuer): void;
}
```

Tokens are keyed by the MCP server, and registrations by the issuer. Each `AccessToken` carries the `issuer`
that minted it, which is what makes an authorization server change safe: a token stamped with an issuer the
resource no longer names is dropped rather than presented or refreshed at the new one. Reading a token back
from a store therefore costs one discovery round trip before the first request goes out, because the SDK must
not send a token to a server other than the one that issued it, and until discovery has run it cannot tell.
Later requests in the same process present the stored token directly. A registration the authorization server
stops recognising is dropped from the store rather than presented again, so an expired one heals on the next
request instead of bricking the client. Store both confidentially. They are credentials.
