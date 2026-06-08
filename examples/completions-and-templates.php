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

/*
 * Demonstrates two server features the other examples skip: RFC 6570 templated
 * resources (`users://{userId}`) and argument completion (`completion/complete`)
 * for both a resource template and a prompt. Server and client run in one
 * process over `InMemoryTransport::createPair()`.
 *
 * Run with:
 *
 *     php examples/completions-and-templates.php
 */

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\Completion\CompletionStore;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\NullLogger;

use function Amp\async;

/** @param list<string> $candidates */
$prefixCompletion = static fn(array $candidates): Closure => static function (string $value) use ($candidates): CompleteResult {
    $matched = array_values(array_filter(
        $candidates,
        static fn(string $candidate): bool => str_starts_with($candidate, $value),
    ));

    return new CompleteResult(['values' => $matched]);
};

[$serverSide, $clientSide] = InMemoryTransport::createPair();

$server = new ServerBuilder()
    ->setLogger(new ExampleLogger())
    ->setServerInfo(name: 'nexus-completions-example', version: '0.1.0')
    ->addResourceTemplate(
        new ResourceTemplate(
            name: 'user',
            uriTemplate: 'users://{userId}',
            description: 'A user record looked up by id.',
            mimeType: 'application/json',
        ),
        static function (string $uri, array $variables, ServerContext $context): ReadResourceResult {
            $userId = $variables['userId'] ?? 'unknown';
            $user = ['id' => $userId, 'name' => ucfirst($userId), 'role' => 'member'];

            return new ReadResourceResult(
                contents: [
                    new TextResourceContents(
                        uri: $uri,
                        text: json_encode($user, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                        mimeType: 'application/json',
                    ),
                ],
                ttlMs: 0,
                cacheScope: CacheScope::Private,
            );
        },
    )
    ->addPrompt(
        new Prompt(
            name: 'greet',
            description: 'Greets someone in a chosen style.',
            arguments: [
                new PromptArgument(name: 'style', description: 'Greeting style.', required: false),
            ],
        ),
        static function (?array $args, ServerContext $context): GetPromptResult {
            $style = is_string($args['style'] ?? null) ? $args['style'] : 'casual';

            return new GetPromptResult(messages: [
                new PromptMessage(
                    role: Role::User,
                    content: new TextContent(text: sprintf('Greet the user in a %s style.', $style)),
                ),
            ]);
        },
    )
    ->setCompletionStore(new CompletionStore(
        promptCompletions: [
            'greet' => ['style' => $prefixCompletion(['casual', 'cheerful', 'formal', 'enthusiastic'])],
        ],
        templateCompletions: [
            'users://{userId}' => ['userId' => $prefixCompletion(['alice', 'alex', 'bob'])],
        ],
    ))
    ->build()
;

$client = new ClientBuilder()
    ->setLogger(new NullLogger())
    ->setClientInfo(name: 'nexus-completions-example-client', version: '0.1.0')
    ->build()
;

$serverRun = async(static fn() => $server->run($serverSide));

$client->connect($clientSide);

try {
    $client->initialize();

    fwrite(\STDOUT, "=== completion/complete for the users://{userId} template (value 'al') ===\n");
    $userIds = $client->complete(
        new ResourceTemplateReference('users://{userId}'),
        ['name' => 'userId', 'value' => 'al'],
    );
    fwrite(\STDOUT, sprintf("    suggestions: %s\n\n", implode(', ', $userIds->completion['values'])));

    fwrite(\STDOUT, "=== resources/read users://alice (matched against the template) ===\n");

    foreach ($client->readResource('users://alice')->contents as $content) {
        if ($content instanceof TextResourceContents) {
            fwrite(\STDOUT, $content->text."\n");
        }
    }

    fwrite(\STDOUT, "\n=== completion/complete for the greet prompt's style argument (value 'c') ===\n");
    $styles = $client->complete(
        new PromptReference('greet'),
        ['name' => 'style', 'value' => 'c'],
    );
    fwrite(\STDOUT, sprintf("    suggestions: %s\n\n", implode(', ', $styles->completion['values'])));

    fwrite(\STDOUT, "=== prompts/get greet (style='formal') ===\n");

    foreach ($client->getPrompt('greet', ['style' => 'formal'])->messages as $message) {
        if ($message->content instanceof TextContent) {
            fwrite(\STDOUT, '    '.$message->content->text."\n");
        }
    }
} finally {
    $client->disconnect();
}

$serverRun->await();
