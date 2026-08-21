# When the server asks for input first

`callTool()`, `readResource()`, and `getPrompt()` can answer with an `InputRequiredResult` instead of the result
you asked for. The server needs something from the user before it can finish. Branch on the type. Do not assume
the happy path.

```php
$result = $client->callTool('book_flight', ['destination' => 'Cebu']);

if ($result instanceof InputRequiredResult) {
    // $result->inputRequests is a map of field name to InputRequest describing what to collect.
    // $result->requestState, when present, is opaque and must be echoed back verbatim.
}
```

## Answering a tool call

To answer, call the tool again with the collected values and the `requestState` it handed you:

```php
$answered = $client->callTool(
    name: 'book_flight',
    arguments: ['destination' => 'Cebu'],
    inputResponses: ['seat' => new ElicitResult(action: ElicitAction::Accept, content: ['seat' => '14C'])],
    requestState: $result->requestState,
);
```

Keep `arguments` the same as in the first call. The server resumes that request. It does not receive a new one.
`requestState` must go back exactly as it arrived. It is opaque, and a server is entitled to reject a modified
one.

## Answering a resource read or a prompt

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

The same rule applies here. Repeat what the first call carried, the `uri` or the `arguments`, since the server
resumes that request rather than receive a new one.
