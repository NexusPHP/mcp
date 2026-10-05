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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema\Request;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\PaginatedRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListSkillsRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(PaginatedRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ListSkillsRequestTest extends AbstractMcpTestCase
{
    public function testMethodIsSkillsList(): void
    {
        self::assertSame('skills/list', ListSkillsRequest::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $request = new ListSkillsRequest(
            id: new RequestId(id: 1),
            params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'next')),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'skills/list',
                'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'cursor' => 'next'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListSkillsRequest(
            id: new RequestId(id: 'req-1'),
            params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()),
        );

        self::assertSame($original->toArray(), ListSkillsRequest::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListSkillsRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing id' => [['params' => []], 'missing the required "id" key.'];

        yield 'id not int or string' => [['id' => [], 'params' => []], '"id" must be an int or non-empty string, array given.'];

        yield 'missing params' => [['id' => 1], 'missing the required "params" key.'];

        yield 'params not an object' => [['id' => 1, 'params' => 'bad'], '"params" must be an object, string given.'];

        yield 'params list-keyed' => [['id' => 1, 'params' => ['x']], '"params" must be a string-keyed object.'];
    }
}
