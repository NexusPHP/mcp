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

namespace Nexus\Mcp\Tests\Extension\Tasks\Schema\Result;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetTaskResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetTaskResultTest extends TestCase
{
    public function testWorkingVariantToArray(): void
    {
        $result = self::createWorking();

        self::assertSame(
            [
                'resultType' => 'complete',
                'taskId' => 'task-1',
                'status' => 'working',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
                'ttlMs' => null,
            ],
            $result->toArray(),
        );
    }

    public function testCompletedVariantCarriesResultPayload(): void
    {
        $result = new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Completed,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:05+00:00',
            ttlMs: 300_000,
            result: ['resultType' => 'complete', 'content' => []],
        );

        self::assertSame(
            [
                'resultType' => 'complete',
                'taskId' => 'task-1',
                'status' => 'completed',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:05+00:00',
                'ttlMs' => 300_000,
                'result' => ['resultType' => 'complete', 'content' => []],
            ],
            $result->toArray(),
        );
    }

    public function testFailedVariantCarriesErrorPayload(): void
    {
        $result = new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Failed,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:05+00:00',
            ttlMs: 300_000,
            error: ['code' => -32603, 'message' => 'It broke.'],
            statusMessage: 'Upstream unavailable.',
        );

        self::assertSame(
            [
                'resultType' => 'complete',
                'taskId' => 'task-1',
                'status' => 'failed',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:05+00:00',
                'ttlMs' => 300_000,
                'error' => ['code' => -32603, 'message' => 'It broke.'],
                'statusMessage' => 'Upstream unavailable.',
            ],
            $result->toArray(),
        );
    }

    public function testInputRequiredVariantCarriesInputRequests(): void
    {
        $result = new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::InputRequired,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:02+00:00',
            ttlMs: 300_000,
            inputRequests: ['github_login' => self::createElicitRequest()],
        );

        $payload = $result->toArray();

        self::assertSame('input_required', $payload['status']);
        self::assertArrayHasKey('inputRequests', $payload);
        self::assertArrayNotHasKey('requestState', $payload);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = self::createWorking();

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayRoundTripsEveryVariant(): void
    {
        $variants = [
            self::createWorking(),
            new GetTaskResult(
                taskId: 'task-1',
                status: TaskStatus::Completed,
                createdAt: '2026-08-04T12:00:00+00:00',
                lastUpdatedAt: '2026-08-04T12:00:05+00:00',
                ttlMs: 300_000,
                result: ['resultType' => 'complete', 'content' => []],
            ),
            new GetTaskResult(
                taskId: 'task-1',
                status: TaskStatus::Failed,
                createdAt: '2026-08-04T12:00:00+00:00',
                lastUpdatedAt: '2026-08-04T12:00:05+00:00',
                ttlMs: 300_000,
                error: ['code' => -32603, 'message' => 'It broke.'],
            ),
            new GetTaskResult(
                taskId: 'task-1',
                status: TaskStatus::InputRequired,
                createdAt: '2026-08-04T12:00:00+00:00',
                lastUpdatedAt: '2026-08-04T12:00:02+00:00',
                ttlMs: 300_000,
                inputRequests: ['github_login' => self::createElicitRequest()],
            ),
            new GetTaskResult(
                taskId: 'task-1',
                status: TaskStatus::Cancelled,
                createdAt: '2026-08-04T12:00:00+00:00',
                lastUpdatedAt: '2026-08-04T12:00:05+00:00',
                ttlMs: 300_000,
                meta: new GenericResultMetaObject(extras: ['vendor.brand' => 'acme']),
            ),
        ];

        foreach ($variants as $original) {
            self::assertSame($original->toArray(), GetTaskResult::fromArray($original->toArray())->toArray());
        }
    }

    public function testRebuildWithMetaCarriesEveryField(): void
    {
        $result = new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Completed,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:05+00:00',
            ttlMs: 300_000,
            result: ['resultType' => 'complete', 'content' => []],
            statusMessage: 'Done.',
            pollIntervalMs: 1_000,
        );

        $serverInfo = new Implementation(name: 'stamped-server', version: '1.0.0');
        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(serverInfo: $serverInfo));

        $payload = $result->toArray();
        $rebuiltPayload = $rebuilt->toArray();
        unset($payload['_meta'], $rebuiltPayload['_meta']);

        self::assertSame($payload, $rebuiltPayload);
        self::assertSame($serverInfo->toArray(), $rebuilt->meta->serverInfo?->toArray());
    }

    /**
     * @param null|array<string, mixed> $result
     * @param null|array<string, mixed> $error
     */
    #[DataProvider('provideConstructorRejectsMismatchedPayloadCases')]
    public function testConstructorRejectsMismatchedPayload(
        TaskStatus $status,
        ?array $result,
        ?array $error,
        bool $withInputRequests,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        new GetTaskResult(
            taskId: 'task-1',
            status: $status,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
            result: $result,
            error: $error,
            inputRequests: $withInputRequests ? ['github_login' => self::createElicitRequest()] : null,
        );
    }

    /**
     * @return iterable<string, array{TaskStatus, null|array<string, mixed>, null|array<string, mixed>, bool, string}>
     */
    public static function provideConstructorRejectsMismatchedPayloadCases(): iterable
    {
        yield 'completed without result' => [
            TaskStatus::Completed,
            null,
            null,
            false,
            'a completed "result" must carry "result" and neither "error" nor "inputRequests".',
        ];

        yield 'completed with error' => [
            TaskStatus::Completed,
            ['resultType' => 'complete'],
            ['code' => -32603],
            false,
            'a completed "result" must carry "result" and neither "error" nor "inputRequests".',
        ];

        yield 'failed without error' => [
            TaskStatus::Failed,
            null,
            null,
            false,
            'a failed "result" must carry "error" and neither "result" nor "inputRequests".',
        ];

        yield 'input_required without inputRequests' => [
            TaskStatus::InputRequired,
            null,
            null,
            false,
            'an input_required "result" must carry "inputRequests" and neither "result" nor "error".',
        ];

        yield 'working with result' => [
            TaskStatus::Working,
            ['resultType' => 'complete'],
            null,
            false,
            'a working "result" must carry none of "result", "error", or "inputRequests".',
        ];

        yield 'cancelled with inputRequests' => [
            TaskStatus::Cancelled,
            null,
            null,
            true,
            'a cancelled "result" must carry none of "result", "error", or "inputRequests".',
        ];
    }

    public function testConstructorRejectsListShapedResultPayload(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.result" must be a string-keyed object.');

        new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Completed,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
            // @phpstan-ignore argument.type
            result: ['a', 'b'],
        );
    }

    public function testConstructorRejectsListShapedErrorPayload(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.error" must be a string-keyed object.');

        new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Failed,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
            // @phpstan-ignore argument.type
            error: ['a', 'b'],
        );
    }

    public function testConstructorRejectsListKeyedInputRequests(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.inputRequests" must be a string-keyed object.');

        new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::InputRequired,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
            // @phpstan-ignore argument.type
            inputRequests: [self::createElicitRequest()],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetTaskResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $valid = [
            'taskId' => 'task-1',
            'status' => 'working',
            'createdAt' => '2026-08-04T12:00:00+00:00',
            'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
            'ttlMs' => null,
        ];

        yield 'missing taskId' => [
            array_diff_key($valid, ['taskId' => true]),
            '"result" is missing the required "taskId" key.',
        ];

        yield 'taskId not a string' => [
            ['taskId' => 1] + $valid,
            '"result.taskId" must be a non-empty string, int given.',
        ];

        yield 'missing status' => [
            array_diff_key($valid, ['status' => true]),
            '"result" is missing the required "status" key.',
        ];

        yield 'missing createdAt' => [
            array_diff_key($valid, ['createdAt' => true]),
            '"result" is missing the required "createdAt" key.',
        ];

        yield 'createdAt not a string' => [
            ['createdAt' => 5] + $valid,
            '"result.createdAt" must be a non-empty string, int given.',
        ];

        yield 'missing lastUpdatedAt' => [
            array_diff_key($valid, ['lastUpdatedAt' => true]),
            '"result" is missing the required "lastUpdatedAt" key.',
        ];

        yield 'lastUpdatedAt not a string' => [
            ['lastUpdatedAt' => 5] + $valid,
            '"result.lastUpdatedAt" must be a non-empty string, int given.',
        ];

        yield 'missing ttlMs' => [
            array_diff_key($valid, ['ttlMs' => true]),
            '"result" is missing the required "ttlMs" key.',
        ];

        yield 'statusMessage not a string' => [
            ['statusMessage' => 5] + $valid,
            '"result.statusMessage" must be a non-empty string, int given.',
        ];

        yield 'pollIntervalMs not an int' => [
            ['pollIntervalMs' => '1000'] + $valid,
            '"result.pollIntervalMs" must be a positive integer, \'1000\' given.',
        ];

        yield 'pollIntervalMs negative' => [
            ['pollIntervalMs' => -1] + $valid,
            '"result.pollIntervalMs" must be a positive integer, -1 given.',
        ];

        yield '_meta not an object' => [
            ['_meta' => 'oops'] + $valid,
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['_meta' => ['x']] + $valid,
            '"result._meta" must be a string-keyed object.',
        ];

        yield 'ttlMs not an int' => [
            ['ttlMs' => '300'] + $valid,
            '"result.ttlMs" must be null or a positive integer, \'300\' given.',
        ];

        yield 'ttlMs zero' => [
            ['ttlMs' => 0] + $valid,
            '"result.ttlMs" must be null or a positive integer, 0 given.',
        ];

        yield 'result payload not an object' => [
            ['status' => 'completed', 'result' => 'oops'] + $valid,
            '"result.result" must be an object, string given.',
        ];

        yield 'error payload not an object' => [
            ['status' => 'failed', 'error' => 'oops'] + $valid,
            '"result.error" must be an object, string given.',
        ];

        yield 'inputRequests not an object' => [
            ['status' => 'input_required', 'inputRequests' => 'oops'] + $valid,
            '"result.inputRequests" must be an object, string given.',
        ];

        yield 'inputRequests entry missing method' => [
            ['status' => 'input_required', 'inputRequests' => ['github_login' => ['params' => []]]] + $valid,
            'each "result.inputRequests" entry is missing the required "method" key.',
        ];
    }

    public function testFromArrayRejectsUnsupportedInputRequestMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.inputRequests" entry must use a supported input-request method, \'sampling/createMessage\' given.');

        GetTaskResult::fromArray([
            'taskId' => 'task-1',
            'status' => 'input_required',
            'createdAt' => '2026-08-04T12:00:00+00:00',
            'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
            'ttlMs' => null,
            'inputRequests' => ['github_login' => ['method' => 'sampling/createMessage']],
        ]);
    }

    private static function createWorking(): GetTaskResult
    {
        return new GetTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Working,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
        );
    }

    private static function createElicitRequest(): ElicitRequest
    {
        return new ElicitRequest(new ElicitRequestFormParams(
            message: 'Please provide your GitHub username',
            requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
        ));
    }
}
