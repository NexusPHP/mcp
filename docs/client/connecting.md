# Connecting and discovery

```php
$client->connect($transport);
$result = $client->discover();
```

`discover()` sends a `server/discover` request and records the server's advertised info and capabilities. It
is optional: a client may call it to learn the server's identity and capabilities, but no discovery is
required before issuing typed requests. After `connect()`, the client can call `listTools()` / `callTool()` /
the other typed methods directly.

`discover()` returns a `DiscoverResult`:

| Field | Type | Notes |
| --- | --- | --- |
| `supportedVersions` | `list<string>` | The protocol revisions the server speaks. |
| `capabilities` | `ServerCapabilities` | Advertised server capabilities. |
| `instructions` | `?string` | Optional model-facing guidance. |
| `ttlMs` / `cacheScope` | `int` / `CacheScope` | Cache hints, inherited from `CacheableResult`. |
| `meta` | `ResultMetaObject` | Carries the server's `Implementation` under `serverInfo`, when the server sends one. |

The server's identity rides the result `_meta` rather than the result body, so `serverInfo` is nullable: a
server may decline to identify itself. The value is self-reported and unverified, so treat it as display and
logging material, never as a behavioural or security signal.

```php
$result = $client->discover();
echo $result->meta->serverInfo?->name ?? '(anonymous)', "\n";
echo 'Protocol versions: ', implode(', ', $result->supportedVersions), "\n";
```

After `discover()`, `getServerInfo()` returns the server's `Implementation` block (name, version, title, …).
It returns `null` before discovery runs, and also when the server identified itself on neither leg.

```php
$info = $client->getServerInfo();
echo $info?->name, ' ', $info?->version;
```

`getServerCapabilities()` returns the server's advertised `ServerCapabilities`, or `null` before discovery
has run for the attached server.
Use it to check what the server supports before issuing a typed request (see
[Typed requests](requests.md#typed-requests)).

```php
if (null !== $client->getServerCapabilities()?->tools) {
    $tools = $client->listTools();
}
```

`connect()` attaches and starts the transport. `disconnect()` is its inverse: it closes the transport and
detaches it (a no-op when not connected), so the client can `connect()` to a new transport afterwards. It
also forgets what the old server advertised, so `getServerInfo()` and `getServerCapabilities()` return
`null` again and the next server is gated on its own `discover()` rather than the last one's.
Calling `connect()` twice, or using the client before `connect()`, throws `LogicException`.

```php
$client->disconnect();
```
