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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\LoggingMessageNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LoggingMessageNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LoggingMessageNotificationParamsTest extends TestCase
{
    public function testConstructionDefaultsLoggerAndMetaToNull(): void
    {
        $params = new LoggingMessageNotificationParams(LoggingLevel::Info, 'something happened');

        self::assertSame(LoggingLevel::Info, $params->level);
        self::assertSame('something happened', $params->data);
        self::assertNull($params->logger);
        self::assertNull($params->meta);
    }

    public function testToArrayWithMinimalFields(): void
    {
        $params = new LoggingMessageNotificationParams(LoggingLevel::Warning, 'msg');

        self::assertSame(
            ['level' => 'warning', 'data' => 'msg'],
            $params->toArray(),
        );
    }

    public function testToArrayWithLogger(): void
    {
        $params = new LoggingMessageNotificationParams(LoggingLevel::Error, 'boom', 'app.db');

        self::assertSame(
            ['level' => 'error', 'data' => 'boom', 'logger' => 'app.db'],
            $params->toArray(),
        );
    }

    public function testToArrayWithEmptyLogger(): void
    {
        $params = new LoggingMessageNotificationParams(LoggingLevel::Debug, 'x', '');

        self::assertSame(
            ['level' => 'debug', 'data' => 'x', 'logger' => ''],
            $params->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $params = new LoggingMessageNotificationParams(
            LoggingLevel::Notice,
            'x',
            null,
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'level' => 'notice', 'data' => 'x'],
            $params->toArray(),
        );
    }

    public function testToArrayKeyOrderHasMetaThenLevelThenDataThenLogger(): void
    {
        $params = new LoggingMessageNotificationParams(
            LoggingLevel::Critical,
            'x',
            'app',
            new MetaObject(['k' => 'v']),
        );

        self::assertSame(
            ['_meta', 'level', 'data', 'logger'],
            array_keys($params->toArray()),
        );
    }

    #[DataProvider('provideToArrayPreservesAnyDataTypeCases')]
    public function testToArrayPreservesAnyDataType(mixed $data): void
    {
        $params = new LoggingMessageNotificationParams(LoggingLevel::Info, $data);

        $result = $params->toArray();
        self::assertArrayHasKey('data', $result);
        self::assertSame($data, $result['data']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideToArrayPreservesAnyDataTypeCases(): iterable
    {
        yield 'string' => ['hello'];

        yield 'int' => [42];

        yield 'float' => [3.14];

        yield 'bool' => [true];

        yield 'null' => [null];

        yield 'array' => [['error' => 'connection refused', 'attempts' => 3]];

        yield 'list' => [[1, 2, 3]];
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new LoggingMessageNotificationParams(
            LoggingLevel::Info,
            'x',
            'app',
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayParsesMinimalShape(): void
    {
        $params = LoggingMessageNotificationParams::fromArray([
            'level' => 'info',
            'data' => 'hello',
        ]);

        self::assertSame(LoggingLevel::Info, $params->level);
        self::assertSame('hello', $params->data);
        self::assertNull($params->logger);
        self::assertNull($params->meta);
    }

    public function testFromArrayParsesLoggerAndMeta(): void
    {
        $params = LoggingMessageNotificationParams::fromArray([
            'level' => 'alert',
            'data' => 'oops',
            'logger' => 'app',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('app', $params->logger);
        self::assertNotNull($params->meta);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayPreservesNullData(): void
    {
        $params = LoggingMessageNotificationParams::fromArray([
            'level' => 'info',
            'data' => null,
        ]);

        self::assertNull($params->data);
    }

    public function testFromArrayPreservesArrayData(): void
    {
        $params = LoggingMessageNotificationParams::fromArray([
            'level' => 'info',
            'data' => ['error' => 'x', 'count' => 3],
        ]);

        self::assertSame(['error' => 'x', 'count' => 3], $params->data);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new LoggingMessageNotificationParams(
            LoggingLevel::Emergency,
            ['kind' => 'oom', 'pid' => 123],
            'app.db',
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = LoggingMessageNotificationParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsUnknownLevel(): void
    {
        $this->expectException(\ValueError::class);

        LoggingMessageNotificationParams::fromArray(['level' => 'verbose', 'data' => 'x']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        LoggingMessageNotificationParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing level' => [
            ['data' => 'x'],
            'LoggingMessageNotificationParams data missing "level".',
        ];

        yield 'level not a string' => [
            ['level' => 1, 'data' => 'x'],
            'LoggingMessageNotificationParams "level" must be a string, int given.',
        ];

        yield 'missing data' => [
            ['level' => 'info'],
            'LoggingMessageNotificationParams data missing "data".',
        ];

        yield 'logger not a string' => [
            ['level' => 'info', 'data' => 'x', 'logger' => 1],
            'LoggingMessageNotificationParams "logger" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['level' => 'info', 'data' => 'x', '_meta' => 'oops'],
            'Notification params "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['level' => 'info', 'data' => 'x', '_meta' => ['x']],
            'Notification params "_meta" must be a string-keyed object.',
        ];
    }
}
