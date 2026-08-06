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

/**
 * An MCP server assembled from attribute-marked methods instead of explicit
 * `addTool()` / `addPrompt()` / `addResource()` calls.
 *
 * `ServerBuilder::register()` reflects each source object: the class-level
 * `#[AsServer]` supplies the server identity and instructions, parameter types and
 * `@param` lines become the tool `inputSchema` and the prompt arguments, a
 * `ServerContext` parameter is injected rather than exposed to the client, and a
 * plain return value (string, content block, or schema object) is adapted to the
 * matching result. Run it the same way as `stdio-server.php`.
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
    /**
     * @param string $city The city to look up.
     * @param string $unit Either "celsius" or "fahrenheit".
     */
    #[AsTool(description: 'Returns a canned weather report for a city.')]
    public function weather(string $city, ServerContext $context, string $unit = 'celsius'): string
    {
        // `ServerContext` is injected rather than exposed to the client, so it never
        // appears in the generated `inputSchema`. Progress is the one out-of-band
        // signal a handler can raise: logging was removed from the protocol at 2026-07-28.
        $context->reportProgress(progress: 1.0, total: 1.0, message: sprintf('Looking up weather for %s.', $city));

        $temperature = 'fahrenheit' === $unit ? '72 °F' : '22 °C';

        return sprintf('It is %s and sunny in %s.', $temperature, $city);
    }

    /**
     * @param string $topic What the haiku should be about.
     */
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

    /**
     * @param string $city The city captured from the URI.
     */
    #[AsResourceTemplate(uriTemplate: 'weather://{city}', name: 'weather_by_city', description: 'Weather for a city addressed by URI.')]
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
