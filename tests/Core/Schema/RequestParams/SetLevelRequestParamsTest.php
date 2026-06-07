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

namespace Nexus\Mcp\Tests\Core\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\SetLevelRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SetLevelRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SetLevelRequestParamsTest extends TestCase
{
    public function testConstructionExposesLevel(): void
    {
        $params = new SetLevelRequestParams(LoggingLevel::Warning);

        self::assertSame(LoggingLevel::Warning, $params->level);
        self::assertSame([], $params->meta->toArray());
    }

    public function testToArrayEmitsLevelOnly(): void
    {
        $params = new SetLevelRequestParams(LoggingLevel::Info);

        self::assertSame(['level' => 'info'], $params->toArray());
    }

    public function testToArrayIncludesMetaWhenPresent(): void
    {
        $params = new SetLevelRequestParams(
            LoggingLevel::Debug,
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'level' => 'debug'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new SetLevelRequestParams(
            LoggingLevel::Error,
            new RequestMetaObject(null, ['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayParsesLevel(): void
    {
        $params = SetLevelRequestParams::fromArray(['level' => 'critical']);

        self::assertSame(LoggingLevel::Critical, $params->level);
        self::assertSame([], $params->meta->toArray());
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = SetLevelRequestParams::fromArray([
            'level' => 'alert',
            '_meta' => ['vendor' => 'x'],
        ]);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new SetLevelRequestParams(
            LoggingLevel::Notice,
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        $rebuilt = SetLevelRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        SetLevelRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing level' => [
            [],
            'missing the required "level" key.',
        ];

        yield 'level not a string' => [
            ['level' => 1],
            '"params.level" must be one of [\'debug\', \'info\', \'notice\', \'warning\', \'error\', \'critical\', \'alert\', \'emergency\'], 1 given.',
        ];

        yield 'unknown level' => [
            ['level' => 'verbose'],
            '"params.level" must be one of [\'debug\', \'info\', \'notice\', \'warning\', \'error\', \'critical\', \'alert\', \'emergency\'], \'verbose\' given.',
        ];

        yield '_meta not an object' => [
            ['level' => 'info', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['level' => 'info', '_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
        ];
    }
}
