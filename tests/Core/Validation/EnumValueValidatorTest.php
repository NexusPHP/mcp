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

namespace Nexus\Mcp\Tests\Core\Validation;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Validation\EnumValueValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(EnumValueValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EnumValueValidatorTest extends AbstractMcpTestCase
{
    public function testParseReturnsCaseForValidStringBackedValue(): void
    {
        $case = EnumValueValidator::parse(Role::class, 'user', 'Test "role"');

        self::assertSame(Role::User, $case);
    }

    public function testParseReturnsCaseForValidIntBackedValue(): void
    {
        $case = EnumValueValidator::parse(ProtocolErrorCode::class, -32_601, 'Test "code"');

        self::assertSame(ProtocolErrorCode::MethodNotFound, $case);
    }

    public function testParseThrowsForUnknownStringValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Test "role" must be one of \[\'user\', \'assistant\'\], \'observer\' given\.$/');

        EnumValueValidator::parse(Role::class, 'observer', 'Test "role"');
    }

    public function testParseThrowsForUnknownIntValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Test "code" must be one of \[-32700, -32600, -32601, -32602, -32603, -32020, -32021, -32022\], 0 given\.$/');

        EnumValueValidator::parse(ProtocolErrorCode::class, 0, 'Test "code"');
    }

    public function testParseRejectsIntValueForStringBackedEnum(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Test "role" must be one of \[\'user\', \'assistant\'\], 1 given\.$/');

        EnumValueValidator::parse(Role::class, 1, 'Test "role"');
    }

    public function testParseRejectsStringValueForIntBackedEnum(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Test "code" must be one of \\[-32700, -32600, -32601, -32602, -32603, -32020, -32021, -32022\\], \'-32700\' given\\.$/');

        EnumValueValidator::parse(ProtocolErrorCode::class, '-32700', 'Test "code"');
    }

    #[DataProvider('provideParseRejectsValueWithNonBackingTypeCases')]
    public function testParseRejectsValueWithNonBackingType(mixed $value, string $expectedRendered): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches(\sprintf('/^Test "role" must be one of \[\'user\', \'assistant\'\], %s given\.$/', $expectedRendered));

        EnumValueValidator::parse(Role::class, $value, 'Test "role"');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideParseRejectsValueWithNonBackingTypeCases(): iterable
    {
        yield 'null' => [null, 'NULL'];

        yield 'float' => [1.5, '1.5'];

        yield 'boolean' => [true, 'true'];
    }
}
