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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ErrorTest extends TestCase
{
    /**
     * @param class-string<Error> $expectedClass
     */
    #[DataProvider('provideForCodeReturnsConcreteSubclassCases')]
    public function testForCodeReturnsConcreteSubclass(ProtocolErrorCode $code, string $expectedClass): void
    {
        $error = Error::forCode($code, 'message text');

        self::assertInstanceOf($expectedClass, $error);
        self::assertSame($code->value, $error->code);
        self::assertSame('message text', $error->message);
    }

    /**
     * @return iterable<string, array{ProtocolErrorCode, class-string<Error>}>
     */
    public static function provideForCodeReturnsConcreteSubclassCases(): iterable
    {
        yield 'parse error' => [ProtocolErrorCode::ParseError, ParseError::class];

        yield 'invalid request' => [ProtocolErrorCode::InvalidRequest, InvalidRequestError::class];

        yield 'method not found' => [ProtocolErrorCode::MethodNotFound, MethodNotFoundError::class];

        yield 'invalid params' => [ProtocolErrorCode::InvalidParams, InvalidParamsError::class];

        yield 'internal error' => [ProtocolErrorCode::InternalError, InternalError::class];
    }

    public function testForCodeRejectsUrlElicitationRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Error::forCode\(\) cannot construct UrlElicitationRequiredErrorPayload/');

        Error::forCode(ProtocolErrorCode::UrlElicitationRequired, 'fallback message');
    }

    public function testForCodePropagatesData(): void
    {
        $data = ['trace_id' => 'abc123'];
        $error = Error::forCode(ProtocolErrorCode::InternalError, 'oops', $data);

        self::assertSame($data, $error->data);
    }

    public function testForCodeOmitsDataWhenNotProvided(): void
    {
        $error = Error::forCode(ProtocolErrorCode::InternalError, 'oops');

        self::assertNull($error->data);
    }
}
