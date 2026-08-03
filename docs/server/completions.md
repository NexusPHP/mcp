# Completions

Completions let a client ask, mid-typing, what values fit a prompt argument or a resource template
variable. They are served entirely by a store you provide:

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

Registering the store advertises the `completions` capability, and the built-in handler consults it on
every `completion/complete` request.

`$ref` names what is being completed: a `PromptReference` for a prompt argument, or a
`ResourceTemplateReference` for a variable in a resource template's URI. `$argumentName` and
`$argumentValue` carry the argument being typed and its partial value. `$contextArguments` carries the
values the client has already resolved for the other arguments, or `null` when it sent none, so a store can
narrow one argument by another (a `city` completion filtered by the chosen `country`, say).

The spec caps `values` at 100 entries. Set `total` when you know how many exist beyond what you returned,
and `hasMore` when the list is truncated. The client-side counterpart is
[`complete()`](../client/requests.md#typed-requests).
