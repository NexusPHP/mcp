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
 * An interactive MCP server speaking line-framed JSON-RPC over STDIN/STDOUT.
 *
 * "Interactive" here means tools that emit server-to-client traffic during
 * execution: `notifications/message` (log lines) and `notifications/progress`
 * (per-step progress reports). Server-initiated requests like
 * `sampling/createMessage` and `elicitation/create` are not yet supported.
 *
 * Spawn from an MCP client (e.g. Claude Desktop) with:
 *
 *     {
 *         "mcpServers": {
 *             "nexus-example": {
 *                 "command": "php",
 *                 "args": ["/absolute/path/to/mcp-sdk/examples/stdio-server.php"]
 *             }
 *         }
 *     }
 */

require __DIR__.'/bootstrap.php';

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

use function Amp\delay;

$logger = new ExampleLogger();

$server = Server::builder()
    ->setLogger($logger)
    ->setServerInfo(
        name: 'nexus-stdio-example',
        version: '0.1.0',
        description: 'An interactive Nexus MCP SDK server demonstrating stdio transport.',
    )
    ->addTool(
        new Tool(
            name: 'multi_greet',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Person to greet.'],
                ],
                'required' => ['name'],
            ],
            description: 'Greets the named person and streams a few log notifications during the work.',
        ),
        static function (?array $args, ServerContext $context): CallToolResult {
            $name = is_string($args['name'] ?? null) ? $args['name'] : 'stranger';

            $context->log(LoggingLevel::Info, sprintf('Preparing greeting for %s...', $name));
            delay(0.2);
            $context->log(LoggingLevel::Info, 'Composing the message...');
            delay(0.2);
            $context->log(LoggingLevel::Info, 'Ready to greet.');

            return new CallToolResult(content: [
                new TextContent(text: sprintf('Hello, %s!', $name)),
            ]);
        },
    )
    ->addTool(
        new Tool(
            name: 'count_down',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                    'intervalMs' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Pause between ticks in milliseconds.'],
                ],
                'required' => ['count'],
            ],
            description: 'Counts down from N to 1, emitting a log notification and a progress report per tick. Pass `progressToken` in `_meta` to observe the progress stream.',
        ),
        static function (?array $args, ServerContext $context): CallToolResult {
            $count = is_int($args['count'] ?? null) ? max(1, min(20, $args['count'])) : 1;
            $intervalMs = is_int($args['intervalMs'] ?? null) ? max(0, $args['intervalMs']) : 250;
            $intervalSeconds = $intervalMs / 1000;

            for ($i = 1; $i <= $count; ++$i) {
                $context->log(LoggingLevel::Info, sprintf('Tick %d of %d', $i, $count));
                $context->reportProgress(
                    progress: (float) $i,
                    total: (float) $count,
                    message: sprintf('%d remaining', $count - $i),
                );

                if ($i < $count && $intervalSeconds > 0) {
                    delay($intervalSeconds);
                }
            }

            return new CallToolResult(content: [
                new TextContent(text: sprintf('Counted down %d ticks.', $count)),
            ]);
        },
    )
    ->addResource(
        new Resource(
            name: 'about',
            uri: 'example://about',
            description: 'A short description of what this example server exposes.',
            mimeType: 'text/markdown',
        ),
        static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(contents: [
            new TextResourceContents(
                uri: $uri,
                text: "# nexus-stdio-example\n\n"
                    ."Two interactive tools:\n\n"
                    ."- `multi_greet(name)` streams log notifications mid-execution.\n"
                    ."- `count_down(count, intervalMs)` streams log + progress notifications.\n\n"
                    ."Watch the client's notification stream while either tool is running.\n",
                mimeType: 'text/markdown',
            ),
        ]),
    )
    ->addPrompt(
        new Prompt(
            name: 'walkthrough',
            description: 'Asks the model to demonstrate the interactive tools to the user.',
            arguments: [
                new PromptArgument(name: 'audience', description: 'Who the walkthrough is being explained to (e.g. "a junior developer").', required: false),
            ],
        ),
        static function (?array $args, ServerContext $context): GetPromptResult {
            $audience = is_string($args['audience'] ?? null) ? $args['audience'] : 'a curious user';

            return new GetPromptResult(messages: [
                new PromptMessage(
                    role: Role::User,
                    content: new TextContent(text: sprintf(
                        'Walk %s through this server. Call `multi_greet` once and `count_down` with `count: 3` once, then describe what notifications they should expect to see streaming in.',
                        $audience,
                    )),
                ),
            ]);
        },
    )
    ->replaceRequestHandler(
        SetLevelRequest::method(),
        new class ($logger) implements RequestHandlerInterface {
            public function __construct(private object $logger)
            {
            }

            #[Override]
            public function handle(JsonRpcRequest $request, AbstractContext $context): EmptyResult
            {
                assert($request instanceof SetLevelRequest);

                $this->logger->setMinLevel($request->params->level);

                return new EmptyResult();
            }
        },
    )
    ->build()
;

$server->run(new StdioServerTransport(logger: $logger));
