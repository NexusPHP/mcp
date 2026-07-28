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
 * The client the conformance referee drives.
 *
 * In client mode the referee is the server: it stands up a mock per scenario,
 * then spawns this process once per scenario with the mock's URL appended as the
 * final argument. Which behaviour to perform is named by an environment
 * variable, so this is a scenario-name to closure registry.
 *
 * Contract, in full:
 *
 * - the server URL arrives as the last argv entry
 * - `MCP_CONFORMANCE_SCENARIO` names the scenario, and is the routing key
 * - `MCP_CONFORMANCE_CONTEXT` carries scenario JSON when there is any
 * - `MCP_CONFORMANCE_PROTOCOL_VERSION` carries the resolved spec version
 * - exit 0 passes, non-zero fails, except where a scenario expects the failure
 *
 * Prefer `./conformance/run-client.sh`, which drives the referee. Running this
 * directly needs the environment set by hand.
 */

require __DIR__.'/bootstrap.php';
require __DIR__.'/HeadlessUserAuthorization.php';

use Amp\Http\Client\HttpClientBuilder;
use Nexus\Assert\Assert;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;

/** @var array<string, Closure(string): void> $scenarios */
$scenarios = [];

$register = static function (string $name, Closure $handler) use (&$scenarios): void {
    $scenarios[$name] = $handler;
};

/** Connects a plain client to the referee's mock, with no authorization. */
$connect = static function (string $serverUrl): Client {
    // The URL arrives from argv, so this is the boundary where it earns its type.
    Assert::that($serverUrl)->isNonEmptyString('The conformance runner must supply a server URL.');

    $client = new ClientBuilder()
        ->setLogger(new ExampleLogger())
        ->setClientInfo(name: 'nexus-mcp-conformance-client', version: '1.0.0')
        ->build()
    ;

    $client->connect(new StreamableHttpClientTransport(
        endpoint: $serverUrl,
        logger: new ExampleLogger(),
        readTimeout: 30.0,
    ));

    return $client;
};

/** @var Closure(): list<array{name: string, arguments: array<string, mixed>}> $toolCallsFromContext */
$toolCallsFromContext = static function (): array {
    $raw = getenv('MCP_CONFORMANCE_CONTEXT');

    if (! is_string($raw) || '' === $raw) {
        return [];
    }

    $parsed = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    $calls = is_array($parsed) && is_array($parsed['toolCalls'] ?? null) ? $parsed['toolCalls'] : [];
    $normalized = [];

    foreach ($calls as $call) {
        if (is_array($call) && is_string($call['name'] ?? null)) {
            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            $normalized[] = ['name' => $call['name'], 'arguments' => $arguments];
        }
    }

    return $normalized;
};

/*
 * Lists the tools, then makes whatever calls the runner asked for. Listing first
 * is load-bearing on the header scenarios: it is what records each tool's
 * `x-mcp-header` bindings, so a later call can mirror them into `Mcp-Param-*`.
 */
$listThenCall = static function (string $serverUrl) use ($connect, $toolCallsFromContext): void {
    $client = $connect($serverUrl);

    try {
        $client->listTools();

        foreach ($toolCallsFromContext() as $call) {
            $client->callTool(name: $call['name'], arguments: $call['arguments']);
        }
    } finally {
        $client->disconnect();
    }
};

