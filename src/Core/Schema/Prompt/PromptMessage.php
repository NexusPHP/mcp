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

namespace Nexus\Mcp\Core\Schema\Prompt;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\JsonRpc\ContentBlockDispatcher;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * Describes a message returned as part of a prompt.
 *
 * This is similar to `SamplingMessage`, but also supports the embedding of resources from the MCP server.
 *
 * @implements Arrayable<array{
 *   content: template-type<AudioContent, Arrayable, 'T'>|template-type<EmbeddedResource, Arrayable, 'T'>|template-type<ImageContent, Arrayable, 'T'>|template-type<ResourceLink, Arrayable, 'T'>|template-type<TextContent, Arrayable, 'T'>,
 *   role: 'assistant'|'user',
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#promptmessage
 */
final readonly class PromptMessage implements Arrayable
{
    public function __construct(public Role $role, public AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent $content)
    {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('role', 'prompt message missing the required "role" key.');
        $role = EnumValueValidator::parse(Role::class, $data['role'], 'prompt message "role"');

        Assert::that($data)->hasOffset('content', 'prompt message missing the required "content" key.');
        Assert::that($data['content'])
            ->isArray('prompt message "content" must be an object, {type} given.')
            ->isMap('prompt message "content" must be a string-keyed object.')
        ;

        return new self(
            role: $role,
            content: ContentBlockDispatcher::fromArray($data['content'], 'prompt message "content"'),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'content' => $this->content->toArray(),
            'role' => $this->role->value,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
