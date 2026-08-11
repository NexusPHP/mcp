# Capability advertisement

`ServerCapabilities` is derived automatically from what you registered.

| Capability slot | Lit up by |
| --- | --- |
| `tools` | At least one `addTool(...)`, `setToolStore(...)`, or both `tools/list` and `tools/call` `replaceRequestHandler(...)`. |
| `prompts` | At least one `addPrompt(...)`, `setPromptStore(...)`, or both `prompts/list` and `prompts/get` `replaceRequestHandler(...)`. |
| `resources` | At least one `addResource(...)` / `addResourceTemplate(...)`, `setResourceStore(...)` / `setResourceTemplateStore(...)`, or both `resources/list` and `resources/read` `replaceRequestHandler(...)`. |
| `completions` | At least one `addPromptCompletion(...)` / `addResourceTemplateCompletion(...)`, `setCompletionStore(...)`, or `completion/complete` `replaceRequestHandler(...)`. |
| `extensions` | One entry per `enableExtension(...)`, keyed by the extension's identifier with its settings object (`{}` when it has none). Never derived: no enable, no entry. |

`listChanged` is advertised only when the subscription store says it will honour that notification type
**and** the feature's own store can report its changes (it implements `ListChangeSourceInterface`, as the
built-in in-memory stores do). `resources.subscribe` follows the subscription store's flag alone, since
resource updates come from your own `emitResourceUpdated()` calls rather than from a store. The builder
holds the two surfaces to the same set: it asks the store what it honours to derive the capabilities, and
narrows every listen request to the `listChanged` types a change-reporting store backs, so neither a
capability nor an acknowledgement can promise more than the other. Advertising `listChanged` without both
is a promise the server cannot keep, and the conformance suite scores an undelivered `list_changed` as a
failure.
