<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Nexus\Mcp\Extension\Skills\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Extension\Skills\Skills;

/**
 * One skill served by a server, as described by `skills/list` and `skills/get`.
 *
 * @phpstan-type Frontmatter array{name: non-empty-string, description: non-empty-string, ...<string, mixed>}
 *
 * @implements Arrayable<array{
 *   uri: non-empty-string,
 *   frontmatter: Frontmatter,
 *   resources: 'dynamic'|list<template-type<SkillResource, Arrayable, 'T'>>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/ext-skills/blob/main/specification/stable/skills.mdx
 */
final readonly class Skill implements Arrayable
{
    private const string MANIFEST_SUFFIX = '/'.Skills::MANIFEST_FILENAME;

    /**
     * @param non-empty-string              $uri         URI of the skill's `SKILL.md`
     * @param Frontmatter                   $frontmatter The `SKILL.md` YAML frontmatter, every field of it
     * @param 'dynamic'|list<SkillResource> $resources   Every file of the skill, or `dynamic` for one whose content is generated and carries no digests
     */
    public function __construct(
        public string $uri,
        public array $frontmatter,
        public array|string $resources,
    ) {
        Assert::that($uri)
            ->isNonEmptyString('skill "uri" must be a non-empty string.')
            ->endsWith(self::MANIFEST_SUFFIX, 'skill "uri" must name a "SKILL.md", {value} given.')
        ;
        Assert::that($frontmatter)
            ->isMap('skill "frontmatter" must be a string-keyed object.')
            ->hasOffset('name', 'skill "frontmatter" must carry a "name".')
            ->hasOffset('description', 'skill "frontmatter" must carry a "description".')
        ;
        Assert::that($frontmatter['name'])->isNonEmptyString('skill "frontmatter.name" must be a non-empty string.');
        Assert::that($frontmatter['description'])->isNonEmptyString('skill "frontmatter.description" must be a non-empty string.');

        $rootUri = substr($uri, 0, -\strlen(self::MANIFEST_SUFFIX));
        Assert::that($rootUri)->isNonEmptyString('skill "uri" must name a skill directory before "SKILL.md".');

        $segments = explode('/', $rootUri);
        Assert::that(end($segments))->isIdentical(
            $frontmatter['name'],
            'skill "uri" must end its skill path in the "frontmatter.name" {other}, {value} given.',
        );

        if (Skills::DYNAMIC_RESOURCES === $resources) {
            return;
        }

        Assert::that($resources)
            ->isList('skill "resources" must be a list or "dynamic".')
            ->values()->isInstanceOf(SkillResource::class)
        ;

        $seen = [];

        foreach ($resources as $resource) {
            Assert::that($resource->uri === $uri || str_starts_with($resource->uri, $rootUri.'/'))->isTrue(\sprintf(
                'each skill "resources" entry must name a file under "%s".',
                $rootUri,
            ));
            Assert::that($seen)->not()->hasOffset($resource->uri, 'each skill "resources" entry must name its file once.');

            $seen[$resource->uri] = $resource;
        }

        Assert::that($seen)->hasOffset($uri, 'skill "resources" must list the "SKILL.md" named by the "uri".');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'skill is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isNonEmptyString('skill "uri" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('frontmatter', 'skill is missing the required "frontmatter" key.');
        $frontmatter = $data['frontmatter'];
        Assert::that($frontmatter)
            ->isArray('skill "frontmatter" must be an object, {type} given.')
            ->isMap('skill "frontmatter" must be a string-keyed object.')
            ->hasOffset('name', 'skill "frontmatter" is missing the required "name" key.')
            ->hasOffset('description', 'skill "frontmatter" is missing the required "description" key.')
        ;
        Assert::that($frontmatter['name'])->isNonEmptyString('skill "frontmatter.name" must be a non-empty string, {type} given.');
        Assert::that($frontmatter['description'])->isNonEmptyString('skill "frontmatter.description" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('resources', 'skill is missing the required "resources" key.');
        $resources = $data['resources'];

        if (Skills::DYNAMIC_RESOURCES === $resources) {
            return new self(uri: $uri, frontmatter: $frontmatter, resources: $resources);
        }

        Assert::that($resources)
            ->isList('skill "resources" must be a list or "dynamic", {type} given.')
            ->values()
            ->isArray('each skill "resources" entry must be an object, {type} given.')
            ->isMap('each skill "resources" entry must be a string-keyed object.')
        ;

        return new self(uri: $uri, frontmatter: $frontmatter, resources: array_map(SkillResource::fromArray(...), $resources));
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'frontmatter' => $this->frontmatter,
            'resources' => \is_array($this->resources)
                ? array_map(static fn(SkillResource $resource): array => $resource->toArray(), $this->resources)
                : $this->resources,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
