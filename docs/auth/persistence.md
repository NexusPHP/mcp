# Persisting tokens and registrations

Both stores default to memory, through the shipped `InMemoryTokenStore` and `InMemoryClientRegistrationStore`,
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

## Keys and issuers

Tokens are keyed by the MCP server, and registrations by the issuer. Each `AccessToken` carries the `issuer`
that minted it. That is what makes an authorization server change safe. A token stamped with an issuer the
resource no longer names is dropped rather than presented or refreshed at the new one.

Reading a token back from a store therefore costs one discovery round trip before the first request goes out.
The SDK must not send a token to a server other than the one that issued it, and until discovery has run it
cannot tell. Later requests in the same process present the stored token directly.

A registration the authorization server stops recognising is dropped from the store rather than presented again.
An expired one heals on the next request instead of bricking the client.

Store both confidentially. They are credentials.

## Sharing a store across workers

Grants and renewals run under a lock so that one client never redeems a refresh token twice. That lock is an
`Amp\Sync\Semaphore`, and the default spans one process only. Two workers sharing a persisted token store can
therefore both renew the same token, which an authorization server with reuse detection treats as theft and
answers by revoking the whole grant.

Pass a cross-process semaphore as the `lock` argument of `AuthorizedHttpClient` whenever the store is shared, for
example `Amp\Sync\PosixSemaphore`. Holding it, a worker re-reads the store before renewing, so the second worker
finds the token the first one just obtained and presents it instead.
