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

use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EmptyRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EmptyRequestParamsTest extends TestCase
{
    public function testDefaultsToNullMeta(): void
    {
        self::assertSame([], new EmptyRequestParams()->meta->toArray());
    }

    public function testToArrayIsEmptyWhenNoMeta(): void
    {
        self::assertSame([], new EmptyRequestParams()->toArray());
    }

    public function testToArrayEmitsMetaUnderUnderscoreKey(): void
    {
        $params = new EmptyRequestParams(new RequestMetaObject(new ProgressToken('tok-1')));

        self::assertSame(['_meta' => ['progressToken' => 'tok-1']], $params->toArray());
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $params = new EmptyRequestParams(new RequestMetaObject());

        self::assertSame([], $params->toArray());
    }

    public function testFromArrayWithoutMetaYieldsNullMeta(): void
    {
        self::assertSame([], EmptyRequestParams::fromArray([])->meta->toArray());
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = EmptyRequestParams::fromArray(['_meta' => ['progressToken' => 42]]);
        self::assertNotNull($params->meta->progressToken);
        self::assertSame(42, $params->meta->progressToken->token);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"params._meta" must be an object, string given.');

        EmptyRequestParams::fromArray(['_meta' => 'bad']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new EmptyRequestParams(new RequestMetaObject(null, ['vendor' => 'x']));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }
}
