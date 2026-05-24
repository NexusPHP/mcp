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
use Nexus\Mcp\Core\Schema\Request\ListRootsRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListRootsRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListRootsRequestTest extends TestCase
{
    public function testMethodIsRootsList(): void
    {
        self::assertSame('roots/list', ListRootsRequest::method());
    }

    public function testToArrayOmitsParamsWhenEmpty(): void
    {
        $request = new ListRootsRequest(new RequestId(1));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'roots/list'],
            $request->toArray(),
        );
    }

    public function testFromArrayParsesWithoutParams(): void
    {
        $request = ListRootsRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'roots/list',
        ]);

        self::assertSame(EmptyRequestParams::class, $request->params::class);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListRootsRequest(new RequestId('req-1'));

        $rebuilt = ListRootsRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListRootsRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'roots/list'],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'roots/list'],
            '"id" must be an int or string, array given.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'roots/list', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'roots/list', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
