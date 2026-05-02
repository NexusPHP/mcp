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

use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProgressNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProgressNotificationParamsTest extends TestCase
{
    public function testConstructionDefaultsTotalAndMessageAndMetaToNull(): void
    {
        $params = new ProgressNotificationParams(new ProgressToken('p-1'), 0.5);

        self::assertSame('p-1', $params->progressToken->token);
        self::assertSame(0.5, $params->progress);
        self::assertNull($params->total);
        self::assertNull($params->message);
        self::assertNull($params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new ProgressNotificationParams(new ProgressToken('p-1'), 0.5);

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 0.5],
            $params->toArray(),
        );
    }

    public function testToArrayWithIntProgressToken(): void
    {
        $params = new ProgressNotificationParams(new ProgressToken(42), 1.0);

        self::assertSame(
            ['progressToken' => 42, 'progress' => 1.0],
            $params->toArray(),
        );
    }

    public function testToArrayWithTotal(): void
    {
        $params = new ProgressNotificationParams(new ProgressToken('p-1'), 5.0, 10.0);

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 5.0, 'total' => 10.0],
            $params->toArray(),
        );
    }

    public function testToArrayWithMessage(): void
    {
        $params = new ProgressNotificationParams(new ProgressToken('p-1'), 5.0, null, 'fetching');

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 5.0, 'message' => 'fetching'],
            $params->toArray(),
        );
    }

    public function testToArrayWithEmptyMessage(): void
    {
        $params = new ProgressNotificationParams(new ProgressToken('p-1'), 5.0, null, '');

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 5.0, 'message' => ''],
            $params->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $params = new ProgressNotificationParams(
            new ProgressToken('p-1'),
            0.25,
            null,
            null,
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'progressToken' => 'p-1', 'progress' => 0.25],
            $params->toArray(),
        );
    }

    public function testToArrayKeyOrder(): void
    {
        $params = new ProgressNotificationParams(
            new ProgressToken('p-1'),
            5.0,
            10.0,
            'fetching',
            new Meta(['k' => 'v']),
        );

        self::assertSame(
            ['_meta', 'progressToken', 'progress', 'total', 'message'],
            array_keys($params->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ProgressNotificationParams(
            new ProgressToken('p-1'),
            5.0,
            10.0,
            'fetching',
            new Meta(['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = ProgressNotificationParams::fromArray([
            'progressToken' => 'p-1',
            'progress' => 0.5,
        ]);

        self::assertSame('p-1', $params->progressToken->token);
        self::assertSame(0.5, $params->progress);
        self::assertNull($params->total);
        self::assertNull($params->message);
    }

    public function testFromArrayWithIntProgressToken(): void
    {
        $params = ProgressNotificationParams::fromArray([
            'progressToken' => 9,
            'progress' => 1.0,
        ]);

        self::assertSame(9, $params->progressToken->token);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $params = ProgressNotificationParams::fromArray([
            '_meta' => ['vendor' => 'x'],
            'progressToken' => 'p-1',
            'progress' => 5.5,
            'total' => 10.0,
            'message' => 'halfway',
        ]);

        self::assertSame('p-1', $params->progressToken->token);
        self::assertSame(5.5, $params->progress);
        self::assertSame(10.0, $params->total);
        self::assertSame('halfway', $params->message);
        self::assertNotNull($params->meta);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ProgressNotificationParams(
            new ProgressToken('p-7'),
            3.14,
            42.0,
            'crunching',
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = ProgressNotificationParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        ProgressNotificationParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing progressToken' => [
            ['progress' => 0.5],
            'ProgressNotificationParams wire data missing "progressToken".',
        ];

        yield 'progressToken not int or string' => [
            ['progressToken' => [], 'progress' => 0.5],
            'ProgressNotificationParams wire "progressToken" must be int or string, array given.',
        ];

        yield 'missing progress' => [
            ['progressToken' => 'p-1'],
            'ProgressNotificationParams wire data missing "progress".',
        ];

        yield 'progress is string' => [
            ['progressToken' => 'p-1', 'progress' => 'oops'],
            'ProgressNotificationParams wire "progress" must be a number, string given.',
        ];

        yield 'progress is bool' => [
            ['progressToken' => 'p-1', 'progress' => true],
            'ProgressNotificationParams wire "progress" must be a number, bool given.',
        ];

        yield 'total is string' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, 'total' => 'oops'],
            'ProgressNotificationParams wire "total" must be a number or null, string given.',
        ];

        yield 'message not a string' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, 'message' => 1],
            'ProgressNotificationParams wire "message" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, '_meta' => 'oops'],
            'Notification params "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, '_meta' => ['x']],
            'Notification params "_meta" must be a string-keyed object.',
        ];
    }

    public function testFromArrayCoercesIntegerProgressToFloat(): void
    {
        $params = ProgressNotificationParams::fromArray([
            'progressToken' => 'p-1',
            'progress' => 5,
            'total' => 10,
        ]);

        self::assertSame(5.0, $params->progress);
        self::assertSame(10.0, $params->total);
    }
}
