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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ServerResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;

/**
 * The result returned by the server for a `skills/get` request.
 *
 * @extends CacheableResult<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   skill: template-type<Skill, Arrayable, 'T'>,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2640-skills-extension.md
 */
final readonly class GetSkillResult extends CacheableResult implements ServerResult
{
    public function __construct(
        public Skill $skill,
        int $ttlMs,
        CacheScope $cacheScope,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('skill', '"result" is missing the required "skill" key.');
        Assert::that($data['skill'])
            ->isArray('"result.skill" must be an object, {type} given.')
            ->isMap('"result.skill" must be a string-keyed object.')
        ;
        $skill = Skill::fromArray($data['skill']);

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" is missing the required "cacheScope" key.');
        Assert::that($data['cacheScope'])->isOneOf(array_column(CacheScope::cases(), 'value'), '"result.cacheScope" must be one of {choices}, {value} given.');
        $cacheScope = CacheScope::from($data['cacheScope']);

        $meta = new GenericResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->not()->isNonEmptyList('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(skill: $skill, ttlMs: $ttlMs, cacheScope: $cacheScope, meta: $meta);
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
        $data['skill'] = $this->skill->toArray();
        $data['ttlMs'] = $this->ttlMs;
        $data['cacheScope'] = $this->cacheScope->value;

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(skill: $this->skill, ttlMs: $this->ttlMs, cacheScope: $this->cacheScope, meta: $meta);
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
