# Completions

Completions let a client ask, mid-typing, which values fit a prompt argument or a resource template variable.
Register a provider per argument on the builder:

```php
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\ServerContext;

$builder
    ->addPromptCompletion('review', 'branch', static function (string $value, ?array $context, ServerContext $ctx): CompleteResult {
        $matches = array_values(array_filter(
            ['main', 'develop', 'release/1.x'],
            static fn(string $branch): bool => str_starts_with($branch, $value),
        ));

        return new CompleteResult(completion: ['values' => $matches, 'hasMore' => false]);
    })
    ->addResourceTemplateCompletion('users://{userId}', 'userId', $userIdProvider)
;
```

Registering any completion advertises the `completions` capability. The built-in handler routes each
`completion/complete` request to the provider registered for that exact prompt name (or template URI) and
argument. An unknown pair answers an empty `values` list.

A provider is a closure of the shape above, or a `CompletionProviderInterface` implementation. Its `complete()`
receives the same three inputs: the partial value being typed, the values the client has already resolved for the
other arguments, and the `ServerContext`. The resolved values are `null` when the client sent none. They let a
`city` completion narrow by the chosen `country`.

## Attribute sugar

`#[AsCompletion]` marks a method as a provider. The same [`ServerBuilder::register()`](../attribute-discovery.md)
walk discovers it as the other attributes. Name the prompt or the URI template it completes, and the argument.
The attribute repeats, so one method can serve several arguments:

```php
use Nexus\Mcp\Server\Attribute\AsCompletion;
use Nexus\Mcp\Server\Attribute\AsPrompt;

final class ReviewPrompts
{
    /**
     * @param string $branch The branch to review.
     */
    #[AsPrompt(name: 'review')]
    public function review(string $branch): string
    {
        return "Review the {$branch} branch.";
    }

    #[AsCompletion(argument: 'branch', prompt: 'review')]
    public function completeBranch(string $value): array
    {
        return array_values(array_filter(
            ['main', 'develop', 'release/1.x'],
            static fn(string $branch): bool => str_starts_with($branch, $value),
        ));
    }
}
```

The method's parameters are bound by type. A `ServerContext` parameter receives the context. An `array` parameter
receives the resolved context arguments, as an empty array when the request carries none and the parameter does
not accept null. Any other parameter receives the partial value, which is why every other parameter must take a
string.

A signature that breaks these rules (a variadic, an `int`, an enum) is rejected at `register()` time rather than
on the first request. The method returns a list of strings, which the SDK wraps into a `CompleteResult`, or a
`CompleteResult` when it needs `total` or `hasMore`.

## Bringing a whole store

For full control, such as dynamic lookups or one handler for every ref, implement `CompletionStoreInterface` and
set it wholesale. An explicit store replaces anything the `add*Completion()` methods collected:

```php
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Completion\CompletionStoreInterface;
use Nexus\Mcp\Server\ServerContext;

final class BranchCompletionStore implements CompletionStoreInterface
{
    public function complete(
        PromptReference|ResourceTemplateReference $ref,
        string $argumentName,
        string $argumentValue,
        ?array $contextArguments,
        ServerContext $context,
    ): CompleteResult {
        $matches = [];

        foreach (['main', 'develop', 'release/1.x'] as $branch) {
            if (str_starts_with($branch, $argumentValue)) {
                $matches[] = $branch;
            }
        }

        return new CompleteResult(completion: ['values' => $matches, 'hasMore' => false]);
    }
}
```

```php
->setCompletionStore(new BranchCompletionStore())
```

`$ref` names what is being completed: a `PromptReference` for a prompt argument, or a
`ResourceTemplateReference` for a variable in a resource template's URI.

### The values cap

The spec caps `values` at 100 entries, and the handler enforces it. An over-long list is truncated to 100 with
`hasMore: true`. Unless the provider set its own, `total` is set to the full count. Set `total` yourself when you
know how many values exist beyond what you returned. Set `hasMore` when your own list is already partial. The
client-side counterpart is [`complete()`](../client/requests.md#typed-requests).
