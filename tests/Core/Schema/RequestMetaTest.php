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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestMeta::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestMetaTest extends TestCase
{
    public function testDefaultsToNoProgressTokenAndNoExtras(): void
    {
        $meta = new RequestMeta();

        self::assertNull($meta->progressToken);
        self::assertSame([], $meta->extras);
    }

    public function testCapturesProgressTokenAndExtras(): void
    {
        $meta = new RequestMeta(new ProgressToken('tok-1'), ['vendor' => 'x']);

        self::assertInstanceOf(ProgressToken::class, $meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testToArrayEmitsProgressTokenAtExpectedKey(): void
    {
        $meta = new RequestMeta(new ProgressToken(42));

        self::assertSame(['progressToken' => 42], $meta->toArray());
    }

    public function testToArrayMergesExtrasAndProgressToken(): void
    {
        $meta = new RequestMeta(new ProgressToken('tok-1'), ['vendor' => 'x']);

        self::assertSame(['vendor' => 'x', 'progressToken' => 'tok-1'], $meta->toArray());
    }

    public function testToArrayOmitsProgressTokenWhenNull(): void
    {
        $meta = new RequestMeta(null, ['a' => 1]);

        self::assertSame(['a' => 1], $meta->toArray());
    }

    public function testFromArrayExtractsProgressTokenAndKeepsExtras(): void
    {
        $meta = RequestMeta::fromArray(['progressToken' => 'tok-1', 'vendor' => 'x']);

        self::assertNotNull($meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testFromArrayWithoutProgressTokenLeavesItNull(): void
    {
        $meta = RequestMeta::fromArray(['a' => 1, 'b' => 2]);

        self::assertNull($meta->progressToken);
        self::assertSame(['a' => 1, 'b' => 2], $meta->extras);
    }

    public function testFromArrayRejectsNonIntOrStringProgressToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Progress token must be an int or string, array given.');

        RequestMeta::fromArray(['progressToken' => []]);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $meta = new RequestMeta(new ProgressToken('tok-1'), ['vendor' => 'x']);

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testRoundTripPreservesEverything(): void
    {
        $original = new RequestMeta(new ProgressToken(7), ['key' => 'value']);

        self::assertSame($original->toArray(), RequestMeta::fromArray($original->toArray())->toArray());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $meta = new RequestMeta();

        self::assertInstanceOf(\stdClass::class, $meta->jsonSerialize());
        self::assertSame('{}', json_encode($meta));
    }

    public function testParseFromWireReturnsNullWhenMetaAbsent(): void
    {
        self::assertNull(RequestMeta::parseFromWire(['name' => 'x'], 'Request params'));
    }

    public function testParseFromWireReadsAndContextualizes(): void
    {
        $meta = RequestMeta::parseFromWire(
            ['_meta' => ['progressToken' => 'tok-1', 'vendor' => 'x']],
            'Request params',
        );

        self::assertNotNull($meta);
        self::assertNotNull($meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testParseFromWireRejectsNonObjectMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Request params "_meta" must be an object, string given.');

        RequestMeta::parseFromWire(['_meta' => 'oops'], 'Request params');
    }

    public function testParseFromWireRejectsListKeyedMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Request params "_meta" must be a string-keyed object.');

        RequestMeta::parseFromWire(['_meta' => ['x']], 'Request params');
    }
}
