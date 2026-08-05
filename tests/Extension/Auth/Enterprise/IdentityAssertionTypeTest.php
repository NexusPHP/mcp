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

namespace Nexus\Mcp\Tests\Extension\Auth\Enterprise;

use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IdentityAssertionType::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class IdentityAssertionTypeTest extends TestCase
{
    #[DataProvider('provideIdentityAssertionTypeCaseValueCases')]
    public function testIdentityAssertionTypeCaseValue(IdentityAssertionType $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{IdentityAssertionType, string}>
     */
    public static function provideIdentityAssertionTypeCaseValueCases(): iterable
    {
        yield 'IdToken' => [IdentityAssertionType::IdToken, 'urn:ietf:params:oauth:token-type:id_token'];

        yield 'RefreshToken' => [IdentityAssertionType::RefreshToken, 'urn:ietf:params:oauth:token-type:refresh_token'];
    }

    public function testPinsTheSubjectTokenTypeSet(): void
    {
        self::assertSame([
            'urn:ietf:params:oauth:token-type:id_token',
            'urn:ietf:params:oauth:token-type:refresh_token',
        ], array_column(IdentityAssertionType::cases(), 'value'));
    }
}
