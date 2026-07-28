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
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EmptyNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EmptyNotificationParamsTest extends TestCase
{
    public function testDefaultsToNullMeta(): void
    {
        self::assertSame([], new EmptyNotificationParams()->meta->toArray());
    }

    public function testToArrayIsEmptyWhenNoMeta(): void
    {
        self::assertSame([], new EmptyNotificationParams()->toArray());
    }

    public function testToArrayEmitsMetaUnderUnderscoreKey(): void
    {
        $params = new EmptyNotificationParams(meta: new NotificationMetaObject(extras: ['vendor' => 'x']));

        self::assertSame(['_meta' => ['vendor' => 'x']], $params->toArray());
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $params = new EmptyNotificationParams();

        self::assertSame([], $params->toArray());
    }

    public function testFromArrayWithoutMetaYieldsNullMeta(): void
    {
        self::assertSame([], EmptyNotificationParams::fromArray([])->meta->toArray());
    }

    public function testFromArrayParsesMetaAsBaseMeta(): void
    {
        $params = EmptyNotificationParams::fromArray(['_meta' => ['any' => 'value']]);
        self::assertSame(['any' => 'value'], $params->meta->extras);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params._meta" must be an object, int given.');

        EmptyNotificationParams::fromArray(['_meta' => 1]);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new EmptyNotificationParams(meta: new NotificationMetaObject(extras: ['k' => 'v']));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }
}
