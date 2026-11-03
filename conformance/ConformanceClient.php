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

use Amp\Http\Client\HttpClientBuilder;
use Nexus\Assert\Assert;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\ClientBuilder;
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
use Nexus\Mcp\Client\Extension\ClientExtensionInterface;
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
use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;
use Nexus\Mcp\Extension\Skills\Client\SkillClient;
use Nexus\Mcp\Extension\Skills\Client\SkillsClientExtension;
use Psr\Log\LoggerInterface;

/**
 * Registry of the conformance scenarios, keyed by the referee's scenario name.
 *
 * @phpstan-import-type ToolCall from ScenarioContext
 */
final readonly class ConformanceClient
{
    // The `client_id` expected by the CIMD scenario, compared as a string and never fetched, so nothing serves it.
    private const string CLIENT_ID_METADATA_DOCUMENT_URL = 'https://conformance-test.local/client-metadata.json';
    private const string CLIENT_NAME = 'Nexus MCP SDK conformance client';
    private const array AUTHORIZATION_SCENARIOS = [
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
    ];
    private const array DELIBERATE_REFUSALS = [
        AuthorizationServerMismatchException::class,
        ClientRegistrationRequiredException::class,
        InsecureAuthorizationEndpointException::class,
        InsufficientScopeException::class,
        InvalidAuthorizationResponseException::class,
        MalformedAuthorizationResponseException::class,
        PkceNotSupportedException::class,
        RedirectRefusedException::class,
        ServerCapabilityNotSupportedException::class,
        SkillVerificationFailedException::class,
        UntrustedAuthorizationMetadataException::class,
    ];

    public function __construct(
        private LoggerInterface $logger,
        private ScenarioContext $context,
    ) {
    }

    /**
     * Describes the registered scenarios, one per line.
     */
    public function describeScenarios(): string
    {
        $lines = '';

        foreach (array_keys($this->buildScenarios()) as $name) {
            $lines .= sprintf("  - %s\n", $name);
        }

        return $lines;
    }

    /**
     * @return int The process exit code
     */
    public function run(string $scenario, string $serverUrl): int
    {
        $scenarios = $this->buildScenarios();

        if (! isset($scenarios[$scenario])) {
            fwrite(\STDERR, sprintf(
                "No handler registered for scenario \"%s\".\n\nRegistered scenarios:\n%s",
                $scenario,
                $this->describeScenarios(),
            ));

            return 1;
        }

        try {
            $scenarios[$scenario]($serverUrl);
        } catch (Throwable $e) {
            $refusal = self::findDeliberateRefusal($e);

            if (null === $refusal) {
                throw $e;
            }

            // The refusal is this scenario's pass condition, so it exits non-zero without a stack trace.
            $this->logger->notice(
                'The client refused, as this scenario expects: {message}',
                ['message' => $refusal->getMessage()],
            );

            return 1;
        }

        return 0;
    }

    /**
     * Null means the throwable is a genuine failure rather than a refusal expected by the scenario.
     */
    private static function findDeliberateRefusal(Throwable $throwable): ?Throwable
    {
        for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
            foreach (self::DELIBERATE_REFUSALS as $refusal) {
                if ($cause instanceof $refusal) {
                    return $cause;
                }
            }
        }

        return null;
    }

    private static function demandInput(CallToolResult|InputRequiredResult $result, string $tool): InputRequiredResult
    {
        if (! $result instanceof InputRequiredResult) {
            throw new RuntimeException(sprintf('The "%s" tool answered without asking for input.', $tool));
        }

        return $result;
    }

    /**
     * @return array<string, callable(string): mixed>
     */
    private function buildScenarios(): array
    {
        $scenarios = [
            'tools_call' => $this->runToolsCall(...),
            'request-metadata' => $this->runRequestMetadata(...),
            'http-standard-headers' => $this->runListThenCall(...),
            'http-custom-headers' => $this->runListThenCall(...),
            'http-invalid-tool-headers' => $this->runInvalidToolHeaders(...),
            'json-schema-ref-no-deref' => $this->runListTools(...),
            'json-schema-2020-12-preservation' => $this->runJsonSchemaPreservation(...),
            'sep-2322-client-request-state' => $this->runClientRequestState(...),
        ];

        foreach (self::AUTHORIZATION_SCENARIOS as $name) {
            $scenarios[$name] = $this->runAuthorizedListTools(...);
        }

        return $scenarios + [
            'auth/scope-step-up' => $this->runScopeStepUp(...),
            'auth/authorization-server-migration' => $this->runAuthorizationServerMigration(...),
            'auth/client-credentials-basic' => $this->runClientCredentialsBasic(...),
            'auth/client-credentials-jwt' => $this->runClientCredentialsJwt(...),
            'auth/enterprise-managed-authorization' => $this->runEnterpriseManagedAuthorization(...),
            'sep-2640-client-no-prefetch' => $this->runSkillsNoPrefetch(...),
            'sep-2640-client-verify-digest' => $this->runLoadFirstSkill(...),
            'sep-2640-client-verify-size' => $this->runLoadFirstSkill(...),
            'sep-2640-client-verify-frontmatter' => $this->runLoadFirstSkill(...),
        ];
    }

    private function connect(
        string $serverUrl,
        ?ClientExtensionInterface $extension = null,
        ?AuthorizedHttpClient $http = null,
    ): Client {
        Assert::that($serverUrl)->isNonEmptyString('The conformance runner must supply a server URL.');

        $builder = (new ClientBuilder())
            ->setLogger($this->logger)
            ->setClientInfo(name: 'nexus-mcp-conformance-client', version: '1.0.0')
        ;

        if (null !== $extension) {
            $builder->enableExtension($extension);
        }

        $client = $builder->build();

        $client->connect(new StreamableHttpClientTransport(
            endpoint: $serverUrl,
            client: $http,
            logger: $this->logger,
            readTimeout: 30.0,
        ));

        return $client;
    }

    /**
     * @param Closure(Client): mixed $exercise
     */
    private function exercise(
        string $serverUrl,
        Closure $exercise,
        ?ClientExtensionInterface $extension = null,
        ?AuthorizedHttpClient $http = null,
    ): void {
        $client = $this->connect($serverUrl, $extension, $http);

        try {
            $exercise($client);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param list<ToolCall> $calls
     */
    private function callTools(Client $client, array $calls): void
    {
        foreach ($calls as $call) {
            $client->callTool(name: $call['name'], arguments: $call['arguments']);
        }
    }

    /**
     * @param list<ToolCall> $default
     */
    private function listThenCallOrDefault(string $serverUrl, array $default): void
    {
        $this->exercise($serverUrl, function (Client $client) use ($default): void {
            $client->listTools();

            $calls = $this->context->readToolCalls();
            $this->callTools($client, [] === $calls ? $default : $calls);
        });
    }

    private function runToolsCall(string $serverUrl): void
    {
        $this->listThenCallOrDefault($serverUrl, [['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 3]]]);
    }

    private function runInvalidToolHeaders(string $serverUrl): void
    {
        $this->listThenCallOrDefault($serverUrl, [['name' => 'valid_tool', 'arguments' => []]]);
    }

    // Lists the tools before calling them, since listing is what records each tool's `x-mcp-header` bindings.
    private function runListThenCall(string $serverUrl): void
    {
        $this->exercise($serverUrl, function (Client $client): void {
            $client->listTools();
            $this->callTools($client, $this->context->readToolCalls());
        });
    }

    private function runRequestMetadata(string $serverUrl): void
    {
        $this->exercise($serverUrl, static function (Client $client): void {
            $client->discover();
            $client->listTools();
        });
    }

    private function runListTools(string $serverUrl): void
    {
        $this->exercise($serverUrl, static fn(Client $client): mixed => $client->listTools());
    }

    private function runJsonSchemaPreservation(string $serverUrl): void
    {
        $this->exercise($serverUrl, static function (Client $client): void {
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
        });
    }

    private function runClientRequestState(string $serverUrl): void
    {
        $this->exercise($serverUrl, function (Client $client): void {
            $client->listTools();

            $stateful = self::demandInput($client->callTool(name: 'test_mrtr_echo_state'), 'test_mrtr_echo_state');

            // Between the two rounds, so a call of its own proves the pending round leaks nothing into it.
            $client->callTool(name: 'test_mrtr_unrelated');
            $client->callTool(
                name: 'test_mrtr_echo_state',
                inputResponses: $this->acceptInputRequests($stateful),
                requestState: $stateful->requestState,
            );

            $stateless = self::demandInput($client->callTool(name: 'test_mrtr_no_state'), 'test_mrtr_no_state');
            $client->callTool(
                name: 'test_mrtr_no_state',
                inputResponses: $this->acceptInputRequests($stateless),
                requestState: $stateless->requestState,
            );

            $client->callTool(name: 'test_mrtr_no_result_type');
        });
    }

    /**
     * @return array<int|non-empty-string, ElicitResult>
     */
    private function acceptInputRequests(InputRequiredResult $result): array
    {
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
    }

    /**
     * @param Closure(Client): mixed $exercise
     */
    private function exerciseAuthorized(string $serverUrl, Closure $exercise): void
    {
        $http = new AuthorizedHttpClient(
            $serverUrl,
            new AuthorizationOptions(
                clientName: self::CLIENT_NAME,
                redirectUri: 'http://127.0.0.1:8765/callback',
                clientIdMetadataDocumentUrl: self::CLIENT_ID_METADATA_DOCUMENT_URL,
                preRegistered: $this->context->readPreRegistration(),
                requestOfflineAccess: true,
                // The referee's mock authorization server runs on plain HTTP over loopback, so the harness opts in.
                allowInsecureLoopback: true,
            ),
            new HeadlessUserAuthorization(),
            new HttpClientBuilder(),
            logger: $this->logger,
        );

        $this->exercise($serverUrl, $exercise, http: $http);
    }

    private function runAuthorizedListTools(string $serverUrl): void
    {
        $this->exerciseAuthorized($serverUrl, static fn(Client $client): mixed => $client->listTools());
    }

    // The mock guards the tool call behind a second scope, so calling one provokes the step-up that listing never would.
    private function runScopeStepUp(string $serverUrl): void
    {
        $this->exerciseAuthorized($serverUrl, static function (Client $client): void {
            $client->listTools();
            $client->callTool(name: 'test-tool');
        });
    }

    // The resource names its new authorization server only after one request has succeeded against the old one.
    private function runAuthorizationServerMigration(string $serverUrl): void
    {
        $this->exerciseAuthorized($serverUrl, static function (Client $client): void {
            $client->listTools();
            $client->listTools();
        });
    }

    private function exerciseWithGrant(
        string $serverUrl,
        ClientCredentialsGrant|IdentityAssertionGrant $strategy,
        ClientExtensionInterface $extension,
        ?ClientRegistration $preRegistered = null,
    ): void {
        $http = new AuthorizedHttpClient(
            $serverUrl,
            new AuthorizationOptions(
                clientName: self::CLIENT_NAME,
                preRegistered: $preRegistered,
                allowInsecureLoopback: true,
            ),
            null,
            new HttpClientBuilder(),
            logger: $this->logger,
            grantStrategy: $strategy,
        );

        $this->exercise($serverUrl, static fn(Client $client): mixed => $client->listTools(), $extension, $http);
    }

    private function runClientCredentialsBasic(string $serverUrl): void
    {
        $credential = new ClientSecretCredential(
            $this->context->readString('client_id'),
            $this->context->readString('client_secret'),
        );

        $this->exerciseWithGrant($serverUrl, new ClientCredentialsGrant($credential), new ClientCredentialsClientExtension());
    }

    private function runClientCredentialsJwt(string $serverUrl): void
    {
        $credential = new PrivateKeyJwtCredential(
            $this->context->readString('client_id'),
            $this->context->readString('private_key_pem'),
            $this->context->readString('signing_algorithm'),
        );

        $this->exerciseWithGrant($serverUrl, new ClientCredentialsGrant($credential), new ClientCredentialsClientExtension());
    }

    private function runEnterpriseManagedAuthorization(string $serverUrl): void
    {
        $grant = new IdentityAssertionGrant(
            $this->context->readString('idp_token_endpoint'),
            new StaticIdentityAssertionProvider($this->context->readString('idp_id_token')),
            $this->context->readString('idp_client_id'),
            // The referee's mock IdP runs on plain HTTP over loopback.
            allowInsecureLoopback: true,
        );

        $this->exerciseWithGrant(
            $serverUrl,
            $grant,
            new EnterpriseAuthorizationClientExtension(),
            $this->context->readPreRegistration(),
        );
    }

    private function runSkillsNoPrefetch(string $serverUrl): void
    {
        $this->exercise(
            $serverUrl,
            static fn(Client $client): mixed => (new SkillClient($client))->listSkills(),
            new SkillsClientExtension(),
        );
    }

    private function runLoadFirstSkill(string $serverUrl): void
    {
        $this->exercise($serverUrl, static function (Client $client): void {
            $skills = new SkillClient($client);
            $skill = $skills->listSkills()->skills[0] ?? null;

            if (null === $skill) {
                return;
            }

            $skills->readSkillFile($skill, $skill->uri);

            foreach (is_array($skill->resources) ? $skill->resources : [] as $resource) {
                if ($resource->uri !== $skill->uri) {
                    $skills->readSkillFile($skill, $resource->uri);
                }
            }
        }, new SkillsClientExtension());
    }
}
