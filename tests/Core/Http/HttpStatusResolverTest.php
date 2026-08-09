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
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(HttpStatusResolver::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class HttpStatusResolverTest extends AbstractMcpTestCase
{
    #[DataProvider('provideResolveCases')]
    public function testResolve(int $code, bool $fromHandler, int $expected): void
    {
        self::assertSame($expected, HttpStatusResolver::resolve($code, $fromHandler));
    }

    /**
     * @return iterable<string, array{int, bool, int}>
     */
    public static function provideResolveCases(): iterable
    {
        yield 'pre-dispatch parse error' => [ProtocolErrorCode::ParseError->value, false, 400];

        yield 'pre-dispatch invalid request' => [ProtocolErrorCode::InvalidRequest->value, false, 400];

        yield 'pre-dispatch invalid params' => [ProtocolErrorCode::InvalidParams->value, false, 400];

        yield 'pre-dispatch header mismatch' => [ProtocolErrorCode::HeaderMismatch->value, false, 400];

        yield 'pre-dispatch missing required client capability' => [ProtocolErrorCode::MissingRequiredClientCapability->value, false, 400];

        yield 'pre-dispatch unsupported protocol version' => [ProtocolErrorCode::UnsupportedProtocolVersion->value, false, 400];

        yield 'pre-dispatch internal error defaults to 400' => [ProtocolErrorCode::InternalError->value, false, 400];

        yield 'pre-dispatch method not found' => [ProtocolErrorCode::MethodNotFound->value, false, 404];

        yield 'a shed request is retryable, not the caller\'s fault' => [SdkErrorCode::Overloaded->value, false, 503];

        yield 'an unrecognised code defaults to 400' => [-32_001, false, 400];

        yield 'handler error rides 200 regardless of code' => [ProtocolErrorCode::InternalError->value, true, 200];

        yield 'handler error ignores a method-not-found code' => [ProtocolErrorCode::MethodNotFound->value, true, 200];

        yield 'handler error ignores an overloaded code' => [SdkErrorCode::Overloaded->value, true, 200];

        yield 'handler error ignores a parse-error code' => [ProtocolErrorCode::ParseError->value, true, 200];

        yield 'handler error keeps 400 for a missing client capability' => [ProtocolErrorCode::MissingRequiredClientCapability->value, true, 400];
    }
}
