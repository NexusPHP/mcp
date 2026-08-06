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

namespace Nexus\Mcp\Tests\Extension\Tasks\Schema\Request;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\GetTaskRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetTaskRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetTaskRequestTest extends AbstractMcpTestCase
{
    public function testMethodIsTasksGet(): void
    {
        self::assertSame('tasks/get', GetTaskRequest::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $request = new GetTaskRequest(
            id: new RequestId(id: 1),
            params: new GetTaskRequestParams(taskId: 'task-1', meta: RequestMetaObjectFactory::create()),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tasks/get',
                'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'taskId' => 'task-1'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetTaskRequest(
            id: new RequestId(id: 'req-1'),
            params: new GetTaskRequestParams(taskId: 'task-1', meta: RequestMetaObjectFactory::create()),
        );

        $rebuilt = GetTaskRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetTaskRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $validParams = ['taskId' => 'task-1'];

        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'tasks/get', 'params' => $validParams],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'tasks/get', 'params' => $validParams],
            '"id" must be an int or non-empty string, array given.',
        ];

        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/get'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/get', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/get', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
