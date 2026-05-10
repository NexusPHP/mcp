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

namespace Nexus\Mcp\Tests\Core\Schema\Request;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CancelTaskRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CancelTaskRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CancelTaskRequestTest extends TestCase
{
    public function testMethodIsTasksCancel(): void
    {
        self::assertSame('tasks/cancel', CancelTaskRequest::method());
    }

    public function testToArray(): void
    {
        $request = new CancelTaskRequest(new RequestId(1), new CancelTaskRequestParams('task-abc'));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tasks/cancel',
                'params' => ['taskId' => 'task-abc'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CancelTaskRequest(new RequestId('req-1'), new CancelTaskRequestParams('task-abc'));

        $rebuilt = CancelTaskRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        CancelTaskRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'tasks/cancel'],
            'CancelTaskRequest wire data missing "id".',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'tasks/cancel'],
            'CancelTaskRequest wire "id" must be int or string, array given.',
        ];

        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/cancel'],
            'CancelTaskRequest wire data missing "params".',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/cancel', 'params' => 'bad'],
            'CancelTaskRequest wire "params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/cancel', 'params' => ['x']],
            'CancelTaskRequest wire "params" must be a string-keyed object.',
        ];
    }
}
