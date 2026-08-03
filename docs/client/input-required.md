# When the server asks for input first

`callTool()`, `readResource()` and `getPrompt()` can answer with an `InputRequiredResult` instead of the
result you asked for: the server needs something from the user before it can finish. Branch on the type
rather than assuming the happy path.

```php
$result = $client->callTool('book_flight', ['destination' => 'Cebu']);

if ($result instanceof InputRequiredResult) {
    // $result->inputRequests is a map of field name to InputRequest describing what to collect.
    // $result->requestState, when present, is opaque and must be echoed back verbatim.
}
```

To answer a tool call, call it again with the collected values and the `requestState` it handed you:

```php
$answered = $client->callTool(
    name: 'book_flight',
    arguments: ['destination' => 'Cebu'],
    inputResponses: ['seat' => new ElicitResult(action: ElicitAction::Accept, content: ['seat' => '14C'])],
    requestState: $result->requestState,
);
```

Keep `arguments` the same as the first call. The server is resuming that request, not being given a new
one, and `requestState` must go back exactly as it arrived: it is opaque, and a server is entitled to
reject a modified one.

`readResource()` and `getPrompt()` answer the same way, with the same two parameters:

```php
$contents = $client->readResource(
    uri: 'file:///report.csv',
    inputResponses: ['passphrase' => new ElicitResult(
        action: ElicitAction::Accept,
        content: ['passphrase' => 'hunter2'],
    )],
    requestState: $result->requestState,
);

$prompt = $client->getPrompt(
    name: 'walkthrough',
    arguments: ['audience' => 'a junior developer'],
    inputResponses: ['confirm_scope' => new ElicitResult(
        action: ElicitAction::Accept,
        content: ['scope' => 'full'],
    )],
    requestState: $result->requestState,
);
```

The same rule applies here: repeat what the first call carried (the `uri`, the `arguments`), since the
server is resuming that request, not being given a new one.
