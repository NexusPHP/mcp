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

namespace Nexus\Mcp\Tests\Server\Validation;

use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpisSchemaValidator::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class OpisSchemaValidatorTest extends TestCase
{
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => ['n' => ['type' => 'integer']],
        'required' => ['n'],
    ];

    public function testConformingDataReturnsNoErrors(): void
    {
        self::assertSame([], new OpisSchemaValidator()->validate(['n' => 42], self::SCHEMA));
    }

    public function testNonConformingDataReturnsErrorMessages(): void
    {
        $errors = new OpisSchemaValidator()->validate(['n' => 'not-an-int'], self::SCHEMA);

        self::assertNotSame([], $errors);

        foreach ($errors as $error) {
            self::assertNotSame('', $error);
        }
    }

    public function testMissingRequiredPropertyReturnsErrorMessages(): void
    {
        self::assertNotSame([], new OpisSchemaValidator()->validate(['other' => 1], self::SCHEMA));
    }

    public function testReportsEveryViolationNotJustTheFirst(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
            'required' => ['a', 'b'],
        ];

        $errors = new OpisSchemaValidator()->validate(['a' => 1, 'b' => 'x'], $schema);

        self::assertGreaterThan(1, \count($errors));
    }
}
