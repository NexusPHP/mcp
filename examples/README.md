# Examples

Runnable demo servers and clients for the Nexus MCP SDK. Each example is a
runnable PHP script. Run it with `php` directly, through
[MCP Inspector](https://github.com/modelcontextprotocol/inspector), or spawn it
from an MCP-aware client like Claude Desktop / Cursor. Shared setup (the Composer
autoloader, an uncaught-exception handler, and `ExampleLogger`) lives in
[bootstrap.php](bootstrap.php).

| Example | Description | File |
| --- | --- | --- |
| `stdio-server` | Stdio MCP server with two interactive tools (`multi_greet`, `count_down`), one resource, and one prompt, streaming `notifications/progress` mid-execution. | [stdio-server.php](stdio-server.php) |
| `attribute-discovery` | Stdio MCP server assembled from one plain PHP class via `ServerBuilder::register()`: `#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, and `#[AsResourceTemplate]` methods become definitions, with `inputSchema` and prompt arguments inferred from signatures and `@param` docblocks. | [attribute-discovery.php](attribute-discovery.php) |
| `stdio-client` | Stdio MCP client that spawns `stdio-server` as a subprocess and drives it through the typed `Client` API: handshake, `listTools`, `callTool` (with streaming `onProgress`), `readResource`, `listPrompts`. | [stdio-client.php](stdio-client.php) |
| `in-memory` | Runs a server and client in a single process over `InMemoryTransport::createPair()`, with no subprocess. The pattern for embedding a server in a host application or exercising one in tests. | [in-memory.php](in-memory.php) |
| `completions-and-templates` | RFC 6570 templated resources (`users://{userId}`) and `completion/complete` for both a template argument and a prompt argument, server and client in one process. | [completions-and-templates.php](completions-and-templates.php) |
| `capability-aware-client` | Spawns `stdio-server`, prints the negotiated `ServerCapabilities`, and shows `ServerCapabilityNotSupportedException` raised when calling an unadvertised capability (`completion/complete`). | [capability-aware-client.php](capability-aware-client.php) |
| `http-server` | Streamable HTTP MCP server bound to a socket by `amphp/http-server`, behind `SecuredHttpEndpoint`. One tool reports progress (answered as an SSE stream), one declares `x-mcp-header` (validated against the mirrored header). | [http-server.php](http-server.php) |
| `http-client` | Streamable HTTP MCP client driving `http-server` over the network: one POST per message, progress parsed from the SSE stream mid-call, and an argument mirrored into `Mcp-Param-Tenant`. | [http-client.php](http-client.php) |

## Running an example

### MCP Inspector (recommended for poking around)

```bash
npx @modelcontextprotocol/inspector php examples/stdio-server.php
```

Open the URL Inspector prints, click **Connect**, then drive tools, resources,
and prompts from the UI. Change the log level under **Server Notifications →
Logging Level** to see the bridge in action: lower it to `debug` and the SDK's
internal PSR-3 chatter starts streaming into the **Debug Log** pane. Raise it
back to `warning` and the stream quiets.

### Claude Desktop / Cursor / any `mcpServers`-aware client

Drop this snippet into the client's MCP config:

```json
{
    "mcpServers": {
        "nexus-example": {
            "command": "php",
            "args": ["/absolute/path/to/mcp-sdk/examples/stdio-server.php"]
        }
    }
}
```

### Direct CLI (manual JSON-RPC)

```bash
php examples/stdio-server.php
```

The server reads JSON-RPC envelopes from STDIN, one per line, and writes
responses to STDOUT. Useful when scripting end-to-end smoke tests, less useful
for interactive exploration.

### Stdio client driving the server

```bash
php examples/stdio-client.php
```

`stdio-client` spawns `stdio-server` as a subprocess over stdio and exercises it
end-to-end. Progress reports from `count_down` arrive through the `onProgress`
callback while the call is in flight. No external client is
needed. The script is both the driver and its own output.

### In-process examples (no subprocess)

```bash
php examples/in-memory.php
php examples/completions-and-templates.php
```

Both run a server and a client in a single process over `InMemoryTransport::createPair()`,
with the server in a background coroutine. `in-memory` is the minimal embedding
pattern. `completions-and-templates` adds RFC 6570 templated resources and
`completion/complete`.

### Capability-aware client

```bash
php examples/capability-aware-client.php
```

Spawns `stdio-server`, prints the negotiated `ServerCapabilities`, then attempts an
unadvertised capability so you can see the client gate it with
`ServerCapabilityNotSupportedException` before anything reaches the transport.

### Streamable HTTP (two terminals)

```bash
php examples/http-server.php                 # terminal 1, listens on 127.0.0.1:8931
php examples/http-client.php                 # terminal 2
```

Unlike the stdio pair, the client does not spawn the server: an HTTP server is a
long-lived process you start yourself. Pass a different endpoint as the client's
one argument (`php examples/http-client.php http://127.0.0.1:9000/mcp`) to point
it elsewhere. The client probes the port first, so an unreachable endpoint
reports an error instead of waiting on a response that cannot arrive.

The SDK ships no HTTP server of its own. `StreamableHttpServerTransport` is a
PSR-15 handler, and [PsrHttpAdapter.php](PsrHttpAdapter.php) binds it to
`amphp/http-server`, which is a dev dependency of this repo rather than of the
SDK. Swap that adapter for your own framework's PSR-15 entry point and the
server code is unchanged. It also pipes an SSE body frame by frame instead of
buffering it, which is what lets progress reports arrive mid-call.

MCP Inspector can drive the endpoint too. Choose **Streamable HTTP** as the
transport and enter `http://127.0.0.1:8931/mcp`.

## Logs go to STDERR

MCP clients reserve STDOUT for the JSON-RPC stream. The examples write all
diagnostic logs to STDERR via PSR-3. Inspector surfaces this stream under its
**Debug Log** pane regardless of level. `ExampleLogger` (in
[bootstrap.php](bootstrap.php)) filters by a minimum severity before writing,
defaulting to `info`, dropping to `debug` when the `DEBUG` environment variable is
set.
