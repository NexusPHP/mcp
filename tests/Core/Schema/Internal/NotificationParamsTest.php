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

namespace Nexus\Mcp\Tests\Core\Schema\Internal;

use Nexus\Mcp\Core\Schema\Internal\NotificationParams;
use Nexus\Mcp\Core\Schema\Meta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class NotificationParamsTest extends TestCase
{
    public function testDefaultsToNullMeta(): void
    {
        self::assertNull(new NotificationParams()->meta);
    }

    public function testToArrayIsEmptyWhenNoMeta(): void
    {
        self::assertSame([], new NotificationParams()->toArray());
    }

    public function testToArrayEmitsMetaUnderUnderscoreKey(): void
    {
        $params = new NotificationParams(new Meta(['vendor' => 'x']));

        self::assertSame(['_meta' => ['vendor' => 'x']], $params->toArray());
    }

    public function testFromArrayWithoutMetaYieldsNullMeta(): void
    {
        self::assertNull(NotificationParams::fromArray([])->meta);
    }

    public function testFromArrayParsesMetaAsBaseMeta(): void
    {
        $params = NotificationParams::fromArray(['_meta' => ['any' => 'value']]);

        self::assertNotNull($params->meta);
        self::assertSame(['any' => 'value'], $params->meta->extras);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification params "_meta" must be an object, int given.');

        NotificationParams::fromArray(['_meta' => 1]);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new NotificationParams(new Meta(['k' => 'v']));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }
}
