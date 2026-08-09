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
 * The client the conformance referee drives, best run through `./conformance/run-client.sh`,
 * which sets up its contract:
 *
 * - the server URL arrives as the last argv entry
 * - `MCP_CONFORMANCE_SCENARIO` names the scenario, and is the routing key
 * - `MCP_CONFORMANCE_CONTEXT` carries scenario JSON when there is any
 * - `MCP_CONFORMANCE_PROTOCOL_VERSION` carries the resolved spec version
 * - exit 0 passes, non-zero fails, except where a scenario expects the failure
 */

require __DIR__.'/bootstrap.php';
require __DIR__.'/HeadlessUserAuthorization.php';
require __DIR__.'/StaticIdentityAssertionProvider.php';

use Amp\Http\Client\HttpClientBuilder;
use Nexus\Assert\Assert;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\ClientExtensionInterface;
use Nexus\Mcp\Client\Exception\AuthorizationServerMismatchException;
use Nexus\Mcp\Client\Exception\ClientRegistrationRequiredException;
use Nexus\Mcp\Client\Exception\InsecureAuthorizationEndpointException;
use Nexus\Mcp\Client\Exception\InsufficientScopeException;
use Nexus\Mcp\Client\Exception\InvalidAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\PkceNotSupportedException;
use Nexus\Mcp\Client\Exception\RedirectRefusedException;
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Schema\Elicitation\BooleanSchema;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Elicitation\NumberSchema;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsClientExtension;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsGrant;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientSecretCredential;
use Nexus\Mcp\Extension\Auth\ClientCredentials\PrivateKeyJwtCredential;
use Nexus\Mcp\Extension\Auth\Enterprise\EnterpriseAuthorizationClientExtension;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionGrant;

/** @var array<string, Closure(string): void> $scenarios */
$scenarios = [];

$register = static function (string $name, Closure $handler) use (&$scenarios): void {
    $scenarios[$name] = $handler;
};

$connect = static function (string $serverUrl): Client {
    Assert::that($serverUrl)->isNonEmptyString('The conformance runner must supply a server URL.');

    $client = (new ClientBuilder())
        ->setLogger(new PsrLogger())
        ->setClientInfo(name: 'nexus-mcp-conformance-client', version: '1.0.0')
        ->build()
    ;

    $client->connect(new StreamableHttpClientTransport(
        endpoint: $serverUrl,
        logger: new PsrLogger(),
        readTimeout: 30.0,
    ));

    return $client;
};

/** @var Closure(): list<array{name: non-empty-string, arguments: array<string, mixed>}> $toolCallsFromContext */
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

// Lists the tools before calling them, since listing is what records each tool's `x-mcp-header` bindings.
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
        $client->listTools();
    } finally {
        $client->disconnect();
    }
});

$register('json-schema-2020-12-preservation', static function (string $serverUrl) use ($connect): void {
    $client = $connect($serverUrl);

    try {
        $schema = null;

        foreach ($client->listTools()->tools as $tool) {
            if ('json_schema_2020_12_tool' === $tool->name) {
                $schema = $tool->inputSchema;

                break;
            }
        }

        if (null === $schema) {
            throw new RuntimeException('The mock server did not advertise "json_schema_2020_12_tool".');
        }

        $client->callTool(name: 'json_schema_echo', arguments: ['schema' => $schema]);
    } finally {
        $client->disconnect();
    }
});

$acceptInputRequests = static function (InputRequiredResult $result): array {
    $responses = [];

    foreach ($result->inputRequests ?? [] as $name => $request) {
        if (! $request instanceof ElicitRequest || ! $request->params instanceof ElicitRequestFormParams) {
            throw new RuntimeException(sprintf('The "%s" input request is not a form elicitation.', $name));
        }

        $content = [];

        foreach ($request->params->requestedSchema->properties as $field => $definition) {
            $content[$field] = match (true) {
                $definition instanceof BooleanSchema => true,
                $definition instanceof NumberSchema => 1,
                default => 'conformance',
            };
        }

        $responses[$name] = new ElicitResult(ElicitAction::Accept, $content);
    }

    return $responses;
};

