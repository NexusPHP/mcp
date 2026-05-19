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
use Nexus\Mcp\Core\Schema\Request\UnsubscribeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\UnsubscribeRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsubscribeRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UnsubscribeRequestTest extends TestCase
{
    public function testMethodIsResourcesUnsubscribe(): void
    {
        self::assertSame('resources/unsubscribe', UnsubscribeRequest::method());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $request = new UnsubscribeRequest(
            new RequestId(1),
            new UnsubscribeRequestParams('file:///x'),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'resources/unsubscribe',
                'params' => ['uri' => 'file:///x'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new UnsubscribeRequest(
            new RequestId('req-1'),
            new UnsubscribeRequestParams('file:///x'),
        );

        $rebuilt = UnsubscribeRequest::fromArray($original->toArray());

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

        UnsubscribeRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $validParams = ['uri' => 'file:///x'];

        yield 'missing id' => [
            ['jsonrpc' => '2.0', 'method' => 'resources/unsubscribe', 'params' => $validParams],
            'missing the required "id" key.',
        ];

        yield 'id not int or string' => [
            ['jsonrpc' => '2.0', 'id' => [], 'method' => 'resources/unsubscribe', 'params' => $validParams],
            '"id" must be int or string, array given.',
        ];

        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/unsubscribe'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/unsubscribe', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/unsubscribe', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
