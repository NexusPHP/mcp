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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the server for a `tools/list` request.
 *
 * @extends PaginatedResult<array{
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 *   tools: list<template-type<Tool, Arrayable, 'T'>>,
 *   nextCursor?: non-empty-string,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#listtoolsresult
 */
final readonly class ListToolsResult extends PaginatedResult implements ServerResult
{
    /**
     * @var list<Tool>
     */
    public array $tools;

    /**
     * @param list<Tool> $tools
     */
    public function __construct(
        array $tools,
        int $ttlMs,
        CacheScope $cacheScope,
        ?Cursor $nextCursor = null,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($tools)
            ->isList('"result.tools" must be a list, non-list array given.')
            ->values()->isInstanceOf(Tool::class)
        ;

        $this->tools = $tools;

        parent::__construct($ttlMs, $cacheScope, $nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('tools', '"result" missing the required "tools" key.');
        Assert::that($data['tools'])
            ->isList('"result.tools" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.tool" must be an object, {type} given.')
            ->isMap('each "result.tool" must be a string-keyed object.')
        ;
        $tools = array_map(Tool::fromArray(...), $data['tools']);

        Assert::that($data)->hasOffset('ttlMs', '"result" missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" missing the required "cacheScope" key.');
        $cacheScope = EnumValueValidator::parse(CacheScope::class, $data['cacheScope'], '"result.cacheScope"');

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('"result.nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor(cursor: $raw);
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(tools: $tools, ttlMs: $ttlMs, cacheScope: $cacheScope, nextCursor: $nextCursor, meta: $meta);
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
        $data['tools'] = array_map(static fn(Tool $tool): array => $tool->toArray(), $this->tools);

        if (null !== $this->nextCursor) {
            $data['nextCursor'] = $this->nextCursor->cursor;
        }

        $data['ttlMs'] = $this->ttlMs;
        $data['cacheScope'] = $this->cacheScope->value;

        return $data;
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
