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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery;

use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\ServerContext;

final class ReflectedHandlers
{
    public function contextOnly(ServerContext $context): string
    {
        return $context->sessionId ?? 'no-session';
    }

    public function requiredString(string $name): string
    {
        return 'Hello, '.$name;
    }

    public function withDefault(int $count = 3): string
    {
        return str_repeat('x', $count);
    }

    public function twoDefaults(int $count = 3, string $label = 'fallback'): string
    {
        return str_repeat($label, $count);
    }

    public function backedString(BackedStringEnum $color): string
    {
        return $color->value;
    }

    public function backedInt(BackedIntEnum $level): string
    {
        return (string) $level->value;
    }

    public function pureCase(PureEnum $flag): string
    {
        return $flag->name;
    }

    public function objectParam(\stdClass $payload): string
    {
        return implode(',', array_keys(get_object_vars($payload)));
    }

    public function unionParam(int|string $value): string
    {
        return (string) $value;
    }

    public function mixedOrder(string $name, ServerContext $context, int $age = 1): string
    {
        return \sprintf('%s/%s/%d', $name, $context->sessionId ?? 'none', $age);
    }

    public function variadicStrings(string ...$tags): string
    {
        return implode(',', $tags);
    }

    public function variadicEnums(BackedStringEnum ...$colors): string
    {
        return implode(',', array_map(static fn(BackedStringEnum $color): string => $color->value, $colors));
    }

    public function variadicWithLeading(string $prefix, string ...$rest): string
    {
        return $prefix.implode('', $rest);
    }

    public function withCoordinate(Coordinate $point): string
    {
        return \sprintf('%s,%s,%s', $point->latitude, $point->longitude, $point->label->value);
    }

    public function withEmpty(EmptyDto $thing): string
    {
        return $thing::class;
    }

    public function toolResult(): CallToolResult
    {
        return new CallToolResult([new TextContent('done')], isError: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toolStructured(): array
    {
        return ['ok' => true, 'count' => 2];
    }

    /**
     * @return list<int>
     */
    public function toolList(): array
    {
        return [1, 2, 3];
    }

    public function toolUnsupported(): int
    {
        return 7;
    }

    public function toolSingleBlock(): ImageContent
    {
        return new ImageContent('aW1n', 'image/png');
    }

    /**
     * @return list<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent>
     */
    public function toolBlockList(): array
    {
        return [
            new TextContent('t'),
            new ImageContent('aW1n', 'image/png'),
            new AudioContent('YXVk', 'audio/mpeg'),
            new EmbeddedResource(new TextResourceContents('mem://embedded', 'e')),
            new ResourceLink('link', 'mem://link'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toolMapOfBlocks(): array
    {
        return ['main' => new TextContent('x')];
    }

    /**
     * @return array<string, mixed>
     */
    public function toolEmpty(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function toolIntKeyedArray(): array
    {
        return [1 => 'x'];
    }

    public function promptResult(): GetPromptResult
    {
        return new GetPromptResult([new PromptMessage(Role::Assistant, new TextContent('seed'))], 'a description');
    }

    public function promptString(string $topic): string
    {
        return 'Write about '.$topic;
    }

    public function promptUnsupported(): float
    {
        return 1.5;
    }

    public function promptMessage(): PromptMessage
    {
        return new PromptMessage(Role::Assistant, new TextContent('one'));
    }

    /**
     * @return list<PromptMessage>
     */
    public function promptMessageList(): array
    {
        return [
            new PromptMessage(Role::User, new TextContent('a')),
            new PromptMessage(Role::Assistant, new TextContent('b')),
        ];
    }

    /**
     * @return array<string, PromptMessage>
     */
    public function promptMapOfMessages(): array
    {
        return ['x' => new PromptMessage(Role::User, new TextContent('m'))];
    }

    /**
     * @return array<string, mixed>
     */
    public function promptEmpty(): array
    {
        return [];
    }

    /**
     * @return list<int>
     */
    public function promptNonMessageList(): array
    {
        return [1, 2];
    }

    public function resourceResult(): ReadResourceResult
    {
        return new ReadResourceResult([new TextResourceContents('mem://fixed', 'body')], 0, CacheScope::Private);
    }

    public function resourceUri(string $uri): string
    {
        return 'contents of '.$uri;
    }

    public function resourceUnsupported(): bool
    {
        return false;
    }

    public function templatedBinding(string $id, ServerContext $context): string
    {
        return \sprintf('user %s for %s', $id, $context->sessionId ?? 'none');
    }

    public function templatedUri(string $uri, string $id): string
    {
        return $uri.'#'.$id;
    }

    public function templatedResult(string $id): ReadResourceResult
    {
        return new ReadResourceResult([new TextResourceContents('mem://users/'.$id, 'profile')], 0, CacheScope::Private);
    }
}
