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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\GetTaskPayloadResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetTaskPayloadResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetTaskPayloadResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new GetTaskPayloadResult();

        self::assertSame([], $result->payload);
        self::assertNull($result->meta);
    }

    public function testConstructionWithPayload(): void
    {
        $result = new GetTaskPayloadResult(['content' => [['type' => 'text', 'text' => 'hello']]]);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'hello']]], $result->payload);
    }

    public function testToArrayEmpty(): void
    {
        self::assertSame([], new GetTaskPayloadResult()->toArray());
    }

    public function testToArrayWithPayload(): void
    {
        $result = new GetTaskPayloadResult(['content' => [['type' => 'text', 'text' => 'hello']]]);

        self::assertSame(
            ['content' => [['type' => 'text', 'text' => 'hello']]],
            $result->toArray(),
        );
    }

    public function testToArrayWithMetaAndPayload(): void
    {
        $result = new GetTaskPayloadResult(
            ['isError' => false],
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'isError' => false],
            $result->toArray(),
        );
    }

    public function testJsonSerializeReturnsStdClassWhenEmpty(): void
    {
        $result = new GetTaskPayloadResult();

        self::assertInstanceOf(\stdClass::class, $result->jsonSerialize());
        self::assertSame('{}', json_encode($result));
    }

    public function testJsonSerializeReturnsArrayWhenPopulated(): void
    {
        $result = new GetTaskPayloadResult(['isError' => true]);

        self::assertSame(['isError' => true], $result->jsonSerialize());
    }

    public function testFromArrayPreservesPayloadFields(): void
    {
        $result = GetTaskPayloadResult::fromArray([
            'content' => [['type' => 'text', 'text' => 'hello']],
            'isError' => false,
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame(
            ['content' => [['type' => 'text', 'text' => 'hello']], 'isError' => false],
            $result->payload,
        );
        self::assertNotNull($result->meta);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetTaskPayloadResult(
            ['content' => [['type' => 'text', 'text' => 'hello']]],
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = GetTaskPayloadResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }
}
