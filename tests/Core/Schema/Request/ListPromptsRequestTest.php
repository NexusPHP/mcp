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
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListPromptsRequest::class)]
#[CoversClass(PaginatedRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListPromptsRequestTest extends TestCase
{
    public function testMethodIsPromptsList(): void
    {
        self::assertSame('prompts/list', ListPromptsRequest::method());
    }

    public function testToArrayOmitsParamsWhenEmpty(): void
    {
        $request = new ListPromptsRequest(new RequestId(1));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list'],
            $request->toArray(),
        );
    }

    public function testToArrayIncludesCursor(): void
    {
        $request = new ListPromptsRequest(
            new RequestId(1),
            new PaginatedRequestParams(new Cursor('cur-1')),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'prompts/list',
                'params' => ['cursor' => 'cur-1'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayParsesWithoutParams(): void
    {
        $request = ListPromptsRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/list',
        ]);

        self::assertSame(PaginatedRequestParams::class, $request->params::class);
        self::assertNull($request->params->cursor);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListPromptsRequest(
            new RequestId('req-1'),
            new PaginatedRequestParams(new Cursor('cur-1')),
        );

        $rebuilt = ListPromptsRequest::fromArray($original->toArray());

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

        ListPromptsRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'prompts/list'],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'prompts/list'],
            '"id" must be int or string, array given.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
