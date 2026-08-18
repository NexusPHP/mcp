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
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\BooleanSchema;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\RequestStateSigner;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\NullLogger;

use function Amp\async;

[$serverSide, $clientSide] = InMemoryTransport::createPair();

$signer = RequestStateSigner::generate();

$server = (new ServerBuilder())
    ->setLogger(new PsrLogger())
    ->setServerInfo(name: 'nexus-input-required-example', version: '0.1.0')
    ->addTool(
        new Tool(
            name: 'deploy',
            inputSchema: [
                'type' => 'object',
                'properties' => ['target' => ['type' => 'string']],
                'required' => ['target'],
            ],
            description: 'Deploys to a target after the user confirms.',
        ),
        static function (?array $args, ServerContext $context) use ($signer): CallToolResult|InputRequiredResult {
            $target = is_string($args['target'] ?? null) ? $args['target'] : 'somewhere';

            if (null === $context->requestState) {
                return new InputRequiredResult(
                    inputRequests: ['confirm' => new ElicitRequest(params: new ElicitRequestFormParams(
                        message: sprintf('Deploy to %s?', $target),
                        requestedSchema: new ElicitRequestedSchema(
                            properties: ['ok' => new BooleanSchema()],
                            required: ['ok'],
                        ),
                    ))],
                    requestState: $signer->sign('awaiting-confirmation'),
                );
            }

            if ($signer->verify($context->requestState) === null) {
                throw new InvalidParamsException($context->requestId, 'The "requestState" failed its integrity check.');
            }

            $answer = $context->inputResponses['confirm'] ?? null;
            $confirmed = $answer instanceof ElicitResult
                && ElicitAction::Accept === $answer->action
                && true === ($answer->content['ok'] ?? null);

            return new CallToolResult(content: [
                new TextContent(text: $confirmed
                    ? sprintf('Deployed to %s.', $target)
                    : 'Deployment cancelled.'),
            ]);
        },
    )
    ->build()
;

$client = (new ClientBuilder())
    ->setLogger(new NullLogger())
    ->setClientInfo(name: 'nexus-input-required-example-client', version: '0.1.0')
    ->build()
;

$serverRun = async(static fn() => $server->run($serverSide));

$client->connect($clientSide);

try {
    $client->discover();

    fwrite(\STDOUT, "=== tools/call deploy (target=production) ===\n");
    $result = $client->callTool(name: 'deploy', arguments: ['target' => 'production']);

    if ($result instanceof InputRequiredResult) {
        foreach ($result->inputRequests ?? [] as $key => $request) {
            if ($request instanceof ElicitRequest && $request->params instanceof ElicitRequestFormParams) {
                fwrite(\STDOUT, sprintf("    server asks (%s): %s\n", $key, $request->params->message));
            }
        }

        fwrite(\STDOUT, "    user accepts: ok=true\n\n");
        fwrite(\STDOUT, "=== tools/call deploy again, answers and requestState attached ===\n");

        $result = $client->callTool(
            name: 'deploy',
            arguments: ['target' => 'production'],
            inputResponses: ['confirm' => new ElicitResult(action: ElicitAction::Accept, content: ['ok' => true])],
            requestState: $result->requestState,
        );
    }

    if ($result instanceof CallToolResult) {
        foreach ($result->content as $block) {
            if ($block instanceof TextContent) {
                fwrite(\STDOUT, sprintf("    result: %s\n", $block->text));
            }
        }
    }
} finally {
    $client->disconnect();
}

$serverRun->await();
