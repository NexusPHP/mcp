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

namespace Nexus\Mcp\Tests\Core\Schema\Tool;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolAnnotations::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolAnnotationsTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $annotations = new ToolAnnotations();

        self::assertNull($annotations->title);
        self::assertNull($annotations->readOnlyHint);
        self::assertNull($annotations->destructiveHint);
        self::assertNull($annotations->idempotentHint);
        self::assertNull($annotations->openWorldHint);
    }

    public function testConstructionWithAllFields(): void
    {
        $annotations = new ToolAnnotations(
            title: 'Read File',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );

        self::assertSame('Read File', $annotations->title);
        self::assertFalse($annotations->readOnlyHint);
        self::assertFalse($annotations->destructiveHint);
        self::assertTrue($annotations->idempotentHint);
        self::assertFalse($annotations->openWorldHint);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ToolAnnotations title must be a non-empty string or null.');

        new ToolAnnotations(title: '');
    }

    public function testConstructorRejectsDestructiveHintWhenReadOnly(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ToolAnnotations "destructiveHint" must be null when "readOnlyHint" is true; the spec defines it only when readOnlyHint == false.');

        new ToolAnnotations(readOnlyHint: true, destructiveHint: false);
    }

    public function testConstructorRejectsIdempotentHintWhenReadOnly(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ToolAnnotations "idempotentHint" must be null when "readOnlyHint" is true; the spec defines it only when readOnlyHint == false.');

        new ToolAnnotations(readOnlyHint: true, idempotentHint: true);
    }

    public function testConstructorAllowsReadOnlyAloneOrWithOpenWorld(): void
    {
        $this->expectNotToPerformAssertions();

        new ToolAnnotations(readOnlyHint: true);
        new ToolAnnotations(readOnlyHint: true, openWorldHint: true);
    }

    public function testToArrayOmitsNullFields(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: true);

        self::assertSame(['readOnlyHint' => true], $annotations->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $annotations = new ToolAnnotations(
            title: 'Read File',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );

        self::assertSame(
            [
                'title' => 'Read File',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            $annotations->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: true);

        self::assertSame($annotations->toArray(), $annotations->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $annotations = new ToolAnnotations();

        self::assertInstanceOf(\stdClass::class, $annotations->jsonSerialize());
        self::assertSame('{}', json_encode($annotations));
    }

    public function testFromArrayParsesAllFields(): void
    {
        $annotations = ToolAnnotations::fromArray([
            'title' => 'Read File',
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ]);

        self::assertSame('Read File', $annotations->title);
        self::assertFalse($annotations->readOnlyHint);
        self::assertFalse($annotations->destructiveHint);
        self::assertTrue($annotations->idempotentHint);
        self::assertFalse($annotations->openWorldHint);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ToolAnnotations(
            title: 'Read File',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );

        $rebuilt = ToolAnnotations::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ToolAnnotations::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'title not a string' => [
            ['title' => 1],
            'ToolAnnotations "title" must be a string or null, int given.',
        ];

        yield 'readOnlyHint not a bool' => [
            ['readOnlyHint' => 'yes'],
            'ToolAnnotations "readOnlyHint" must be a bool or null, string given.',
        ];

        yield 'destructiveHint not a bool' => [
            ['destructiveHint' => 'no'],
            'ToolAnnotations "destructiveHint" must be a bool or null, string given.',
        ];

        yield 'idempotentHint not a bool' => [
            ['idempotentHint' => 1],
            'ToolAnnotations "idempotentHint" must be a bool or null, int given.',
        ];

        yield 'openWorldHint not a bool' => [
            ['openWorldHint' => 0],
            'ToolAnnotations "openWorldHint" must be a bool or null, int given.',
        ];
    }
}
