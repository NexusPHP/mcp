# Contributing

Thanks for your interest in improving Nexus MCP SDK. This guide covers local setup, the quality
gates, and the conventions the project follows.

## Requirements

- PHP 8.3 or newer
- [Composer](https://getcomposer.org)

## Setup

Install dependencies with:

```bash
composer update
```

This also installs the isolated tooling under `tools/` (PHP-CS-Fixer, PHPStan, Infection) and the
documentation linters.

## Quality gates

Every change must pass the full gate suite before it is considered done:

```bash
composer test:all
```

During iteration, run the diff-based variant so the mutation step only covers your changes (including
untracked files):

```bash
composer test:with-untracked
```

For faster inner loops, the single-concern scripts are usually enough:

```bash
composer cs:check        # code style
composer phpstan:check   # static analysis (PHPStan level 10)
composer arch:check      # layer boundaries (Server and Client stay independent, both build on Core)
composer deps:check      # composer dependency declarations (shadow/unused deps)
composer test:unit       # or test:client / test:core / test:server
composer coverage:check  # enforce 100% line coverage (after test:unit)
composer lint:docs       # typos, markdownlint
composer lint:fences     # PHP fences in markdown parse on the declared floor
composer bc:check        # public API against the latest stable tag (run `composer update --working-dir=bc` once first)
```

Auto-fix what is fixable:

```bash
composer cs:fix
composer lint:fix
```

## Coding standards

- Target PHP 8.3. Declare `declare(strict_types=1);` in every file.
- The namespace root is `Nexus\Mcp\`, with `Core`, `Server`, and `Client` subnamespaces mirrored by
  the directory layout under `src/`.
- `Core/` holds protocol types only (readonly value objects, enums, interfaces). Behaviour lives in
  `Server/`, `Client/`, and the non-schema `Core/` namespaces.
- PHPStan runs at level 10 with strict rules. Production code must pass without `@phpstan-ignore`.
  Confirmed false positives go in the baseline. Run `composer phpstan:baseline`
- Code style is enforced by PHP-CS-Fixer via Nexus CS Config. Run `composer cs:fix`.
- Classes are final by default unless designed for extension.
- Every SDK exception implements the `McpExceptionInterface` marker, so consumers can catch all SDK
  errors in one block.

## Spec compliance

The SDK tracks MCP spec revision **2026-07-28**. Do not introduce types, params, or response shapes
that are not in the official spec. Schema classes under `src/Core/Schema/` map directly to spec
definitions. If a change touches an envelope or schema payload, cite the spec definition that
justifies it.

## Tests

- Tests mirror `src/` under `tests/` with the `Nexus\Mcp\Tests\` namespace. Shared fixtures live
  under `tests/Fixtures/`.
- New behaviour needs covering tests. The project enforces 100% mutation score (MSI) and 100% code
  coverage via Infection.
- Mutation testing is not part of the inner loop. Run `composer mutation:filter` when you need it, or
  `composer mutation:check` before merging.

## Documentation

After renaming, moving, or adding a public building block (class, interface, method, file), update
the affected files under `docs/` plus `ROADMAP.md`, then run `composer lint:docs`.

Every PHP code sample in the documentation must parse on the PHP floor declared in `composer.json`,
not merely on your local PHP. `composer lint:fences` checks this, and CI enforces it. Set
`PHP_FLOOR_BIN` to a floor binary to run it locally, for example
`PHP_FLOOR_BIN=/usr/bin/php8.3 composer lint:fences`.

## Commits and pull requests

- Write focused commits with clear messages.
- Keep a linear history. The CI rejects merge commits, so rebase instead of merging.
- Open a pull request against the `1.x` branch. Describe what changed and why.
- Make sure `composer test:all` passes before requesting review.

## Code of Conduct

Participation in this project is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).
