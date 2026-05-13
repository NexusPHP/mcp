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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\PaginatedRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\ListTasksRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListTasksRequest::class)]
#[CoversClass(PaginatedRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListTasksRequestTest extends TestCase
{
    public function testMethodIsTasksList(): void
    {
        self::assertSame('tasks/list', ListTasksRequest::method());
    }

    public function testToArrayMinimal(): void
    {
        $request = new ListTasksRequest(new RequestId(1));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tasks/list',
            ],
            $request->toArray(),
        );
    }

    public function testToArrayWithCursor(): void
    {
        $request = new ListTasksRequest(new RequestId(1), new PaginatedRequestParams(new Cursor('cursor-1')));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tasks/list',
                'params' => ['cursor' => 'cursor-1'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListTasksRequest(
            new RequestId('req-1'),
            new PaginatedRequestParams(new Cursor('cursor-1')),
        );

        $rebuilt = ListTasksRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayWithoutParams(): void
    {
        $request = ListTasksRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/list']);

        self::assertSame(1, $request->id->id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListTasksRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'tasks/list'],
            'ListTasksRequest data missing "id".',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'tasks/list'],
            'ListTasksRequest "id" must be int or string, array given.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/list', 'params' => 'bad'],
            'ListTasksRequest "params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/list', 'params' => ['x']],
            'ListTasksRequest "params" must be a string-keyed object.',
        ];
    }
}
