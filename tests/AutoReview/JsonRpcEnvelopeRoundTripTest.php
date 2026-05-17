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
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the exact JSON-RPC envelope shape that every concrete request,
 * notification, result response, and error response writes to the envelope.
 *
 * Each fixture is a hand-authored, pretty-printed JSON file under
 * `envelope-shapes/{Class}/{variant}.json`. The test decodes the fixture,
 * reconstructs the schema instance, re-encodes with `JSON_PRETTY_PRINT`,
 * and asserts the string round-trips byte-for-byte. Companion gates
 * ensure every concrete spec class has at least one fixture and that no
 * orphan fixture directory exists on disk.
 *
 * Variant convention is two files per class: `all-props.json` (every optional
 * field populated) and `none.json` (only required fields). With no optional
 * params they collapse to a single `all-props.json`.
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

        foreach (self::concreteSubclasses(Result::class) as $resultClass) {
            $shortName = new \ReflectionClass($resultClass)->getShortName();
            $expectedDir = 'JsonRpcResultResponse-'.$shortName;

            if (! is_dir(self::fixtureRoot().'/'.$expectedDir)) {
                $missing[] = $resultClass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete Result subclasses without a JsonRpcResultResponse fixture set: %s. Add fixtures under envelope-shapes/JsonRpcResultResponse-{ShortName}/ and register in self::registry().',
            implode(', ', $missing),
        ));
    }

    public function testEveryConcreteErrorHasErrorResponseFixtures(): void
    {
        $missing = [];

        foreach (self::concreteSubclasses(Error::class) as $errorClass) {
            $shortName = new \ReflectionClass($errorClass)->getShortName();
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
     * Envelope fixture registry. Each entry binds a fixture directory to
     * a wrapper class and (for parameterized response wrappers) the inner
     * payload class needed to reconstruct the envelope. The optional
     * `encodingPathsDiverge` flag disables the cross-path encoding check
     * for entries whose `jsonSerialize` substitutes `\stdClass` for an
     * empty object slot that `toArray` returns as `[]` (directly or via
     * composition). Result responses inherit this from `Result`, which
     * applies the substitution at the result level.
     *
     * @return iterable<string, array{wrapper: class-string, inner: null|class-string<Result>, encodingPathsDiverge?: bool}>
     */
    #[\Override]
    protected static function registry(): iterable
    {
        // Concrete requests.
        yield 'PingRequest' => ['wrapper' => Request\PingRequest::class, 'inner' => null];

        yield 'ReadResourceRequest' => ['wrapper' => Request\ReadResourceRequest::class, 'inner' => null];

        yield 'InitializeRequest' => ['wrapper' => Request\InitializeRequest::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'CompleteRequest' => ['wrapper' => Request\CompleteRequest::class, 'inner' => null];

        yield 'CreateMessageRequest' => ['wrapper' => Request\CreateMessageRequest::class, 'inner' => null];

        yield 'GetPromptRequest' => ['wrapper' => Request\GetPromptRequest::class, 'inner' => null];

        yield 'CallToolRequest' => ['wrapper' => Request\CallToolRequest::class, 'inner' => null];

        yield 'CancelTaskRequest' => ['wrapper' => Request\CancelTaskRequest::class, 'inner' => null];

        yield 'GetTaskRequest' => ['wrapper' => Request\GetTaskRequest::class, 'inner' => null];

        yield 'GetTaskPayloadRequest' => ['wrapper' => Request\GetTaskPayloadRequest::class, 'inner' => null];

        yield 'ListPromptsRequest' => ['wrapper' => Request\ListPromptsRequest::class, 'inner' => null];

        yield 'ListResourcesRequest' => ['wrapper' => Request\ListResourcesRequest::class, 'inner' => null];

        yield 'ListResourceTemplatesRequest' => ['wrapper' => Request\ListResourceTemplatesRequest::class, 'inner' => null];

        yield 'ListRootsRequest' => ['wrapper' => Request\ListRootsRequest::class, 'inner' => null];

        yield 'ListTasksRequest' => ['wrapper' => Request\ListTasksRequest::class, 'inner' => null];

        yield 'ListToolsRequest' => ['wrapper' => Request\ListToolsRequest::class, 'inner' => null];

        yield 'SetLevelRequest' => ['wrapper' => Request\SetLevelRequest::class, 'inner' => null];

        yield 'SubscribeRequest' => ['wrapper' => Request\SubscribeRequest::class, 'inner' => null];

        yield 'UnsubscribeRequest' => ['wrapper' => Request\UnsubscribeRequest::class, 'inner' => null];

        yield 'ElicitRequest' => ['wrapper' => Request\ElicitRequest::class, 'inner' => null];

        // Concrete notifications.
        yield 'CancelledNotification' => ['wrapper' => Notification\CancelledNotification::class, 'inner' => null, 'encodingPathsDiverge' => true];

        yield 'InitializedNotification' => ['wrapper' => Notification\InitializedNotification::class, 'inner' => null];

        yield 'LoggingMessageNotification' => ['wrapper' => Notification\LoggingMessageNotification::class, 'inner' => null];

        yield 'ProgressNotification' => ['wrapper' => Notification\ProgressNotification::class, 'inner' => null];

        yield 'PromptListChangedNotification' => ['wrapper' => Notification\PromptListChangedNotification::class, 'inner' => null];

        yield 'ResourceListChangedNotification' => ['wrapper' => Notification\ResourceListChangedNotification::class, 'inner' => null];

        yield 'ResourceUpdatedNotification' => ['wrapper' => Notification\ResourceUpdatedNotification::class, 'inner' => null];

        yield 'RootsListChangedNotification' => ['wrapper' => Notification\RootsListChangedNotification::class, 'inner' => null];

        yield 'TaskStatusNotification' => ['wrapper' => Notification\TaskStatusNotification::class, 'inner' => null];

        yield 'ToolListChangedNotification' => ['wrapper' => Notification\ToolListChangedNotification::class, 'inner' => null];

        yield 'ElicitationCompleteNotification' => ['wrapper' => Notification\ElicitationCompleteNotification::class, 'inner' => null];

        // Result responses, parameterized by the inner Result subclass.
        yield 'JsonRpcResultResponse-CallToolResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\CallToolResult::class];

        yield 'JsonRpcResultResponse-CancelTaskResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\CancelTaskResult::class];

        yield 'JsonRpcResultResponse-CompleteResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\CompleteResult::class];

        yield 'JsonRpcResultResponse-CreateMessageResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\CreateMessageResult::class];

        yield 'JsonRpcResultResponse-CreateTaskResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\CreateTaskResult::class];

        yield 'JsonRpcResultResponse-EmptyResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\EmptyResult::class];

        yield 'JsonRpcResultResponse-GetPromptResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\GetPromptResult::class];

        yield 'JsonRpcResultResponse-GetTaskResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\GetTaskResult::class];

        yield 'JsonRpcResultResponse-GetTaskPayloadResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\GetTaskPayloadResult::class];

        yield 'JsonRpcResultResponse-InitializeResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\InitializeResult::class];

        yield 'JsonRpcResultResponse-ListPromptsResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ListPromptsResult::class];

        yield 'JsonRpcResultResponse-ListResourcesResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ListResourcesResult::class];

        yield 'JsonRpcResultResponse-ListResourceTemplatesResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ListResourceTemplatesResult::class];

        yield 'JsonRpcResultResponse-ListRootsResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ListRootsResult::class];

        yield 'JsonRpcResultResponse-ListTasksResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ListTasksResult::class];

        yield 'JsonRpcResultResponse-ListToolsResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ListToolsResult::class];

        yield 'JsonRpcResultResponse-ReadResourceResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ReadResourceResult::class];

        yield 'JsonRpcResultResponse-ElicitResult' => ['wrapper' => JsonRpcResultResponse::class, 'encodingPathsDiverge' => true, 'inner' => Result\ElicitResult::class];

        // Error responses, organised per Error subclass even though
        // `JsonRpcErrorResponse::fromArray` self-dispatches on `code`.
        yield 'JsonRpcErrorResponse-InternalError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InvalidParamsError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InvalidRequestError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-MethodNotFoundError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-ParseError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-UnknownProtocolError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-UrlElicitationRequiredErrorPayload' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];
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

        if (JsonRpcResultResponse::class === $wrapper) {
            self::assertIsString($inner, 'JsonRpcResultResponse fixtures must declare an inner Result class.');
            self::assertArrayHasKey('id', $decoded);
            self::assertArrayHasKey('result', $decoded);
            self::assertIsArray($decoded['result']);
            self::assertStringKeyed($decoded['result'], 'inner result');

            $id = $decoded['id'];

            if (! \is_int($id) && ! \is_string($id)) {
                self::fail('JsonRpcResultResponse fixture "id" must be an int or string.');
            }

            \assert(is_subclass_of($inner, Result::class));

            return new JsonRpcResultResponse(new RequestId($id), $inner::fromArray($decoded['result']));
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
            if (JsonRpcResultResponse::class === $entry['wrapper'] || JsonRpcErrorResponse::class === $entry['wrapper']) {
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
