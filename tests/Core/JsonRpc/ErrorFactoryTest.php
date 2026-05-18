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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\ErrorFactory;
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
#[CoversClass(ErrorFactory::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ErrorFactoryTest extends TestCase
{
    /**
     * @param class-string<Error> $expectedClass
     */
    #[DataProvider('provideCreateReturnsConcreteSubclassCases')]
    public function testCreateReturnsConcreteSubclass(ProtocolErrorCode $code, string $expectedClass): void
    {
        $error = ErrorFactory::create($code, 'message text');

        self::assertInstanceOf($expectedClass, $error);
        self::assertSame($code->value, $error->code);
        self::assertSame('message text', $error->message);
    }

    /**
     * @return iterable<string, array{ProtocolErrorCode, class-string<Error>}>
     */
    public static function provideCreateReturnsConcreteSubclassCases(): iterable
    {
        yield 'parse error' => [ProtocolErrorCode::ParseError, ParseError::class];

        yield 'invalid request' => [ProtocolErrorCode::InvalidRequest, InvalidRequestError::class];

        yield 'method not found' => [ProtocolErrorCode::MethodNotFound, MethodNotFoundError::class];

        yield 'invalid params' => [ProtocolErrorCode::InvalidParams, InvalidParamsError::class];

        yield 'internal error' => [ProtocolErrorCode::InternalError, InternalError::class];
    }

    public function testCreateRejectsUrlElicitationRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ErrorFactory::create\(\) cannot construct UrlElicitationRequiredErrorPayload\. Instantiate it directly with the required url\/urlError payload\.$/');

        ErrorFactory::create(ProtocolErrorCode::UrlElicitationRequired, 'fallback message');
    }

    public function testCreatePropagatesData(): void
    {
        $data = ['trace_id' => 'abc123'];
        $error = ErrorFactory::create(ProtocolErrorCode::InternalError, 'oops', $data);

        self::assertSame($data, $error->data);
    }

    public function testCreateOmitsDataWhenNotProvided(): void
    {
        $error = ErrorFactory::create(ProtocolErrorCode::InternalError, 'oops');

        self::assertNull($error->data);
    }
}
