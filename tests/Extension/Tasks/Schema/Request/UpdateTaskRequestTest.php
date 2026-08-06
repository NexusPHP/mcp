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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\UpdateTaskRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UpdateTaskRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UpdateTaskRequestTest extends AbstractMcpTestCase
{
    public function testMethodIsTasksUpdate(): void
    {
        self::assertSame('tasks/update', UpdateTaskRequest::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $request = new UpdateTaskRequest(
            id: new RequestId(id: 1),
            params: new UpdateTaskRequestParams(
                taskId: 'task-1',
                inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
                meta: RequestMetaObjectFactory::create(),
            ),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tasks/update',
                'params' => [
                    '_meta' => RequestMetaObjectFactory::shape(),
                    'taskId' => 'task-1',
                    'inputResponses' => ['github_login' => ['action' => 'accept']],
                ],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new UpdateTaskRequest(
            id: new RequestId(id: 'req-1'),
            params: new UpdateTaskRequestParams(
                taskId: 'task-1',
                inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
                meta: RequestMetaObjectFactory::create(),
            ),
        );

        $rebuilt = UpdateTaskRequest::fromArray($original->toArray());

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

        UpdateTaskRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $validParams = ['taskId' => 'task-1', 'inputResponses' => ['github_login' => ['action' => 'accept']]];

        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'tasks/update', 'params' => $validParams],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'tasks/update', 'params' => $validParams],
            '"id" must be an int or non-empty string, array given.',
        ];

        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/update'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/update', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/update', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
