# Capability advertisement

`ServerCapabilities` is derived automatically from what you registered.

| Capability slot | Lit up by |
| --- | --- |
| `tools` | At least one `addTool(...)`, `setToolStore(...)`, or both `tools/list` and `tools/call` `replaceRequestHandler(...)`. |
| `prompts` | At least one `addPrompt(...)`, `setPromptStore(...)`, or both `prompts/list` and `prompts/get` `replaceRequestHandler(...)`. |
| `resources` | At least one `addResource(...)` / `addResourceTemplate(...)`, `setResourceStore(...)` / `setResourceTemplateStore(...)`, or both `resources/list` and `resources/read` `replaceRequestHandler(...)`. |
| `completions` | At least one `addPromptCompletion(...)` / `addResourceTemplateCompletion(...)`, `setCompletionStore(...)`, or `completion/complete` `replaceRequestHandler(...)`. |

`listChanged` and `resources.subscribe` are advertised only when the subscription store says it will honour
that notification type **and** the feature's own store can report its changes (it implements
`ListChangeSourceInterface`, as the built-in in-memory stores do). The store is the single declarer: the
builder asks it what it honours rather than deciding independently, so a capability can never promise more
than an acknowledgement will grant. Advertising either without both is a promise the server cannot keep, and
the conformance suite scores an undelivered `list_changed` as a failure.
