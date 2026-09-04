# Releasing

The ordered steps for cutting a release. The order is load-bearing: the badges must be regenerated
before the tag, and the changelog heading must exist before the tag, because
[release.yml](workflows/release.yml) fails when the tag's section is missing.

## 1. Verify the tree

Run the full gate on the release commit's parent state:

```bash
composer update
composer test:all
```

CI must be green on `1.x` at the same commit.

## 2. Regenerate the conformance badges

The released README advertises the badge scores, so they must describe the code being tagged:

```bash
composer conformance:server -- --suite all
composer conformance:client -- --suite all
composer conformance:extensions
composer conformance:badge
```

Both modes take the suite selector CI uses, since the badges score the draft and pending scenarios too and
the client referee refuses to run without one.

Commit any badge change before the release-prep commit.

## 3. Review the backward-compatibility report

The report names what to write under `BREAKING_CHANGES.md`, and from 1.0 it decides whether the bump
can be a minor at all:

```bash
composer update --working-dir=bc
composer bc:check
```

It compares committed work against the latest stable tag, so run it before the tag exists. A break is
permitted through `0.x` and must be recorded, not silenced.

Then re-record the parameter names it cannot compare on a final class, and commit any change as
`Record the public parameter names frozen by vX.Y.Z`:

```bash
composer bc:snapshot
```

The snapshot describes the surface this tag freezes, so a symbol added since the last release joins it
here. Renaming one before this point was never a break, and after it is.

## 4. Prepare the changelog commit

One commit, titled `Prepare changelog for vX.Y.Z`:

- In `CHANGELOG.md`, insert `## [vX.Y.Z](https://github.com/NexusPHP/mcp/compare/vPREV...vX.Y.Z) - YYYY-MM-DD`
  under the `## [Unreleased]` heading, with a short intro paragraph, so the unreleased entries move
  under the new heading and `Unreleased` is left empty.
- In `BREAKING_CHANGES.md`, rename `## vPREV to Unreleased` to `## vPREV to vX.Y.Z` and add a fresh
  empty `## vX.Y.Z to Unreleased` above it.
- Run `composer lint:docs` and `composer validate --strict`.

Push the commit and wait for CI.

## 5. Tag

A GPG-signed annotated tag on the prep commit, named exactly like the changelog heading:

```bash
git tag -s vX.Y.Z -m vX.Y.Z
git push origin vX.Y.Z
```

## 6. Publish

The tag push triggers [release.yml](workflows/release.yml), which extracts the tag's
changelog section and creates a **draft** release. Review the draft, then publish it. Packagist syncs
from the published tag, so verify the new version appears on
[packagist.org/packages/nexusphp/mcp](https://packagist.org/packages/nexusphp/mcp).

The same push triggers [split-components.yml](workflows/split-components.yml), which signs the tag with the
maintainer's signing subkey and pushes it to the four component mirrors. Confirm each mirror shows the tag
as **Verified**, and that Packagist lists the version for every component. A mirror Packagist does not list
yet is submitted at <https://packagist.org/packages/submit> with the GitHub hook enabled before its first tag.

## Rotating the split secrets

The `mirrors` environment holds `SPLIT_ACCESS_TOKEN` (a fine-grained token with Contents and Workflows write
access to the four mirrors, the latter because every split carries `.github/workflows/carson.yml`), `SPLIT_GPG_KEY` (an ASCII-armoured signing-only subkey), and `SPLIT_GPG_PASSPHRASE`. The token and
the subkey both expire: renew them there before they lapse, and re-upload the public key to the GitHub account
whenever the subkey is replaced, or the mirror tags stop verifying.