$register('tools_call', static function (string $serverUrl) use ($connect, $toolCallsFromContext): void {
    $client = $connect($serverUrl);

    try {
        $client->listTools();
        $calls = $toolCallsFromContext();

        if ([] === $calls) {
            // The mock advertises this tool when the runner supplies no calls of its own.
            $calls = [['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 3]]];
        }

        foreach ($calls as $call) {
            $client->callTool(name: $call['name'], arguments: $call['arguments']);
        }
    } finally {
        $client->disconnect();
    }
});

$register('request-metadata', static function (string $serverUrl) use ($connect): void {
    $client = $connect($serverUrl);

    try {
        $client->discover();
        $client->listTools();
    } finally {
        $client->disconnect();
    }
});

$register('http-standard-headers', $listThenCall);
$register('http-custom-headers', $listThenCall);

$register('http-invalid-tool-headers', static function (string $serverUrl) use ($connect, $toolCallsFromContext): void {
    $client = $connect($serverUrl);

    try {
        $client->listTools();

        // The scenario's point is that one malformed tool definition must not
        // poison the rest of the listing, so a well-formed tool has to be called.
        $calls = $toolCallsFromContext();
        $calls = [] === $calls ? [['name' => 'valid_tool', 'arguments' => []]] : $calls;

        foreach ($calls as $call) {
            $client->callTool(name: $call['name'], arguments: $call['arguments']);
        }
    } finally {
        $client->disconnect();
    }
});

$register('json-schema-ref-no-deref', static function (string $serverUrl) use ($connect): void {
    $client = $connect($serverUrl);

    try {
        // Listing is the whole test: the advertised tool carries a network `$ref`
        // that the client must pass through rather than fetch.
        $client->listTools();
    } finally {
        $client->disconnect();
    }
});

/*
 * The `client_id` the CIMD scenario expects to see. The referee compares the string and never
 * fetches the document, so nothing has to serve it. It is carried by every OAuth scenario because
 * `ClientRegistrar` reaches for it only where the authorization server advertises
 * `client_id_metadata_document_supported`, which makes the priority order it sits in observable.
 */
$clientIdMetadataDocumentUrl = 'https://conformance-test.local/client-metadata.json';

/*
 * Every OAuth scenario runs the same client, and what differs between them lives in the referee's
 * mock authorization server and in the context it supplies. What the client does once it holds a
 * token is the exception, so that part is the argument.
 */
$withAuthorization = static function (Closure $exercise) use ($clientIdMetadataDocumentUrl): Closure {
    return static function (string $serverUrl) use ($exercise, $clientIdMetadataDocumentUrl): void {
        Assert::that($serverUrl)->isNonEmptyString('The conformance runner must supply a server URL.');

        $raw = getenv('MCP_CONFORMANCE_CONTEXT');
        $context = is_string($raw) && '' !== $raw ? json_decode($raw, true, 512, \JSON_THROW_ON_ERROR) : [];
        $context = is_array($context) ? $context : [];

        $clientId = is_string($context['client_id'] ?? null) ? $context['client_id'] : null;
        $clientSecret = is_string($context['client_secret'] ?? null) ? $context['client_secret'] : null;

        $http = new AuthorizedHttpClient(
            $serverUrl,
            new AuthorizationOptions(
                clientName: 'Nexus MCP SDK conformance client',
                redirectUri: 'http://127.0.0.1:8765/callback',
                clientIdMetadataDocumentUrl: $clientIdMetadataDocumentUrl,
                // Supplied only by the scenarios that issue credentials out of band. They name no
                // authorization server, so the registration stays unbound until discovery names one.
                preRegistered: null !== $clientId
                    ? new ClientRegistration(clientId: $clientId, clientSecret: $clientSecret)
                    : null,
                requestOfflineAccess: true,
                // The referee's mock authorization server runs on plain HTTP over
                // loopback, which the spec does not exempt, so the harness opts in.
                allowInsecureLoopback: true,
            ),
            new HeadlessUserAuthorization(),
            HttpClientBuilder::buildDefault(),
            logger: new ExampleLogger(),
        );

        $client = new ClientBuilder()
            ->setLogger(new ExampleLogger())
            ->setClientInfo(name: 'nexus-mcp-conformance-client', version: '1.0.0')
            ->build()
        ;

        $client->connect(new StreamableHttpClientTransport(
            endpoint: $serverUrl,
            client: $http,
            logger: new ExampleLogger(),
            readTimeout: 30.0,
        ));

        try {
            $exercise($client);
        } finally {
            $client->disconnect();
        }
    };
};

$authorize = $withAuthorization(static function (Client $client): void {
    $client->listTools();
});

foreach ([
    'auth/basic-cimd',
    'auth/metadata-default',
    'auth/metadata-var1',
    'auth/metadata-var2',
    'auth/metadata-var3',
    'auth/resource-mismatch',
    'auth/scope-from-www-authenticate',
    'auth/scope-from-scopes-supported',
    'auth/scope-omitted-when-undefined',
    'auth/scope-retry-limit',
    'auth/token-endpoint-auth-basic',
    'auth/token-endpoint-auth-post',
    'auth/token-endpoint-auth-none',
    'auth/pre-registration',
    'auth/offline-access-scope',
    'auth/offline-access-not-supported',
    'auth/iss-supported',
    'auth/iss-not-advertised',
    'auth/iss-supported-missing',
    'auth/iss-wrong-issuer',
    'auth/iss-unexpected',
    'auth/iss-normalized',
    'auth/metadata-issuer-mismatch',
] as $authScenario) {
    $register($authScenario, $authorize);
}

/*
 * Listing needs only the scope the first challenge named. Calling a tool is what the mock guards
 * behind a second one, so the insufficient-scope answer that drives a step-up has to be provoked.
 */
$register('auth/scope-step-up', $withAuthorization(static function (Client $client): void {
    $client->listTools();
    $client->callTool(name: 'test-tool');
}));

/*
 * The resource names its new authorization server only after one request has already succeeded
 * against the old one, so the migration is invisible to a client that stops after the first.
 */
$register('auth/authorization-server-migration', $withAuthorization(static function (Client $client): void {
    $client->listTools();
    $client->listTools();
}));

$arguments = conformanceArguments();
$scenario = getenv('MCP_CONFORMANCE_SCENARIO');
$serverUrl = 1 < count($arguments) ? $arguments[count($arguments) - 1] : '';

if (! is_string($scenario) || '' === $scenario || '' === $serverUrl) {
    fwrite(\STDERR, "Usage: MCP_CONFORMANCE_SCENARIO=<scenario> php conformance/client.php <server-url>\n");
    fwrite(\STDERR, "The conformance runner sets that variable and appends the URL.\n\nRegistered scenarios:\n");

    foreach (array_keys($scenarios) as $name) {
        fwrite(\STDERR, sprintf("  - %s\n", $name));
    }

    exit(1);
}

if (! isset($scenarios[$scenario])) {
    fwrite(\STDERR, sprintf("No handler registered for scenario \"%s\".\n\nRegistered scenarios:\n", $scenario));

    foreach (array_keys($scenarios) as $name) {
        fwrite(\STDERR, sprintf("  - %s\n", $name));
    }

    exit(1);
}

$scenarios[$scenario]($serverUrl);
