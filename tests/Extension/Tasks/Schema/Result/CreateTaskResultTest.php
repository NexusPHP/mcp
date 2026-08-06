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
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CreateTaskResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class CreateTaskResultTest extends AbstractMcpTestCase
{
    public function testToArrayEmitsTaskResultTypeAndAlwaysCarriesTtl(): void
    {
        $result = self::createResult();

        self::assertSame(
            [
                'resultType' => 'task',
                'taskId' => 'task-1',
                'status' => 'working',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
                'ttlMs' => null,
            ],
            $result->toArray(),
        );
    }

    public function testToArrayCarriesOptionalFields(): void
    {
        $result = new CreateTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Working,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: 300_000,
            statusMessage: 'Crunching numbers.',
            pollIntervalMs: 1_000,
        );

        self::assertSame(
            [
                'resultType' => 'task',
                'taskId' => 'task-1',
                'status' => 'working',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
                'ttlMs' => 300_000,
                'statusMessage' => 'Crunching numbers.',
                'pollIntervalMs' => 1_000,
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = self::createResult();

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new CreateTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Working,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: 300_000,
            statusMessage: 'Crunching numbers.',
            pollIntervalMs: 1_000,
            meta: new GenericResultMetaObject(extras: ['vendor.brand' => 'acme']),
        );

        self::assertSame($original->toArray(), CreateTaskResult::fromArray($original->toArray())->toArray());
    }

    public function testRebuildWithMetaCarriesEveryField(): void
    {
        $result = new CreateTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Working,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: 300_000,
            statusMessage: 'Crunching numbers.',
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
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CreateTaskResult::fromArray($payload);
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

        yield 'ttlMs not an int' => [
            ['ttlMs' => '300'] + $valid,
            '"result.ttlMs" must be null or a positive integer, \'300\' given.',
        ];

        yield 'ttlMs zero' => [
            ['ttlMs' => 0] + $valid,
            '"result.ttlMs" must be null or a positive integer, 0 given.',
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
    }

    private static function createResult(): CreateTaskResult
    {
        return new CreateTaskResult(
            taskId: 'task-1',
            status: TaskStatus::Working,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
        );
    }
}
