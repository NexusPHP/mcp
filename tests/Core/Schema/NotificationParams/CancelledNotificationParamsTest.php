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

namespace Nexus\Mcp\Tests\Core\Schema\NotificationParams;

use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CancelledNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CancelledNotificationParamsTest extends TestCase
{
    public function testDefaultsAllFieldsToNull(): void
    {
        $params = new CancelledNotificationParams();

        self::assertNull($params->requestId);
        self::assertNull($params->reason);
        self::assertNull($params->meta);
    }

    public function testToArrayWhenAllFieldsAreNull(): void
    {
        self::assertSame([], new CancelledNotificationParams()->toArray());
    }

    public function testToArrayWithIntRequestId(): void
    {
        $params = new CancelledNotificationParams(new RequestId(42));

        self::assertSame(['requestId' => 42], $params->toArray());
    }

    public function testToArrayWithStringRequestId(): void
    {
        $params = new CancelledNotificationParams(new RequestId('abc'));

        self::assertSame(['requestId' => 'abc'], $params->toArray());
    }

    public function testToArrayIncludesReasonWhenSet(): void
    {
        $params = new CancelledNotificationParams(new RequestId(1), 'user aborted');

        self::assertSame(
            ['requestId' => 1, 'reason' => 'user aborted'],
            $params->toArray(),
        );
    }

    public function testToArrayIncludesEmptyReason(): void
    {
        $params = new CancelledNotificationParams(new RequestId(1), '');

        self::assertSame(
            ['requestId' => 1, 'reason' => ''],
            $params->toArray(),
        );
    }

    public function testToArrayIncludesMeta(): void
    {
        $params = new CancelledNotificationParams(new RequestId(1), null, new MetaObject(['vendor' => 'x']));

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'requestId' => 1],
            $params->toArray(),
        );
    }

    public function testToArrayIncludesEverything(): void
    {
        $params = new CancelledNotificationParams(
            new RequestId('req-1'),
            'timeout',
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'requestId' => 'req-1', 'reason' => 'timeout'],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsMetaWhenMetaIsEmpty(): void
    {
        $params = new CancelledNotificationParams(new RequestId(1), null, new MetaObject());

        self::assertSame(['requestId' => 1], $params->toArray());
    }

    public function testToArrayKeyOrderHasMetaFirstThenRequestIdThenReason(): void
    {
        $params = new CancelledNotificationParams(
            new RequestId(1),
            'cancelled',
            new MetaObject(['k' => 'v']),
        );

        self::assertSame(
            ['_meta', 'requestId', 'reason'],
            array_keys($params->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new CancelledNotificationParams(
            new RequestId(1),
            'cancelled',
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayParsesIntRequestId(): void
    {
        $params = CancelledNotificationParams::fromArray(['requestId' => 7]);

        self::assertNotNull($params->requestId);
        self::assertSame(7, $params->requestId->id);
        self::assertNull($params->reason);
        self::assertNull($params->meta);
    }

    public function testFromArrayParsesStringRequestId(): void
    {
        $params = CancelledNotificationParams::fromArray(['requestId' => 'req-9']);

        self::assertNotNull($params->requestId);
        self::assertSame('req-9', $params->requestId->id);
    }

    public function testFromArrayParsesReason(): void
    {
        $params = CancelledNotificationParams::fromArray([
            'requestId' => 1,
            'reason' => 'user aborted',
        ]);

        self::assertSame('user aborted', $params->reason);
    }

    public function testFromArrayParsesEmptyReason(): void
    {
        $params = CancelledNotificationParams::fromArray([
            'requestId' => 1,
            'reason' => '',
        ]);

        self::assertSame('', $params->reason);
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = CancelledNotificationParams::fromArray([
            'requestId' => 1,
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertNotNull($params->meta);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayRoundTripsAllFields(): void
    {
        $original = new CancelledNotificationParams(
            new RequestId('id-7'),
            'because',
            new MetaObject(['a' => 'b']),
        );

        $reconstructed = CancelledNotificationParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $reconstructed->toArray());
    }

    public function testFromArrayWithoutRequestIdYieldsNull(): void
    {
        $params = CancelledNotificationParams::fromArray([]);

        self::assertNull($params->requestId);
        self::assertNull($params->reason);
        self::assertNull($params->meta);
    }

    #[DataProvider('provideFromArrayRejectsNonArrayKeyRequestIdCases')]
    public function testFromArrayRejectsNonArrayKeyRequestId(mixed $value, string $expectedTypeFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf(
            'CancelledNotificationParams wire "requestId" must be int or string, %s given.',
            $expectedTypeFragment,
        ));

        CancelledNotificationParams::fromArray(['requestId' => $value]);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function provideFromArrayRejectsNonArrayKeyRequestIdCases(): iterable
    {
        yield 'float' => [1.5, 'float'];

        yield 'bool' => [true, 'bool'];

        yield 'null' => [null, 'null'];

        yield 'array' => [[], 'array'];
    }

    public function testFromArrayRejectsNonStringReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CancelledNotificationParams wire "reason" must be a string or null, int given.');

        CancelledNotificationParams::fromArray([
            'requestId' => 1,
            'reason' => 42,
        ]);
    }

    public function testFromArrayRejectsNonArrayMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification params "_meta" must be an object, int given.');

        CancelledNotificationParams::fromArray([
            'requestId' => 1,
            '_meta' => 42,
        ]);
    }

    public function testFromArrayRejectsListKeyedMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification params "_meta" must be a string-keyed object.');

        CancelledNotificationParams::fromArray([
            'requestId' => 1,
            '_meta' => ['a', 'b'],
        ]);
    }
}
