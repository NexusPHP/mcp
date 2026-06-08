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
use Nexus\Mcp\Core\JsonRpc\SamplingContentDispatcher;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolResultContent;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the client for a `sampling/createMessage` request. The client should
 * inform the user before returning the sampled message, to allow them to inspect the response
 * (human in the loop) and decide whether to allow the server to see it.
 *
 * @phpstan-import-type ContentMember from SamplingMessage
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#createmessageresult
 */
final readonly class CreateMessageResult extends Result implements ClientResult
{
    /**
     * @var non-empty-string
     */
    public string $model;

    /**
     * @var ContentMember|list<ContentMember>
     */
    public array|AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $content;

    /**
     * @var null|non-empty-string
     */
    public ?string $stopReason;

    /**
     * @param ContentMember|list<ContentMember> $content
     */
    public function __construct(
        string $model,
        public Role $role,
        array|AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $content,
        ?string $stopReason = null,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($model)->isNonEmptyString('"result.model" must be a non-empty string.');
        Assert::that($stopReason)->nullOr()->isNonEmptyString('"result.stopReason" must be a non-empty string or null.');

        $this->model = $model;
        $this->content = $content;
        $this->stopReason = $stopReason;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('model', '"result" missing the required "model" key.');
        $model = $data['model'];
        Assert::that($model)->isString('"result.model" must be a string, {type} given.');

        Assert::that($data)->hasOffset('role', '"result" missing the required "role" key.');
        $role = EnumValueValidator::parse(Role::class, $data['role'], '"result.role"');

        Assert::that($data)->hasOffset('content', '"result" missing the required "content" key.');
        Assert::that($data['content'])->isArray('"result.content" must be an object or array, {type} given.');

        if ([] === $data['content'] || array_is_list($data['content'])) {
            Assert::that($data['content'])
                ->values()
                ->isArray('each "result.content" must be an object, {type} given.')
                ->isMap('each "result.content" must be a string-keyed object.')
            ;
            $content = array_map(
                static fn(array $entry): AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent => SamplingContentDispatcher::fromArray($entry, '"result" content'),
                $data['content'],
            );
        } else {
            Assert::that($data['content'])->isMap('"result.content" must be a string-keyed object.');
            $content = SamplingContentDispatcher::fromArray($data['content'], '"result" content');
        }

        $stopReason = $data['stopReason'] ?? null;
        Assert::that($stopReason)->nullOr()->isString('"result.stopReason" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($model, $role, $content, $stopReason, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'model' => $this->model,
            'role' => $this->role->value,
        ];

        if (\is_array($this->content)) {
            $data['content'] = array_map(
                static fn(AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $block): array => $block->toArray(),
                $this->content,
            );
        } else {
            $data['content'] = $this->content->toArray();
        }

        if (null !== $this->stopReason) {
            $data['stopReason'] = $this->stopReason;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        if (\is_array($this->content)) {
            $data['content'] = array_map(
                static fn(AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $block): array => $block->jsonSerialize(),
                $this->content,
            );
        } else {
            $data['content'] = $this->content->jsonSerialize();
        }

        return $data;
    }
}
