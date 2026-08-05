# MCP Apps end to end

The [MCP Apps extension](../../docs/server/apps.md) with all three parties in play, browser host
included:

- [server.php](server.php): an MCP server over Streamable HTTP. It enables `AppsServerExtension`,
  declares the `ui://apps-example/status-panel` view through the guarded `UiResource` composition,
  links the `system_status` tool to it via `_meta.ui`, and serves the same metadata on both the
  `resources/list` descriptor and the `resources/read` contents by reusing the composed resource's
  meta object. The tool keeps a text fallback, so a host without UI support still gets an answer.
- [host.php](host.php): the host backend. It runs the SDK `Client` with `AppsClientExtension`
  (advertising `mimeTypes` on every request) and the `AppClient` facade
  (`findAppTools()`, `readAppResource()` with mime verification, `resolveResourceMeta()`), and
  exposes a small JSON API to the browser.
- [host.html](host.html): a minimal browser host. It renders the view in a sandboxed iframe
  (`sandbox="allow-scripts"`, CSP composed from the declared `_meta.ui.csp` allow-lists,
  `prefersBorder` honoured), answers the view's `ui/initialize` handshake, delivers
  `ui/notifications/tool-result`, resizes on `ui/notifications/size-changed`, and proxies the
  view's `tools/call` requests through the backend.
- [dashboard.html](dashboard.html): the view itself, served as the `ui://` resource. Hand-written
  JSON-RPC over `postMessage`, no dependencies: it initialises, renders each tool result, and
  refreshes by calling `system_status` through the host.

## Running it

Two terminals, then a browser:

```bash
php examples/apps-e2e/server.php   # MCP server on 127.0.0.1:8941
php examples/apps-e2e/host.php     # host backend on 127.0.0.1:8942
```

Open <http://127.0.0.1:8942>, click **Server status panel**, and the view renders the tool's
`structuredContent`. The **Refresh** button inside the panel is the view calling `tools/call`
through the host's proxy, the round trip the extension exists for.

Both scripts force the production posture the way `conformance/server.php` does: xdebug is
dropped through one `composer/xdebug-handler` restart and `zend.assertions` is lowered at
runtime, since an instrumented process can stall a streaming response long enough to look
broken. Set `MCP_APPS_EXAMPLE_ALLOW_XDEBUG=1` to step-debug either script.

## What is demo-grade here

The PHP halves are the SDK used as documented. The browser side is deliberately minimal and is
not part of the SDK:

- It implements a subset of the `ui/*` postMessage protocol (the `2026-01-26` line): the
  initialise handshake, `tool-input`/`tool-result` delivery, `size-changed`, and the `tools/call`
  proxy. Everything else is answered with `-32601`.
- The CSP rides a `<meta http-equiv>` tag injected into the sandboxed `srcdoc` document. A
  production host serves views from a dedicated origin and enforces CSP as a response header,
  along with the rest of the host obligations the
  [extension spec](https://github.com/modelcontextprotocol/ext-apps) places on it.
- Nothing here runs in CI beyond the repo's normal linters on the PHP files.
