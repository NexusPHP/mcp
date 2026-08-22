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
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(EmptyRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EmptyRequestParamsTest extends AbstractMcpTestCase
{
    public function testConstructionCapturesMeta(): void
    {
        $meta = RequestMetaObjectFactory::create();
        $params = new EmptyRequestParams(meta: $meta);

        self::assertSame($meta, $params->meta);
    }

    public function testToArrayEmitsMetaUnderUnderscoreKey(): void
    {
        $params = new EmptyRequestParams(meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'tok-1')));

        self::assertSame(['_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'tok-1'))], $params->toArray());
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = EmptyRequestParams::fromArray(['_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 42))]);
        self::assertNotNull($params->meta->progressToken);
        self::assertSame(42, $params->meta->progressToken->token);
    }

    public function testFromArrayRequiresMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params" is missing the required "_meta" key.');

        EmptyRequestParams::fromArray([]);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params._meta" must be an object, string given.');

        EmptyRequestParams::fromArray(['_meta' => 'bad']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new EmptyRequestParams(meta: RequestMetaObjectFactory::create(null, ['vendor' => 'x']));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }
}
