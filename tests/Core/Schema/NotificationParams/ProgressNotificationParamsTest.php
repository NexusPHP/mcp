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

use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
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
        $params = new ProgressNotificationParams(progressToken: new ProgressToken(token: 'p-1'), progress: 0.5);

        self::assertSame('p-1', $params->progressToken->token);
        self::assertSame(0.5, $params->progress);
        self::assertNull($params->total);
        self::assertNull($params->message);
        self::assertSame([], $params->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $params = new ProgressNotificationParams(progressToken: new ProgressToken(token: 'p-1'), progress: 0.5);

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 0.5],
            $params->toArray(),
        );
    }

    public function testToArrayWithIntProgressToken(): void
    {
        $params = new ProgressNotificationParams(progressToken: new ProgressToken(token: 42), progress: 1.0);

        self::assertSame(
            ['progressToken' => 42, 'progress' => 1.0],
            $params->toArray(),
        );
    }

    public function testToArrayWithTotal(): void
    {
        $params = new ProgressNotificationParams(progressToken: new ProgressToken(token: 'p-1'), progress: 5.0, total: 10.0);

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 5.0, 'total' => 10.0],
            $params->toArray(),
        );
    }

    public function testToArrayWithMessage(): void
    {
        $params = new ProgressNotificationParams(progressToken: new ProgressToken(token: 'p-1'), progress: 5.0, message: 'fetching');

        self::assertSame(
            ['progressToken' => 'p-1', 'progress' => 5.0, 'message' => 'fetching'],
            $params->toArray(),
        );
    }

    public function testConstructorRejectsEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params.message" must be a non-empty string or null.');

        new ProgressNotificationParams(
            progressToken: new ProgressToken(token: 'p-1'),
            progress: 5.0,
            message: '',
        );
    }

    public function testToArrayWithMeta(): void
    {
        $params = new ProgressNotificationParams(
            progressToken: new ProgressToken(token: 'p-1'),
            progress: 0.25,
            meta: new NotificationMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'progressToken' => 'p-1', 'progress' => 0.25],
            $params->toArray(),
        );
    }

    public function testToArrayKeyOrder(): void
    {
        $params = new ProgressNotificationParams(
            progressToken: new ProgressToken(token: 'p-1'),
            progress: 5.0,
            total: 10.0,
            message: 'fetching',
            meta: new NotificationMetaObject(extras: ['k' => 'v']),
        );

        self::assertSame(
            ['_meta', 'progressToken', 'progress', 'total', 'message'],
            array_keys($params->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ProgressNotificationParams(
            progressToken: new ProgressToken(token: 'p-1'),
            progress: 5.0,
            total: 10.0,
            message: 'fetching',
            meta: new NotificationMetaObject(extras: ['k' => 'v']),
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
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ProgressNotificationParams(
            progressToken: new ProgressToken(token: 'p-7'),
            progress: 3.14,
            total: 42.0,
            message: 'crunching',
            meta: new NotificationMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ProgressNotificationParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ProgressNotificationParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing progressToken' => [
            ['progress' => 0.5],
            '"params" is missing the required "progressToken" key.',
        ];

        yield 'progressToken not int or string' => [
            ['progressToken' => [], 'progress' => 0.5],
            '"params.progressToken" must be an int or string, array given.',
        ];

        yield 'missing progress' => [
            ['progressToken' => 'p-1'],
            '"params" is missing the required "progress" key.',
        ];

        yield 'progress is string' => [
            ['progressToken' => 'p-1', 'progress' => 'oops'],
            '"params.progress" must be a number, string given.',
        ];

        yield 'progress is bool' => [
            ['progressToken' => 'p-1', 'progress' => true],
            '"params.progress" must be a number, bool given.',
        ];

        yield 'total is string' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, 'total' => 'oops'],
            '"params.total" must be a number or null, string given.',
        ];

        yield 'message not a string' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, 'message' => 1],
            '"params.message" must be a string or null, int given.',
        ];

        yield 'message empty' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, 'message' => ''],
            '"params.message" must be a non-empty string or null.',
        ];

        yield '_meta not an object' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['progressToken' => 'p-1', 'progress' => 0.5, '_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
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
