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

use Nexus\Mcp\Server\Validation\SchemaViolation;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SchemaViolation::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SchemaViolationTest extends AbstractMcpTestCase
{
    public function testToArrayCarriesThePointerAndTheMessage(): void
    {
        $violation = new SchemaViolation('/point/latitude', '"point.latitude" must be a number, string given.');

        self::assertSame('/point/latitude', $violation->pointer);
        self::assertSame(
            ['pointer' => '/point/latitude', 'message' => '"point.latitude" must be a number, string given.'],
            $violation->toArray(),
        );
    }

    #[DataProvider('provideAnRfc6901PointerIsAcceptedCases')]
    public function testAnRfc6901PointerIsAccepted(string $pointer): void
    {
        self::assertSame($pointer, (new SchemaViolation($pointer, 'x'))->pointer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnRfc6901PointerIsAcceptedCases(): iterable
    {
        yield 'the data root' => [''];

        yield 'one segment' => ['/n'];

        yield 'an array index' => ['/tags/0'];

        yield 'an empty segment' => ['/'];

        yield 'escaped slash and tilde' => ['/a~1b/c~0d'];
    }

    #[DataProvider('provideAPointerOffTheRfc6901GrammarIsRefusedCases')]
    public function testAPointerOffTheRfc6901GrammarIsRefused(string $pointer): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Schema violation pointer must be an RFC 6901 JSON pointer, \'%s\' given.', $pointer));

        new SchemaViolation($pointer, 'x');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAPointerOffTheRfc6901GrammarIsRefusedCases(): iterable
    {
        yield 'a dotted path' => ['point.latitude'];

        yield 'a missing leading slash' => ['n'];

        yield 'an unescaped tilde' => ['/a~b'];

        yield 'a tilde escape off the grammar' => ['/a~2b'];
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Schema violation message must be a non-empty string, string given.');

        // @phpstan-ignore argument.type
        new SchemaViolation('/n', '');
    }
}
