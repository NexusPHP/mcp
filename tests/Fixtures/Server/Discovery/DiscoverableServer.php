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

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\ServerContext;

/**
 * Source object whose attribute-marked methods exercise every `AttributeScanner` branch.
 */
final class DiscoverableServer
{
    #[AsTool(description: 'Adds two integers.')]
    public function add(int $a, int $b): string
    {
        return (string) ($a + $b);
    }

    /**
     * @param string $name Who to greet.
     */
    #[AsTool(
        name: 'greet_user',
        title: 'Greeter',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: ['type' => 'object', 'properties' => ['greeting' => ['type' => 'string']]],
        meta: ['category' => 'social'],
    )]
    public function greet(string $name, ServerContext $context): CallToolResult
    {
        return new CallToolResult(content: [new TextContent(text: \sprintf('Hello, %s!', $name))]);
    }

    /**
     * @param string $topic The subject to write about.
     * @param string $tone  The desired tone.
     */
    #[AsPrompt(name: 'compose', description: 'Composes a message.', meta: ['audience' => 'writers'])]
    public function compose(string $topic, string $tone = 'neutral'): string
    {
        return \sprintf('Write about %s in a %s tone.', $topic, $tone);
    }

    #[AsPrompt]
    public function outline(string $subject, ServerContext $context): GetPromptResult
    {
        return new GetPromptResult(messages: [new PromptMessage(role: Role::User, content: new TextContent(text: $subject))]);
    }

    #[AsPrompt(name: 'ping_prompt')]
    public function pingPrompt(ServerContext $context): string
    {
        return 'pong';
    }

    /**
     * @param string $label A label to echo back.
     */
    #[AsPrompt(name: 'labelled')]
    public function labelled(ServerContext $context, string $label): string
    {
        return $label;
    }

    #[AsResource(
        uri: 'config://app',
        name: 'app_config',
        title: 'App Config',
        description: 'Application configuration.',
        mimeType: 'application/json',
        annotations: new Annotations(priority: 0.5),
        size: 128,
        meta: ['cacheable' => true],
    )]
    public function appConfig(string $uri): string
    {
        return '{"debug":false}';
    }

    /**
     * @param non-empty-string $uri
     */
    #[AsResource(uri: 'config://defaults')]
    public function defaults(string $uri): ReadResourceResult
    {
        return new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'defaults')], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    /**
     * @param string $id The user id.
     */
    #[AsResourceTemplate(
        uriTemplate: 'users://{id}',
        name: 'user_profile',
        description: 'A user profile.',
        annotations: new Annotations(priority: 0.7),
        meta: ['versioned' => true],
    )]
    public function userProfile(string $uri, string $id): string
    {
        return \sprintf('profile %s at %s', $id, $uri);
    }

    /**
     * @param non-empty-string $uri
     */
    #[AsResourceTemplate(uriTemplate: 'files://{path}')]
    public function fileTemplate(string $uri): ReadResourceResult
    {
        return new ReadResourceResult(contents: [new TextResourceContents(uri: $uri, text: 'file')], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function helper(): string
    {
        return self::hidden();
    }

    #[AsTool(name: 'hidden')]
    private static function hidden(): string
    {
        return 'not discoverable';
    }
}
