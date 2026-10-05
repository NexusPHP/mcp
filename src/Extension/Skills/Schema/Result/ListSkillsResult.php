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

namespace Nexus\Mcp\Extension\Skills\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Core\Schema\Result\ServerResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;

/**
 * The result returned by the server for a `skills/list` request.
 *
 * @extends PaginatedResult<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   skills: list<template-type<Skill, Arrayable, 'T'>>,
 *   nextCursor?: non-empty-string,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2640-skills-extension.md
 */
final readonly class ListSkillsResult extends PaginatedResult implements ServerResult
{
    /**
     * @param list<Skill> $skills
     */
    public function __construct(
        public array $skills,
        int $ttlMs,
        CacheScope $cacheScope,
        ?Cursor $nextCursor = null,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        Assert::that($skills)
            ->isList('"result.skills" must be a list, non-list array given.')
            ->values()->isInstanceOf(Skill::class)
        ;

        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, nextCursor: $nextCursor, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('skills', '"result" is missing the required "skills" key.');
        Assert::that($data['skills'])
            ->isList('"result.skills" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.skills" entry must be an object, {type} given.')
            ->isMap('each "result.skills" entry must be a string-keyed object.')
        ;
        $skills = array_map(Skill::fromArray(...), $data['skills']);

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" is missing the required "cacheScope" key.');
        Assert::that($data['cacheScope'])->isOneOf(array_column(CacheScope::cases(), 'value'), '"result.cacheScope" must be one of {choices}, {value} given.');
        $cacheScope = CacheScope::from($data['cacheScope']);

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isNonEmptyString('"result.nextCursor" must be a non-empty string, {type} given.');
            $nextCursor = new Cursor(cursor: $raw);
        }

        $meta = new GenericResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->not()->isNonEmptyList('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(skills: $skills, ttlMs: $ttlMs, cacheScope: $cacheScope, nextCursor: $nextCursor, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = self::getResultType();
        $data['skills'] = array_map(static fn(Skill $skill): array => $skill->toArray(), $this->skills);

        if (null !== $this->nextCursor) {
            $data['nextCursor'] = $this->nextCursor->cursor;
        }

        $data['ttlMs'] = $this->ttlMs;
        $data['cacheScope'] = $this->cacheScope->value;

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(
            skills: $this->skills,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $this->nextCursor,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
