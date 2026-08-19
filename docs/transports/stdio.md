# Stdio transports

The stdio binding has two classes. `StdioServerTransport` serves line-framed JSON-RPC over
STDIN/STDOUT. `StdioClientTransport` starts a server as a subprocess and speaks the same framing. Both
obey [the transport contract](../transports.md#the-contract).

## `StdioServerTransport`

```php
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Nexus\Mcp\Server\Transport\StdioServerTransport;
use Psr\Log\NullLogger;

$transport = new StdioServerTransport(
    stdin: $stream,          // optional ReadableStream; default: new ReadableResourceStream(\STDIN)
    stdout: $writableStream, // optional WritableStream; default: new WritableResourceStream(\STDOUT)
    logger: $psrLogger,      // optional; default: new NullLogger
    maxLineBytes: 4_194_304, // optional cap on a single inbound line; default 4 MiB
);
```

The `stdin` and `stdout` parameters take `Amp\ByteStream\ReadableStream` and `WritableStream`
implementations, not raw PHP stream resources. The defaults wrap the live process streams:
`new ReadableResourceStream(\STDIN)` and `new WritableResourceStream(\STDOUT)` from
`amphp/byte-stream`.

### Framing

The transport reads one JSON-RPC envelope per line on STDIN. Each `send()` writes one envelope as a
single line that ends in `\n`, and each write is flushed. An inbound line is capped at `$maxLineBytes`
(default 4 MiB). A line that reaches the cap before its `\n` raises a read error and unwinds the loop,
so a peer cannot exhaust memory with an unterminated stream.

### The read loop

`start()` spawns the read loop. The loop parses each line as JSON and answers by shape:

| Inbound line | Result |
| --- | --- |
| Fails to decode | A `-32700 ParseError` response |
| Decodes, but is not a JSON object (JSON-RPC batches included, which the SDK does not accept) | A `-32600 InvalidRequest` response |
| A valid envelope | Emitted to the `onMessage` listeners |

When STDIN closes, the read loop unwinds and its `finally` calls `close()`.

### Close

`close()` is idempotent, and it is the only place `onDrain` fires. Every close path drains exactly
once, a cold `close()` on a never-started transport included. A close runs these steps in order:

1. Wait for the read loop and any side-channel loop to finish.
2. Fire `onDrain`, so the dispatcher can await its pending coroutines.
3. Transition to the `Closed` state.
4. Fire `onClose`.

The state flips only after the drain, so a drain listener that settles its last exchange can still
`send()`. A `close()` from another fiber blocks until the running close settles. A `close()` that
re-enters from a listener or a drained loop returns immediately. After the close, `send()` and
`start()` throw `TransportAlreadyClosedException`.

A concurrent close (for example, EOF on the read loop) can land while a `send()` is suspended in the
byte-stream `write()`. The transport wraps that stream failure into `TransportAlreadyClosedException`
and keeps the original throwable as `getPrevious()`, so callers can demote uniformly. On the same path
it emits a per-message-shape DEBUG log with the request id, the method, and the underlying throwable.
Operators keep a granular audit trail even though the dispatcher reports the symptom at INFO.

### STDOUT discipline

MCP servers MUST NOT write anything to STDOUT outside the JSON-RPC stream. Send all diagnostic logs to
STDERR through the PSR-3 logger you pass in.

### Stdin / stdout substitution

Useful in tests when you want to drive the transport from synthetic streams:

```php
use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\WritableBuffer;

$reader = new BufferedReader(/* … */);
$writer = new WritableBuffer();
$transport = new StdioServerTransport(stdin: $reader, stdout: $writer);
```

## `StdioClientTransport`

```php
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Psr\Log\NullLogger;

$transport = new StdioClientTransport(
    command: ['php', 'examples/stdio-server.php'],  // argv. No shell interpretation
    workingDirectory: null,                          // optional cwd; defaults to current
    env: null,                                       // optional; null prunes to a safe allowlist
    logger: $psrLogger,                              // optional; default: NullLogger
    maxLineBytes: 4_194_304,                         // optional cap; default 4 MiB
);
```

The transport launches an MCP server as a subprocess. It exchanges line-framed JSON-RPC envelopes over
the subprocess's STDIN/STDOUT, with the same framing rules as the server transport: outbound writes go
to the subprocess's stdin, and inbound lines come from its stdout.

### Launch and environment

`start()` runs the command through `Amp\Process\Process`. The first array element is the executable.
The rest are its arguments. There is no shell interpretation, so pass arguments separately to avoid
quoting bugs.

The `env` parameter has three modes:

| Value | Effect |
| --- | --- |
| `null` (default) | Passes a pruned allowlist of safe names (`PATH`, `HOME`, `TERM`, …) from the parent. Everything else is dropped, secrets included, and exported shell-function values are skipped. |
| `[]` | Inherits the full parent environment. |
| A non-empty array | Passed verbatim. |

### The stderr pump

A second pump runs in parallel. It forwards every subprocess stderr line to the logger as
`info('Subprocess stderr: {line}', ['line' => $line])`. Each line is sanitised first: non-printable
bytes are escaped to `\xNN`, and the line is capped at 80 bytes. A hostile subprocess therefore cannot
smuggle control sequences into the logs. An error during the pump logs a warning and does not change
the transport state.

### Close

`close()` closes the subprocess's stdin, which signals EOF. If the subprocess still runs, the transport
sends `SIGKILL`. `SIGTERM` would be preferable, but `amphp/process` runs subprocesses behind a shell
wrapper that ignores `SIGTERM`, so `SIGKILL` is the only signal guaranteed to terminate the child.

### Unexpected exit

The transport implements
[`SupervisableTransportInterface`](../../src/Core/Transport/SupervisableTransportInterface.php).
`onUnexpectedExit(fn (?int $exitCode) => ...)` reports a teardown nobody asked for: the subprocess
exited on its own, or it stopped serving and was killed. Calling `close()` notifies nobody. The
transport is spent once this fires, so a supervisor respawns by building a fresh
`StdioClientTransport`, not by restarting this one.

```php
$transport->onUnexpectedExit(static function (?int $exitCode) use ($logger): void {
    $logger->warning('MCP server died with code {code}.', ['code' => $exitCode ?? 'unknown']);
});
```

The exit code is `null` when the peer ended without reporting a status.
