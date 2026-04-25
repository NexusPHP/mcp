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

use Nexus\Mcp\Core\Schema\Internal\RequestParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestParamsTest extends TestCase
{
    public function testDefaultsToNullMeta(): void
    {
        self::assertNull(new RequestParams()->meta);
    }

    public function testToArrayIsEmptyWhenNoMeta(): void
    {
        self::assertSame([], new RequestParams()->toArray());
    }

    public function testToArrayEmitsMetaUnderUnderscoreKey(): void
    {
        $params = new RequestParams(new RequestMeta(new ProgressToken('tok-1')));

        self::assertSame(['_meta' => ['progressToken' => 'tok-1']], $params->toArray());
    }

    public function testFromArrayWithoutMetaYieldsNullMeta(): void
    {
        self::assertNull(RequestParams::fromArray([])->meta);
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = RequestParams::fromArray(['_meta' => ['progressToken' => 42]]);

        self::assertNotNull($params->meta);
        self::assertNotNull($params->meta->progressToken);
        self::assertSame(42, $params->meta->progressToken->token);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request params "_meta" must be an object, string given.');

        RequestParams::fromArray(['_meta' => 'bad']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new RequestParams(new RequestMeta(null, ['vendor' => 'x']));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }
}
