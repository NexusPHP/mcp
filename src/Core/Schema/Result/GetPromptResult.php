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
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The result returned by the server for a `prompts/get` request.
 *
 * @extends Result<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   description?: non-empty-string,
 *   messages: list<template-type<PromptMessage, Arrayable, 'T'>>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#getpromptresult
 */
final readonly class GetPromptResult extends Result implements ServerResult
{
    /**
     * @var list<PromptMessage>
     */
    public array $messages;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @param list<PromptMessage> $messages
     */
    public function __construct(array $messages, ?string $description = null, ResultMetaObject $meta = new GenericResultMetaObject())
    {
        Assert::that($messages)->isList('"result.messages" must be a list, non-list array given.');

        foreach ($messages as $message) {
            Assert::that($message)->isInstanceOf(PromptMessage::class);
        }

        Assert::that($description)->nullOr()->isNonEmptyString('"result.description" must be a non-empty string or null.');

        $this->messages = $messages;
        $this->description = $description;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('messages', '"result" is missing the required "messages" key.');
        Assert::that($data['messages'])->isArray('"result.messages" must be an array, {type} given.');

        $messages = [];

        foreach ($data['messages'] as $entry) {
            Assert::that($entry)
                ->isArray('"result.message" must be an object, {type} given.')
                ->isMap('"result.message" must be a string-keyed object.')
            ;
            $messages[] = PromptMessage::fromArray($entry);
        }

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('"result.description" must be a string or null, {type} given.');

        $meta = new GenericResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(messages: $messages, description: $description, meta: $meta);
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

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        $data['messages'] = array_map(
            static fn(PromptMessage $message): array => $message->toArray(),
            $this->messages,
        );

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(
            messages: $this->messages,
            description: $this->description,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