$register('sep-2322-client-request-state', static function (string $serverUrl) use ($connect, $acceptInputRequests): void {
    $client = $connect($serverUrl);

    $demandInput = static function (CallToolResult|InputRequiredResult $result, string $tool): InputRequiredResult {
        if (! $result instanceof InputRequiredResult) {
            throw new RuntimeException(sprintf('The "%s" tool answered without asking for input.', $tool));
        }

        return $result;
    };

    try {
        $client->listTools();

        $stateful = $demandInput($client->callTool(name: 'test_mrtr_echo_state'), 'test_mrtr_echo_state');

        // Between the two rounds, so a call of its own proves the pending round leaks nothing into it.
        $client->callTool(name: 'test_mrtr_unrelated');
        $client->callTool(
            name: 'test_mrtr_echo_state',
            inputResponses: $acceptInputRequests($stateful),
            requestState: $stateful->requestState,
        );

        $stateless = $demandInput($client->callTool(name: 'test_mrtr_no_state'), 'test_mrtr_no_state');
        $client->callTool(
            name: 'test_mrtr_no_state',
            inputResponses: $acceptInputRequests($stateless),
            requestState: $stateless->requestState,
        );

        $client->callTool(name: 'test_mrtr_no_result_type');
    } finally {
        $client->disconnect();
    }
});

// The `client_id` the CIMD scenario expects, compared as a string and never fetched, so nothing serves it.
$clientIdMetadataDocumentUrl = 'https://conformance-test.local/client-metadata.json';

$scenarioContext = static function (): array {
    $raw = getenv('MCP_CONFORMANCE_CONTEXT');
    $context = is_string($raw) && '' !== $raw ? json_decode($raw, true, 512, \JSON_THROW_ON_ERROR) : [];

    return is_array($context) ? $context : [];
};

$preRegisteredFromContext = static function (array $context): ?ClientRegistration {
    $clientId = is_string($context['client_id'] ?? null) ? $context['client_id'] : null;
    $clientSecret = is_string($context['client_secret'] ?? null) ? $context['client_secret'] : null;

    return null !== $clientId ? new ClientRegistration(clientId: $clientId, clientSecret: $clientSecret) : null;
};

$runAuthorized = static function (
    string $serverUrl,
    AuthorizedHttpClient $http,
    Closure $exercise,
    ?ClientExtensionInterface $extension = null,
): void {
    Assert::that($serverUrl)->isNonEmptyString('The conformance runner must supply a server URL.');

    $builder = (new ClientBuilder())
        ->setLogger(new PsrLogger())
        ->setClientInfo(name: 'nexus-mcp-conformance-client', version: '1.0.0')
    ;

    if (null !== $extension) {
        $builder = $builder->enableExtension($extension);
    }

    $client = $builder->build();

    $client->connect(new StreamableHttpClientTransport(
        endpoint: $serverUrl,
        client: $http,
        logger: new PsrLogger(),
        readTimeout: 30.0,
    ));

    try {
        $exercise($client);
    } finally {
        $client->disconnect();
    }
};

