# Completions

Completions let a client ask, mid-typing, what values fit a prompt argument or a resource template
variable. Register a provider per argument on the builder:

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

Registering any completion advertises the `completions` capability, and the built-in handler routes
each `completion/complete` request to the provider registered for that exact prompt name (or template
URI) and argument. An unknown pair answers an empty `values` list.

A provider is either a closure of the shape above or a `CompletionProviderInterface` implementation,
whose `complete()` receives the same three inputs: the partial value being typed, the values the client
has already resolved for the other arguments (or `null` when it sent none, so a `city` completion can be
narrowed by the chosen `country`), and the `ServerContext`.

## Attribute sugar

`#[AsCompletion]` marks a method as a provider, discovered through the same
[`ServerBuilder::register()`](../attribute-discovery.md) walk as the other attributes. Name the prompt
or the URI template it completes, and the argument. The attribute repeats, so one method can serve
several arguments:

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

The method's parameters are bound by type: a `ServerContext` parameter receives the context, an `array`
parameter the resolved context arguments (an empty array when the request carries none and the parameter
does not accept null), and any other parameter the partial value, which is why every other parameter must
take a string. A signature that breaks these rules (a variadic, an `int`, an enum) is rejected at
`register()` time rather than on the first request. The method returns either a list of strings (wrapped
into a `CompleteResult` for it) or a `CompleteResult` when it needs `total` or `hasMore`.

## Bringing a whole store

For full control (dynamic lookups, one handler for every ref), implement `CompletionStoreInterface`
and set it wholesale. An explicit store replaces anything the `add*Completion()` methods collected:

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

The spec caps `values` at 100 entries. Set `total` when you know how many exist beyond what you returned,
and `hasMore` when the list is truncated. The client-side counterpart is
[`complete()`](../client/requests.md#typed-requests).
