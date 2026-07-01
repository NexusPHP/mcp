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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\HttpStatusResolver;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HttpStatusResolver::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class HttpStatusResolverTest extends TestCase
{
    #[DataProvider('provideResolveCases')]
    public function testResolve(ProtocolErrorCode $code, bool $fromHandler, int $expected): void
    {
        self::assertSame($expected, HttpStatusResolver::resolve($code, $fromHandler));
    }

    /**
     * @return iterable<string, array{ProtocolErrorCode, bool, int}>
     */
    public static function provideResolveCases(): iterable
    {
        yield 'pre-dispatch parse error' => [ProtocolErrorCode::ParseError, false, 400];

        yield 'pre-dispatch invalid request' => [ProtocolErrorCode::InvalidRequest, false, 400];

        yield 'pre-dispatch invalid params' => [ProtocolErrorCode::InvalidParams, false, 400];

        yield 'pre-dispatch header mismatch' => [ProtocolErrorCode::HeaderMismatch, false, 400];

        yield 'pre-dispatch missing required client capability' => [ProtocolErrorCode::MissingRequiredClientCapability, false, 400];

        yield 'pre-dispatch unsupported protocol version' => [ProtocolErrorCode::UnsupportedProtocolVersion, false, 400];

        yield 'pre-dispatch internal error defaults to 400' => [ProtocolErrorCode::InternalError, false, 400];

        yield 'pre-dispatch method not found' => [ProtocolErrorCode::MethodNotFound, false, 404];

        yield 'handler error rides 200 regardless of code' => [ProtocolErrorCode::InternalError, true, 200];

        yield 'handler error ignores a method-not-found code' => [ProtocolErrorCode::MethodNotFound, true, 200];

        yield 'handler error ignores a parse-error code' => [ProtocolErrorCode::ParseError, true, 200];
    }
}
