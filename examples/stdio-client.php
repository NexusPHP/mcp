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

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Psr\Log\NullLogger;

$client = (new ClientBuilder())
    ->setLogger(new NullLogger())
    ->setClientInfo(name: 'nexus-stdio-example-client', version: '0.1.0')
    ->build()
;

$transport = new StdioClientTransport(command: [\PHP_BINARY, __DIR__.'/stdio-server.php']);

$client->connect($transport);

try {
    $discoverResult = $client->discover();

    fwrite(\STDOUT, "=== Discovery ===\n");
    fwrite(\STDOUT, sprintf(
        "Connected to %s v%s (protocol versions: %s)\n\n",
        $discoverResult->meta->serverInfo->name ?? '(anonymous)',
        $discoverResult->meta->serverInfo->version ?? '?',
        implode(', ', $discoverResult->supportedVersions),
    ));

    fwrite(\STDOUT, "=== tools/list ===\n");

    foreach ($client->listTools()->tools as $tool) {
        fwrite(\STDOUT, sprintf("- %s: %s\n", $tool->name, $tool->description ?? '(no description)'));
    }

    fwrite(\STDOUT, "\n=== tools/call count_down (count=3): streaming notifications follow ===\n");
    $countDown = $client->callTool(
        name: 'count_down',
        arguments: ['count' => 3, 'intervalMs' => 150],
        onProgress: static function (float $progress, ?float $total, ?string $message): void {
            $totalText = null !== $total ? sprintf('/%g', $total) : '';
            $messageText = null !== $message ? ' '.$message : '';

            fwrite(\STDOUT, sprintf("    [progress] %g%s%s\n", $progress, $totalText, $messageText));
        },
    );
    fwrite(\STDOUT, sprintf("    result: %s\n\n", renderText($countDown)));

    fwrite(\STDOUT, "=== tools/call multi_greet (name='Paul') ===\n");
    $greeting = $client->callTool(name: 'multi_greet', arguments: ['name' => 'Paul']);
    fwrite(\STDOUT, sprintf("    result: %s\n\n", renderText($greeting)));

    fwrite(\STDOUT, "=== resources/read example://about ===\n");

    $about = $client->readResource('example://about');

    if ($about instanceof ReadResourceResult) {
        foreach ($about->contents as $content) {
            if ($content instanceof TextResourceContents) {
                fwrite(\STDOUT, $content->text."\n");
            }
        }
    }

    fwrite(\STDOUT, "\n=== prompts/list ===\n");

    foreach ($client->listPrompts()->prompts as $prompt) {
        fwrite(\STDOUT, sprintf("- %s: %s\n", $prompt->name, $prompt->description ?? '(no description)'));
    }
} finally {
    $client->disconnect();
}

function renderText(CallToolResult|InputRequiredResult $result): string
{
    if ($result instanceof InputRequiredResult) {
        return '(the server asked for input first)';
    }

    foreach ($result->content as $block) {
        if ($block instanceof TextContent) {
            return $block->text;
        }
    }

    return '(no text content)';
}
