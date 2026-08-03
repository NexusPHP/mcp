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
 * An MCP client walking the full authorization flow against `server.php`.
 *
 * The first request is refused with a 401. From that one challenge the SDK
 * discovers the realm through the server's metadata document, registers itself
 * anonymously, runs the authorization-code exchange with PKCE (the login form
 * answered headlessly by `KeycloakLogin`), and replays the request with the
 * minted token. Nothing Keycloak-specific is configured on the client.
 *
 * Start Keycloak and `php examples/keycloak-e2e/server.php` first, then run:
 *
 *     php examples/keycloak-e2e/client.php
 */

require __DIR__.'/../bootstrap.php';
require __DIR__.'/KeycloakLogin.php';

use Amp\Http\Client\HttpClientBuilder;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;

const ENDPOINT = 'http://127.0.0.1:8973/mcp';

$http = new AuthorizedHttpClient(
    ENDPOINT,
    new AuthorizationOptions(
        clientName: 'Nexus Keycloak example client',
        redirectUri: 'http://127.0.0.1:8765/callback',
        // Keycloak here runs on cleartext loopback, which the spec does not
        // exempt, so the example opts in. Never set this in production.
        allowInsecureLoopback: true,
    ),
    new KeycloakLogin(username: 'demo', password: 'demo-password'),
    HttpClientBuilder::buildDefault(),
    logger: new ExampleLogger(),
);

$client = new ClientBuilder()
    ->setLogger(new ExampleLogger())
    ->setClientInfo(name: 'nexus-keycloak-example-client', version: '0.1.0')
    ->build()
;

$client->connect(new StreamableHttpClientTransport(
    endpoint: ENDPOINT,
    client: $http,
    logger: new ExampleLogger(),
    readTimeout: 30.0,
));

try {
    $discoverResult = $client->discover();

    fwrite(\STDOUT, sprintf(
        "Connected to %s v%s after the authorization flow.\n",
        $discoverResult->meta->serverInfo->name ?? '(anonymous)',
        $discoverResult->meta->serverInfo->version ?? '?',
    ));

    $identity = $client->callTool(name: 'whoami');

    if (! $identity instanceof CallToolResult) {
        throw new RuntimeException('The whoami tool unexpectedly asked for input.');
    }

    foreach ($identity->content as $block) {
        if ($block instanceof TextContent) {
            fwrite(\STDOUT, $block->text."\n");
        }
    }
} finally {
    $client->disconnect();
}
