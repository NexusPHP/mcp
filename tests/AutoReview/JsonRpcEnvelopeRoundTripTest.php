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

namespace Nexus\Mcp\Tests\AutoReview;

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\PromptListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\CompleteResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\DiscoverResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GetPromptResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListPromptsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListResourcesResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListResourceTemplatesResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ReadResourceResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\SubscriptionsListenResultResponse;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the exact JSON-RPC envelope shape every concrete request, notification, result response, and error response writes, two fixtures per class: `all-props.json` and `none.json`.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class JsonRpcEnvelopeRoundTripTest extends AbstractRoundTripTestCase
{
    public function testEveryConcreteRequestHasFixtures(): void
    {
        self::assertEveryConcreteSubclassIsRegistered(JsonRpcRequest::class, 'requests');
    }

    public function testEveryConcreteNotificationHasFixtures(): void
    {
        self::assertEveryConcreteSubclassIsRegistered(JsonRpcNotification::class, 'notifications');
    }

    public function testEveryConcreteResultHasResultResponseFixtures(): void
    {
        $missing = [];

        foreach (self::concreteSubclasses(Result::class) as $result) {
            $shortName = (new \ReflectionClass($result))->getShortName();

            if (! is_dir(self::fixtureRoot().'/'.$shortName)) {
                $missing[] = $result;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete Result subclasses without a result-response fixture set: %s. Add fixtures under envelope-shapes/{ResultShortName}/ and register in self::registry().',
            implode(', ', $missing),
        ));
    }

    /**
     * @param class-string<Result> $resultClass
     */
    #[DataProvider('provideEveryResultSurvivesAMetaRebuildCases')]
    public function testEveryResultSurvivesAMetaRebuild(string $resultClass, string $fixturePath): void
    {
        $decoded = self::decodeFixture(self::readFixture($fixturePath), $fixturePath);
        self::assertArrayHasKey('result', $decoded);
        self::assertIsArray($decoded['result']);
        self::assertStringKeyed($decoded['result'], $fixturePath);

        $result = $resultClass::fromArray($decoded['result']);
        $serverInfo = new Implementation(name: 'stamped-server', version: '1.0.0');

        $payload = $result->toArray();
        unset($payload['_meta']);

        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(serverInfo: $serverInfo));

        $rebuiltPayload = $rebuilt->toArray();
        unset($rebuiltPayload['_meta']);

        self::assertSame(
            $payload,
            $rebuiltPayload,
            \sprintf(
                'Rebuilding %s with a new `_meta` dropped or reordered part of the result. Its rebuildWithMeta() must carry every field the constructor takes.',
                $resultClass,
            ),
        );

        self::assertInstanceOf(
            $result->meta::class,
            $rebuilt->meta,
            \sprintf(
                'Rebuilding %s widened its `_meta` type. A result whose method names a narrower `_meta` must rebuild into that same type.',
                $resultClass,
            ),
        );

        self::assertSame(
            $serverInfo->toArray(),
            $rebuilt->meta->serverInfo?->toArray(),
            \sprintf('Rebuilding %s did not carry the stamped server identity.', $resultClass),
        );
    }

    /**
     * @return iterable<string, array{class-string<Result>, string}>
     */
    public static function provideEveryResultSurvivesAMetaRebuildCases(): iterable
    {
        foreach (self::concreteSubclasses(Result::class) as $resultClass) {
            $shortName = (new \ReflectionClass($resultClass))->getShortName();

            yield $shortName => [$resultClass, self::fixtureRoot().'/'.$shortName.'/all-props.json'];
        }
    }

    public function testEveryConcreteErrorHasErrorResponseFixtures(): void
    {
        $missing = [];

        foreach (self::concreteSubclasses(Error::class) as $errorClass) {
            $shortName = (new \ReflectionClass($errorClass))->getShortName();
            $expectedDir = 'JsonRpcErrorResponse-'.$shortName;

            if (! is_dir(self::fixtureRoot().'/'.$expectedDir)) {
                $missing[] = $errorClass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete Error subclasses without a JsonRpcErrorResponse fixture set: %s. Add fixtures under envelope-shapes/JsonRpcErrorResponse-{ShortName}/ and register in self::registry().',
            implode(', ', $missing),
        ));
    }

    #[\Override]
    protected static function fixtureRoot(): string
    {
        return __DIR__.'/envelope-shapes';
    }

    /**
     * Envelope fixture registry binding a fixture directory to its wrapper class, inner payload class, and optional `encodingPathsDiverge` flag.
     *
     * @return iterable<string, array{wrapper: class-string, inner: null|class-string<Result>, encodingPathsDiverge?: bool}>
     */
    #[\Override]
    protected static function registry(): iterable
    {
        yield 'DiscoverRequest' => ['wrapper' => DiscoverRequest::class, 'inner' => null];

        yield 'ReadResourceRequest' => ['wrapper' => ReadResourceRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'CompleteRequest' => ['wrapper' => CompleteRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'GetPromptRequest' => ['wrapper' => GetPromptRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'CallToolRequest' => ['wrapper' => CallToolRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'ListPromptsRequest' => ['wrapper' => ListPromptsRequest::class, 'inner' => null];

        yield 'ListResourcesRequest' => ['wrapper' => ListResourcesRequest::class, 'inner' => null];

        yield 'ListResourceTemplatesRequest' => ['wrapper' => ListResourceTemplatesRequest::class, 'inner' => null];

        yield 'ListToolsRequest' => ['wrapper' => ListToolsRequest::class, 'inner' => null];

        yield 'SubscriptionsListenRequest' => ['wrapper' => SubscriptionsListenRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'GetTaskRequest' => ['wrapper' => GetTaskRequest::class, 'inner' => null];

        yield 'UpdateTaskRequest' => ['wrapper' => UpdateTaskRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'CancelTaskRequest' => ['wrapper' => CancelTaskRequest::class, 'inner' => null];

        yield 'CancelledNotification' => ['wrapper' => CancelledNotification::class, 'inner' => null];

        yield 'ProgressNotification' => ['wrapper' => ProgressNotification::class, 'inner' => null];

        yield 'PromptListChangedNotification' => ['wrapper' => PromptListChangedNotification::class, 'inner' => null];

        yield 'ResourceListChangedNotification' => ['wrapper' => ResourceListChangedNotification::class, 'inner' => null];

        yield 'ResourceUpdatedNotification' => ['wrapper' => ResourceUpdatedNotification::class, 'inner' => null];

        yield 'ToolListChangedNotification' => ['wrapper' => ToolListChangedNotification::class, 'inner' => null];

        yield 'SubscriptionsAcknowledgedNotification' => ['wrapper' => SubscriptionsAcknowledgedNotification::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'CallToolResult' => ['wrapper' => CallToolResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'CompleteResult' => ['wrapper' => CompleteResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'DiscoverResult' => ['wrapper' => DiscoverResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'EmptyResult' => ['wrapper' => GenericResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => EmptyResult::class];

        yield 'GetPromptResult' => ['wrapper' => GetPromptResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'InputRequiredResult' => ['wrapper' => CallToolResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'ListPromptsResult' => ['wrapper' => ListPromptsResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'ListResourcesResult' => ['wrapper' => ListResourcesResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'ListResourceTemplatesResult' => ['wrapper' => ListResourceTemplatesResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'ListToolsResult' => ['wrapper' => ListToolsResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'ReadResourceResult' => ['wrapper' => ReadResourceResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'SubscriptionsListenResult' => ['wrapper' => SubscriptionsListenResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => null];

        yield 'CreateTaskResult' => ['wrapper' => GenericResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => CreateTaskResult::class];

        yield 'GetTaskResult' => ['wrapper' => GenericResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => GetTaskResult::class];

        yield 'JsonRpcErrorResponse-HeaderMismatchError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InternalError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InvalidParamsError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InvalidRequestError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-MethodNotFoundError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-MissingRequiredClientCapabilityError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'JsonRpcErrorResponse-ParseError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-UnknownProtocolError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-UnsupportedProtocolVersionError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $decoded
     */
    #[\Override]
    protected static function reconstruct(array $entry, array $decoded): \JsonSerializable
    {
        self::assertArrayHasKey('wrapper', $entry);
        self::assertArrayHasKey('inner', $entry);

        $wrapper = $entry['wrapper'];
        self::assertIsString($wrapper);

        $inner = $entry['inner'];

        if (GenericResultResponse::class === $wrapper) {
            self::assertIsString($inner, 'GenericResultResponse fixtures must declare an inner Result class.');
            self::assertArrayHasKey('id', $decoded);
            self::assertArrayHasKey('result', $decoded);
            self::assertIsArray($decoded['result']);
            self::assertStringKeyed($decoded['result'], 'inner result');

            $id = $decoded['id'];

            if (! \is_int($id) && ('' === $id || ! \is_string($id))) {
                self::fail('GenericResultResponse fixture "id" must be an int or non-empty string.');
            }

            \assert(is_subclass_of($inner, Result::class));

            return new GenericResultResponse(id: new RequestId(id: $id), result: $inner::fromArray($decoded['result']));
        }

        \assert(is_subclass_of($wrapper, Arrayable::class));

        return $wrapper::fromArray($decoded);
    }

    /**
     * @param class-string $abstractBase
     */
    private static function assertEveryConcreteSubclassIsRegistered(string $abstractBase, string $label): void
    {
        $registered = [];

        foreach (self::registry() as $dir => $entry) {
            if (is_subclass_of($entry['wrapper'], JsonRpcResultResponse::class) || JsonRpcErrorResponse::class === $entry['wrapper']) {
                continue;
            }

            if (is_subclass_of($entry['wrapper'], $abstractBase)) {
                $registered[$entry['wrapper']] = $dir;
            }
        }

        $missing = [];

        foreach (self::concreteSubclasses($abstractBase) as $subclass) {
            if (! isset($registered[$subclass])) {
                $missing[] = $subclass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete %s without envelope fixtures: %s. Add fixtures under envelope-shapes/{ShortName}/ and register in self::registry().',
            $label,
            implode(', ', $missing),
        ));
    }
}
