# Examples

Runnable demo servers and clients for the Nexus MCP SDK. Each example is a
single self-contained PHP script; run it with `php` directly, through
[MCP Inspector](https://github.com/modelcontextprotocol/inspector), or spawn it
from an MCP-aware client like Claude Desktop / Cursor.

| Example | Description | File |
| --- | --- | --- |
| `stdio-server` | Stdio MCP server with two interactive tools (`multi_greet`, `count_down`), one resource, one prompt, and a custom `logging/setLevel` handler that bridges the client-controlled log level to the server's PSR-3 logger. | [stdio-server.php](stdio-server.php) |
| `stdio-client` | Stdio MCP client that spawns `stdio-server` as a subprocess and drives it through the typed `Client` API: handshake, `listTools`, `callTool` (with streaming `onProgress`), `readResource`, `listPrompts`. Renders the server's log notifications via a registered handler. | [stdio-client.php](stdio-client.php) |

## Running an example

### MCP Inspector (recommended for poking around)

```bash
npx @modelcontextprotocol/inspector php examples/stdio-server.php
```

Open the URL Inspector prints, click **Connect**, then drive tools, resources,
and prompts from the UI. Change the log level under **Server Notifications →
Logging Level** to see the bridge in action: lower it to `debug` and the SDK's
internal PSR-3 chatter starts streaming into the **Debug Log** pane; raise it
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
responses to STDOUT. Useful when scripting end-to-end smoke tests; less useful
for interactive exploration.

### Stdio client driving the server

```bash
php examples/stdio-client.php
```

`stdio-client` spawns `stdio-server` as a subprocess over stdio and exercises it
end-to-end. Progress reports from `count_down` arrive through the `onProgress`
callback while the call is in flight, and the server's log notifications stream
through the registered `notifications/message` handler. No external client is
needed; the script is both the driver and its own output.

## Logs go to STDERR

MCP clients reserve STDOUT for the JSON-RPC stream. The example writes all
diagnostic logs to STDERR via PSR-3. Inspector surfaces this stream under its
**Debug Log** pane regardless of level. The example's logger filters by a
minimum severity before writing, defaulting to `info` and updating live in
response to `logging/setLevel` calls from the client.
