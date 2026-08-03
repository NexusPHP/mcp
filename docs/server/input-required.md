# Asking the client for input

A `tools/call`, `prompts/get` or `resources/read` handler that cannot finish without something from the
client returns an `InputRequiredResult` instead of its normal result. The client fulfils the requests it
names and calls the same method again, carrying its answers.

```php
$signer = new RequestStateSigner($secretFromConfig);

$builder->addTool(
    tool: new Tool(name: 'deploy', inputSchema: ['type' => 'object']),
    executor: static function (?array $args, ServerContext $context) use ($signer): CallToolResult|InputRequiredResult {
        $answer = $context->inputResponses['confirm'] ?? null;

        if (! $answer instanceof ElicitResult || ElicitAction::Accept !== $answer->action) {
            return new InputRequiredResult(
                inputRequests: ['confirm' => new ElicitRequest(params: new ElicitRequestFormParams(
                    message: 'Deploy to production?',
                    requestedSchema: new ElicitRequestedSchema(
                        properties: ['ok' => new BooleanSchema()],
                        required: ['ok'],
                    ),
                ))],
                requestState: $signer->sign('awaiting-confirmation'),
            );
        }

        return new CallToolResult(content: [new TextContent(text: 'Deployed.')]);
    },
)
```

Three things are worth knowing before you write one.

**The client resends only the newest round's answers.** Anything an earlier round learned has to travel in
`requestState`, which is why a multi-round handler reads its position from the state rather than from an
accumulated `inputResponses` map.

**`requestState` is opaque to the client and unverified on arrival.** The spec has the client echo it back
untouched, so a hostile one may echo back something else entirely. `RequestStateSigner` mints and checks it:

```php
// Minting, when the handler asks for input.
$state = $signer->sign('awaiting-confirmation');

// Checking, when the call comes back.
$payload = $signer->verify($context->requestState)
    ?? throw new InvalidParamsException($context->requestId, 'The "requestState" failed its integrity check.');
```

`verify()` returns the payload it signed, or `null` for a state this server did not mint. Distinguish that
from a first call, where `$context->requestState` is `null` and the handler should ask rather than reject.
`RequestStateSigner::generate()` draws a key for a server whose states never outlive its own process. Anything
longer-lived wants a key from configuration, so a state survives a restart or a second instance behind a load
balancer.

The payload is signed, not encrypted, and travels in the clear: put a continuation marker in it, never a
secret. Expiry is not built in either. Encode a timestamp in the payload and check it after `verify()` if a
state should go stale.

**Only elicitation is available to ask for.** The spec's `InputRequest` union also admits
`sampling/createMessage` and `roots/list`, but both are deprecated as of 2026-07-28 (SEP-2577) and this SDK
does not model them, so `ElicitRequest` is the only thing a handler can put in `inputRequests`.

## The field vocabulary

`ElicitRequestedSchema` describes a flat form. Each property is one of the primitive field schemas, every
one carrying an optional `title`, `description`, and `default` the client applies when the user leaves the
field alone:

```php
use Nexus\Mcp\Core\Schema\Elicitation\NumberSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Elicitation\UntitledSingleSelectEnumSchema;

new ElicitRequestedSchema(
    properties: [
        'environment' => new UntitledSingleSelectEnumSchema(
            enum: ['staging', 'production'],
            default: 'staging',
        ),
        'replicas' => new NumberSchema(type: 'integer', minimum: 1, maximum: 12, default: 2),
        'reason' => new StringSchema(maxLength: 200),
    ],
    required: ['environment'],
)
```

`StringSchema` constrains by `minLength` / `maxLength` / `format`, `NumberSchema` by `minimum` / `maximum`
(pass `type: 'integer'` for whole numbers), and `BooleanSchema` is the yes/no field. Choice fields come in
single- and multi-select variants, untitled (the value list is what the user sees) and titled (each value
pairs with a display label via `EnumOption`). Nested objects are not part of the vocabulary: clients render
flat forms.

The answers come back untyped. A client conforming to the spec applies defaults and honours the
constraints, but the handler still owns the trust boundary, so read each field from
`$context->inputResponses` defensively, as the example above does with `$answer instanceof ElicitResult`.

## URL mode

A form is one of two elicitation modes. `ElicitRequestUrlParams` instead sends the user to a URL the
server names (a checkout page, a device-authorization screen), and the client answers once the visit is
done:

```php
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;

new ElicitRequest(params: new ElicitRequestUrlParams(
    message: 'Complete the payment to continue.',
    mode: 'url',
    url: 'https://pay.example.com/session/8f3a',
))
```

A client declares which modes it supports under its `elicitation` capability (`form`, `url`, or both), and
the request's `_meta` carries that declaration per request, so check
`$context->meta->clientCapabilities->elicitation` before choosing a mode.

[`conformance/MultiRoundServer.php`](../../conformance/MultiRoundServer.php) is the worked example, covering a
single round, a signed continuation token, a two-question sequence, and the same flow on a prompt.
