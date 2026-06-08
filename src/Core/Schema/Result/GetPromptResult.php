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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The result returned by the server for a `prompts/get` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#getpromptresult
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
    public function __construct(array $messages, ?string $description = null, MetaObject $meta = new MetaObject())
    {
        Assert::that($messages)->isList('"result.messages" must be a list, non-list array given.');

        foreach ($messages as $message) {
            Assert::that($message)->isInstanceOf(PromptMessage::class);
        }

        Assert::that($description)->nullOr()->isNonEmptyString('"result.description" must be a non-empty string or null.');

        $this->messages = $messages;
        $this->description = $description;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('messages', '"result" missing the required "messages" key.');
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

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($messages, $description, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'messages' => array_map(static fn(PromptMessage $message): array => $message->toArray(), $this->messages),
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
