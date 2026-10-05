# Skills

The client half of the skills extension (`io.modelcontextprotocol/skills`, SEP-2640) pairs two classes.
`SkillsClientExtension` advertises the capability and gates the outbound methods. The `SkillClient` facade
speaks them and verifies what the server returns:

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Extension\Skills\Client\SkillClient;
use Nexus\Mcp\Extension\Skills\Client\SkillsClientExtension;

$client = (new ClientBuilder())
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new SkillsClientExtension())
    ->build();

$client->connect($transport);

$skills = new SkillClient($client);
```

Verifying a `SKILL.md` parses its YAML frontmatter with `symfony/yaml`, installed as the
[server guide](../server/skills.md) shows.

## Finding skills

`listSkills()` returns one page of the skills listed by the server. Each `Skill` carries the frontmatter that
the model needs to decide whether a skill applies, without any file having been read:

```php
$page = $skills->listSkills();

foreach ($page->skills as $skill) {
    printf("%s: %s\n", $skill->frontmatter['name'], $skill->frontmatter['description']);
}

if (null !== $page->nextCursor) {
    $page = $skills->listSkills($page->nextCursor);
}
```

A server may serve skills without listing them. `getSkill()` fetches the current entry of one skill by the URI of
its `SKILL.md`, listed or not:

```php
$skill = $skills->getSkill('skill://acme/billing/refunds/SKILL.md');
```

The facade discovers the server's capabilities on its first call when the client holds none. Both methods throw
`ServerCapabilityNotSupportedException` against a server that did not advertise the extension, before anything
is sent.

## Reading a file

`readSkillFile()` reads one file of a skill through `resources/read` and returns its bytes as a string only once
they match the entry:

```php
use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;

try {
    $instructions = $skills->readSkillFile($skill, $skill->uri);
} catch (SkillVerificationFailedException $e) {
    // The server sent something other than what the entry describes. Do not hand it to the model.
}
```

For a skill whose entry lists its files, the checks run in this order:

1. The URI must be in the manifest. A file not listed by the manifest is refused without being requested.
2. The byte length must equal the listed `size`.
3. The SHA-256 digest of the bytes must equal the listed `digest`.
4. For the `SKILL.md`, the frontmatter in the file must equal the frontmatter in the entry, field by field.

A skill whose `resources` is `"dynamic"` lists no files: its content is generated and carries no digests, so
only the frontmatter check applies.

A file larger than 16 MiB is refused, which bounds a dynamic skill too. A listed file over the limit is refused
before it is requested. Pass a different limit in bytes as the second constructor argument:

```php
$skills = new SkillClient($client, maxFileBytes: 1_048_576);
```

Call `readSkillFile()` when the model actually needs the file. A host must not fetch a skill's files ahead of
use, so do not walk the manifest after `listSkills()`.

A server may ask for input before it serves a file. `readSkillFile()` then returns the `InputRequiredResult`
instead of the bytes. Answer it by calling again with the continuation parameters (see
[answering an `InputRequiredResult`](input-required.md)):

```php
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;

$outcome = $skills->readSkillFile($skill, $skill->uri);

if ($outcome instanceof InputRequiredResult) {
    $outcome = $skills->readSkillFile($skill, $skill->uri, $inputResponses, $outcome->requestState);
}
```

## Reading a directory

`readDirectory()` returns the direct children of a skill directory as resources, a subdirectory carrying the
`inode/directory` MIME type:

```php
$children = $skills->readDirectory('skill://acme/billing/refunds');

foreach ($children->resources as $resource) {
    printf("%s (%s)\n", $resource->uri, $resource->mimeType ?? 'unknown');
}
```

Directory reads are optional for a server. The method throws `ServerCapabilityNotSupportedException` unless the
server advertised the extension with `directoryRead: true`.

[examples/skills-client.php](../../examples/skills-client.php) runs every call above against
[examples/skills-server.php](../../examples/skills-server.php).
