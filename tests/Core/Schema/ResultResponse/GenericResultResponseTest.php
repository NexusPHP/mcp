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

namespace Nexus\Mcp\Tests\Core\Schema\ResultResponse;

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GenericResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GenericResultResponseTest extends TestCase
{
    public function testToArrayEmitsEnvelopeWithEmptyResult(): void
    {
        $response = new GenericResultResponse(id: new RequestId(id: 42), result: new EmptyResult());

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'result' => ['resultType' => 'complete']],
            $response->toArray(),
        );
    }

    public function testToArrayEmitsEnvelopeWithResultMeta(): void
    {
        $response = new GenericResultResponse(
            id: new RequestId(id: 'req-1'),
            result: new EmptyResult(meta: new ResultMetaObject(extras: ['vendor' => 'x'])),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'result' => ['_meta' => ['vendor' => 'x'], 'resultType' => 'complete'],
            ],
            $response->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayForLeafResult(): void
    {
        $response = new GenericResultResponse(id: new RequestId(id: 1), result: new EmptyResult(meta: new ResultMetaObject(extras: ['vendor' => 'x'])));

        self::assertSame($response->toArray(), $response->jsonSerialize());
    }

    public function testJsonSerializePreservesEmptyObjectMarkersFromInnerResult(): void
    {
        $response = new GenericResultResponse(
            id: new RequestId(id: 99),
            result: new DiscoverResult(
                supportedVersions: [ProtocolVersion::LATEST_VERSION],
                capabilities: new ServerCapabilities(completions: []),
                ttlMs: 0,
                cacheScope: CacheScope::Private,
            ),
        );

        $encoded = json_encode($response);

        self::assertIsString($encoded);
        self::assertStringContainsString('"completions":{}', $encoded, 'Empty capability markers must encode as JSON objects, not arrays.');
    }

    public function testFromArrayDecodesABareSuccessResponseToEmptyResult(): void
    {
        $response = GenericResultResponse::fromArray(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['resultType' => 'complete']]);

        self::assertInstanceOf(EmptyResult::class, $response->result);
        self::assertSame(7, $response->id->id);
    }

    public function testFromArrayRejectsInputRequiredResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        GenericResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
