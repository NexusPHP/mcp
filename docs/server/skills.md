# Skills

The skills extension (`io.modelcontextprotocol/skills`, SEP-2640) serves [Agent Skills](https://agentskills.io)
over MCP: directories holding a `SKILL.md` and its supporting files, addressed under the `skill://` scheme. It
ships in `Nexus\Mcp\Extension\Skills`. Like every extension, it is disabled until you enable it explicitly:

```php
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\SkillsServerExtension;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\ServerBuilder;

$server = (new ServerBuilder())
    ->setServerInfo('demo', '1.0.0')
    ->setResourceStore(new ResourceStore())
    ->enableExtension(new SkillsServerExtension(new SkillStore([
        new DirectorySkill('git-workflow', __DIR__.'/skills/git-workflow'),
        new DirectorySkill('acme/billing/refunds', __DIR__.'/skills/refunds'),
    ])))
    ->build();
```

Reading the YAML frontmatter of a `SKILL.md` needs `symfony/yaml`, a suggested package that stays out of the
SDK's own requirements. Both halves of the extension use it, so install it alongside:

```bash
composer require symfony/yaml
```

Enabling the extension advertises the capability and serves three methods:

| Method | Answers with |
| --- | --- |
| `skills/list` | One page of the skills listed by the store, each with its frontmatter and file manifest. |
| `skills/get` | The current entry of one skill, named by the URI of its `SKILL.md`. |
| `resources/directory/read` | The direct children of a skill directory. |

The files themselves travel as ordinary resources. The extension wraps the `resources/list` and `resources/read`
handlers, so every skill file is listed after the server's other resources and read by its `skill://` URI. The
server must therefore serve resources, or `build()` fails: a decorated method needs a handler to wrap. A server
that registers resources already does. One that serves skills alone sets an empty `ResourceStore`, as above.

A client may call these methods without declaring the extension among its own capabilities, unlike the methods
of a [gated extension](extensions.md).

## Skills read from a directory

`DirectorySkill` takes the skill path and the directory that holds the `SKILL.md`. The path is one or more
`/`-separated segments, and it becomes the skill's place in the `skill://` namespace:

| Path | `SKILL.md` URI |
| --- | --- |
| `git-workflow` | `skill://git-workflow/SKILL.md` |
| `acme/billing/refunds` | `skill://acme/billing/refunds/SKILL.md` |

The final segment must equal the `name` in the frontmatter, which makes it a skill name: lowercase letters,
digits and single hyphens, 64 characters at most. The segments before it take letters, digits, `.`, `_`, `~` and
`-`, and start with a letter or a digit. The frontmatter must carry a `name` and a `description`. Construction
fails otherwise.

Every regular file under the directory joins the skill, at any depth, under a URI that mirrors its relative path
with each segment percent-encoded. Two kinds of entry are left out, along with everything beneath them: symbolic
links, so a skill cannot reach outside its directory, and names starting with a dot, so `.git` and `.env` are
never published. The manifest lists each file with its size and SHA-256 digest, the `SKILL.md` first and the
rest in byte order of their URIs.

A skill directory may hold another skill. Its files are supporting files of the skill around it, so they appear
in that manifest. To publish the nested skill in its own right, register it as well, under its path inside the
enclosing skill:

```php
new SkillStore([
    new DirectorySkill('acme', __DIR__.'/skills/acme'),
    new DirectorySkill('acme/refunds', __DIR__.'/skills/acme/refunds'),
]);
```

Both skills then list the shared files, each of which is served once. A store refuses two skills that give one
URI different bytes.

The frontmatter reaches the client as JSON, typed the way YAML resolves it. Quote a value that must arrive exactly
as written: an unquoted `1.0` is sent as the number `1`, and an unquoted timestamp is sent in ISO 8601 form,
which may differ from how it was typed. A host compares the frontmatter parsed from the file against the
frontmatter sent by the server, so a value resolved differently by two YAML parsers makes it refuse the skill.

A host is only required to accept a skill of up to 512 files and 16 MiB. A larger one is still served, and the
store logs a warning for it:

```php
$store = new SkillStore($skills, logger: $logger);
```

The bytes are read once, when the `DirectorySkill` is constructed, and held in memory. An edit on disk reaches
clients after the server process builds the skill again. That keeps the listed digests in step with the bytes
served.

A file whose bytes are valid UTF-8 without a NUL is served as text and reproduced exactly. Anything else is
served as a base64 blob. The MIME type follows the file extension, with `application/octet-stream` as the
fallback.

## Caching and pagination

`SkillStore` takes the page size and the cache policy carried by every result:

```php
use Nexus\Mcp\Core\Schema\Enum\CacheScope;

$store = new SkillStore($skills, pageSize: 20, ttlMs: 60_000, cacheScope: CacheScope::Public);
```

The defaults are a `ttlMs` of `0` and `CacheScope::Private`. On the `resources/list` page where the skill files
join the server's other resources, the result carries the smaller of the two `ttlMs` values and is `public` only
when both listings are.

## Unlisted and generated skills

`skills/list` is not required to enumerate every skill held by a server. Pass a `SkillProviderInterface` as the
store's second argument to serve skills left out of the listing: ones that are too many to list, that depend on
the caller, or whose content is generated per request.

```php
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Extension\Skills\Server\SkillProviderInterface;
use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Server\ServerContext;

final class ReportSkills implements SkillProviderInterface
{
    public function findSkill(string $uri, ServerContext $context): ?Skill
    {
        if ('skill://reports/q3/SKILL.md' !== $uri) {
            return null;
        }

        return new Skill($uri, ['name' => 'q3', 'description' => 'Summarise the third-quarter report.'], Skills::DYNAMIC_RESOURCES);
    }

    public function findFile(string $uri, ServerContext $context): ?SkillFile
    {
        if ('skill://reports/q3/SKILL.md' !== $uri) {
            return null;
        }

        return new SkillFile($uri, renderQuarterlySkill(), 'text/markdown');
    }

    public function findDirectory(string $uri, ServerContext $context): ?array
    {
        return null;
    }
}

$store = new SkillStore($skills, new ReportSkills());
```

The store consults the provider only for a URI outside its own skills, and `resources/read` asks it about
`skill://` URIs alone. Each method returns `null` for a URI not served by the provider: `skills/get` and
`resources/directory/read` then answer `-32602`, and `resources/read` falls through to the server's other
resources.

A `Skill` built with `Skills::DYNAMIC_RESOURCES` is sent with `"resources": "dynamic"`. That tells the host the
content carries no stable digests, so it reads the files without verifying them. Give an entry a list of
`SkillResource` instead when the bytes are stable, and build each digest from the bytes returned by
`findFile()`.

For full control over listing and lookup, implement `SkillStoreInterface` and pass it to `SkillsServerExtension`
in place of `SkillStore`.

## Leaving directory reads out

`resources/directory/read` is the optional part of the extension. It is served by default and advertised as
`directoryRead: true` in the extension's settings. Turn it off when the store cannot enumerate directories:

```php
new SkillsServerExtension($store, directoryRead: false);
```

The method then stays unregistered and answers `-32601`, and the setting is not advertised.

The client half is documented in [Client skills](../client/skills.md), and
[examples/skills-server.php](../../examples/skills-server.php) is a runnable server with a directory skill and a
generated one.
