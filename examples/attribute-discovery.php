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

use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsServer;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

#[AsServer(
    name: 'nexus-attribute-example',
    version: '0.1.0',
    description: 'A Nexus MCP SDK server assembled through attribute discovery.',
    instructions: 'Ask for the weather, request a haiku, or read the about resource.',
)]
final class Concierge
{
    #[AsTool(description: 'Returns a canned weather report for a city.')]
    public function weather(string $city, ServerContext $context, string $unit = 'celsius'): string
    {
        $context->reportProgress(progress: 1.0, total: 1.0, message: sprintf('Looking up weather for %s.', $city));

        $temperature = 'fahrenheit' === $unit ? '72 °F' : '22 °C';

        return sprintf('It is %s and sunny in %s.', $temperature, $city);
    }

    #[AsPrompt(name: 'haiku', description: 'Asks the model to compose a haiku.')]
    public function haiku(string $topic): GetPromptResult
    {
        return new GetPromptResult(messages: [
            new PromptMessage(
                role: Role::User,
                content: new TextContent(text: sprintf('Write a haiku about %s.', $topic)),
            ),
        ]);
    }

    #[AsResource(uri: 'concierge://about', description: 'About this server.', mimeType: 'text/plain')]
    public function about(string $uri): string
    {
        return 'A Nexus MCP SDK example server built entirely from attribute-marked methods.';
    }

    #[AsResourceTemplate(
        uriTemplate: 'weather://{city}',
        name: 'weather_by_city',
        description: 'Weather for a city addressed by URI.',
    )]
    public function weatherResource(string $uri, string $city): string
    {
        return sprintf('Weather report resource for %s (%s).', $city, $uri);
    }
}

$logger = new PsrLogger();
$server = (new ServerBuilder())
    ->setLogger($logger)
    ->register(new Concierge())
    ->build()
;

$server->run(new StdioServerTransport(logger: $logger));
