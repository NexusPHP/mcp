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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PingRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PingRequestTest extends TestCase
{
    public function testDefaultsToBaseRequestParamsWithNullMeta(): void
    {
        $request = new PingRequest(new RequestId(1));

        self::assertSame(EmptyRequestParams::class, $request->params::class);
        self::assertNull($request->params->meta);
    }

    public function testToArrayOmitsParamsWhenEmpty(): void
    {
        $request = new PingRequest(new RequestId(42));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'method' => 'ping'],
            $request->toArray(),
        );
    }

    public function testToArrayIncludesParamsWithMeta(): void
    {
        $request = new PingRequest(
            new RequestId('req-1'),
            new EmptyRequestParams(new RequestMetaObject(new ProgressToken('tok-1'))),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'method' => 'ping',
                'params' => ['_meta' => ['progressToken' => 'tok-1']],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayParsesIntId(): void
    {
        $request = PingRequest::fromArray(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);

        self::assertSame(7, $request->id->id);
    }

    public function testFromArrayParsesStringId(): void
    {
        $request = PingRequest::fromArray(['jsonrpc' => '2.0', 'id' => 'req-1', 'method' => 'ping']);

        self::assertSame('req-1', $request->id->id);
    }

    public function testFromArrayParsesParamsMeta(): void
    {
        $request = PingRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
            'params' => ['_meta' => ['progressToken' => 'tok-1']],
        ]);

        self::assertNotNull($request->params->meta);
        self::assertNotNull($request->params->meta->progressToken);
        self::assertSame('tok-1', $request->params->meta->progressToken->token);
    }

    public function testFromArrayRejectsMissingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PingRequest wire data missing "id".');

        PingRequest::fromArray(['jsonrpc' => '2.0', 'method' => 'ping']);
    }

    public function testFromArrayRejectsBadIdType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PingRequest wire "id" must be int or string, array given.');

        PingRequest::fromArray(['jsonrpc' => '2.0', 'id' => [], 'method' => 'ping']);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PingRequest wire "params" must be an object, string given.');

        PingRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'bad']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $request = new PingRequest(new RequestId(1));

        self::assertSame($request->toArray(), $request->jsonSerialize());
    }
}