$withAuthorization = static function (Closure $exercise) use ($clientIdMetadataDocumentUrl, $scenarioContext, $preRegisteredFromContext, $runAuthorized): Closure {
    return static function (string $serverUrl) use ($exercise, $clientIdMetadataDocumentUrl, $scenarioContext, $preRegisteredFromContext, $runAuthorized): void {
        $http = new AuthorizedHttpClient(
            $serverUrl,
            new AuthorizationOptions(
                clientName: 'Nexus MCP SDK conformance client',
                redirectUri: 'http://127.0.0.1:8765/callback',
                clientIdMetadataDocumentUrl: $clientIdMetadataDocumentUrl,
                preRegistered: $preRegisteredFromContext($scenarioContext()),
                requestOfflineAccess: true,
                // The referee's mock authorization server runs on plain HTTP over loopback, so the harness opts in.
                allowInsecureLoopback: true,
            ),
            new HeadlessUserAuthorization(),
            new HttpClientBuilder(),
            logger: new PsrLogger(),
        );

        $runAuthorized($serverUrl, $http, $exercise);
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

// The mock guards the tool call behind a second scope, so calling one provokes the step-up listing never would.
$register('auth/scope-step-up', $withAuthorization(static function (Client $client): void {
    $client->listTools();
    $client->callTool(name: 'test-tool');
}));

// The resource names its new authorization server only after one request has succeeded against the old one.
$register('auth/authorization-server-migration', $withAuthorization(static function (Client $client): void {
    $client->listTools();
    $client->listTools();
}));

/** @var Closure(array<array-key, mixed>, string): non-empty-string $contextString */
$contextString = static function (array $context, string $key): string {
    $value = $context[$key] ?? null;
    Assert::that($value)->isNonEmptyString(sprintf('The scenario context must carry a non-empty "%s".', $key));

    return $value;
};

$withGrantStrategy = static function (
    ClientCredentialsGrant|IdentityAssertionGrant $strategy,
    ClientExtensionInterface $extension,
    ?ClientRegistration $preRegistered = null,
) use ($runAuthorized): Closure {
    return static function (string $serverUrl) use ($strategy, $extension, $preRegistered, $runAuthorized): void {
        $http = new AuthorizedHttpClient(
            $serverUrl,
            new AuthorizationOptions(
                clientName: 'Nexus MCP SDK conformance client',
                preRegistered: $preRegistered,
                allowInsecureLoopback: true,
            ),
            null,
            new HttpClientBuilder(),
            logger: new PsrLogger(),
            grantStrategy: $strategy,
        );

        $runAuthorized($serverUrl, $http, static fn(Client $client): mixed => $client->listTools(), $extension);
    };
};

$register('auth/client-credentials-basic', static function (string $serverUrl) use ($withGrantStrategy, $scenarioContext, $contextString): void {
    $context = $scenarioContext();

    $withGrantStrategy(
        new ClientCredentialsGrant(new ClientSecretCredential(
            $contextString($context, 'client_id'),
            $contextString($context, 'client_secret'),
        )),
        new ClientCredentialsClientExtension(),
    )($serverUrl);
});

$register('auth/client-credentials-jwt', static function (string $serverUrl) use ($withGrantStrategy, $scenarioContext, $contextString): void {
    $context = $scenarioContext();

    $withGrantStrategy(
        new ClientCredentialsGrant(new PrivateKeyJwtCredential(
            $contextString($context, 'client_id'),
            $contextString($context, 'private_key_pem'),
            $contextString($context, 'signing_algorithm'),
        )),
        new ClientCredentialsClientExtension(),
    )($serverUrl);
});

$register('auth/enterprise-managed-authorization', static function (string $serverUrl) use ($withGrantStrategy, $scenarioContext, $contextString, $preRegisteredFromContext): void {
    $context = $scenarioContext();

    $withGrantStrategy(
        new IdentityAssertionGrant(
            $contextString($context, 'idp_token_endpoint'),
            new StaticIdentityAssertionProvider($contextString($context, 'idp_id_token')),
            $contextString($context, 'idp_client_id'),
            // The referee's mock IdP runs on plain HTTP over loopback.
            allowInsecureLoopback: true,
        ),
        new EnterpriseAuthorizationClientExtension(),
        $preRegisteredFromContext($context),
    )($serverUrl);
});

/**
 * Null means the throwable is a genuine failure rather than a refusal the scenario expects.
 */
function resolveDeliberateRefusal(Throwable $throwable): ?Throwable
{
    $refusals = [
        AuthorizationServerMismatchException::class,
        ClientRegistrationRequiredException::class,
        InsecureAuthorizationEndpointException::class,
        InsufficientScopeException::class,
        InvalidAuthorizationResponseException::class,
        MalformedAuthorizationResponseException::class,
        PkceNotSupportedException::class,
        RedirectRefusedException::class,
        ServerCapabilityNotSupportedException::class,
        UntrustedAuthorizationMetadataException::class,
    ];

    for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
        foreach ($refusals as $refusal) {
            if ($cause instanceof $refusal) {
                return $cause;
            }
        }
    }

    return null;
}

$arguments = conformanceArguments();
$scenario = getenv('MCP_CONFORMANCE_SCENARIO');
$serverUrl = count($arguments) > 1 ? $arguments[count($arguments) - 1] : '';

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

try {
    $scenarios[$scenario]($serverUrl);
} catch (Throwable $e) {
    $refusal = resolveDeliberateRefusal($e);

    if (null === $refusal) {
        throw $e;
    }

    // The refusal is this scenario's pass condition, so it exits non-zero without a stack trace.
    (new PsrLogger())->notice(
        'The client refused, as this scenario expects: {message}',
        ['message' => $refusal->getMessage()],
    );

    exit(1);
}
