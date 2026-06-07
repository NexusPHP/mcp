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

namespace Nexus\Mcp\Tests\Core\Schema\Elicitation;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StringSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class StringSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new StringSchema();

        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->minLength);
        self::assertNull($schema->maxLength);
        self::assertNull($schema->format);
        self::assertNull($schema->default);
    }

    public function testToArrayMinimal(): void
    {
        self::assertSame(['type' => 'string'], new StringSchema()->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new StringSchema('Email', 'Email address', 3, 320, 'email', 'user@example.com');

        self::assertSame(
            [
                'type' => 'string',
                'title' => 'Email',
                'description' => 'Email address',
                'minLength' => 3,
                'maxLength' => 320,
                'format' => 'email',
                'default' => 'user@example.com',
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new StringSchema('Email', null, null, null, 'email');

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $schema = StringSchema::fromArray([
            'type' => 'string',
            'title' => 'Email',
            'description' => 'desc',
            'minLength' => 3,
            'maxLength' => 320,
            'format' => 'email',
            'default' => 'a@b.com',
        ]);

        self::assertSame('Email', $schema->title);
        self::assertSame(3, $schema->minLength);
        self::assertSame(320, $schema->maxLength);
        self::assertSame('email', $schema->format);
        self::assertSame('a@b.com', $schema->default);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new StringSchema('Email', 'desc', 3, 320, 'uri', 'http://example.com');

        $rebuilt = StringSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('string schema "title" must be a non-empty string or null.');

        new StringSchema('');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('string schema "description" must be a non-empty string or null.');

        new StringSchema(null, '');
    }

    public function testConstructorRejectsNegativeMinLength(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('string schema "minLength" must be a non-negative integer or null.');

        new StringSchema(null, null, -1);
    }

    public function testConstructorRejectsNegativeMaxLength(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('string schema "maxLength" must be a non-negative integer or null.');

        new StringSchema(null, null, null, -1);
    }

    public function testConstructorRejectsUnknownFormat(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('string schema "format" must be one of "date", "date-time", "email", "uri".');

        new StringSchema(null, null, null, null, 'phone');
    }

    #[DataProvider('provideConstructorAcceptsKnownFormatCases')]
    public function testConstructorAcceptsKnownFormat(string $format): void
    {
        $schema = new StringSchema(null, null, null, null, $format);

        self::assertSame($format, $schema->format);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideConstructorAcceptsKnownFormatCases(): iterable
    {
        yield 'date' => ['date'];

        yield 'date-time' => ['date-time'];

        yield 'email' => ['email'];

        yield 'uri' => ['uri'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        StringSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            [],
            'string schema missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'number'],
            'string schema "type" must be \'string\', \'number\' given.',
        ];

        yield 'title not a string' => [
            ['type' => 'string', 'title' => 1],
            'string schema "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'string', 'description' => 1],
            'string schema "description" must be a string or null, int given.',
        ];

        yield 'minLength not an int' => [
            ['type' => 'string', 'minLength' => 'x'],
            'string schema "minLength" must be an int or null, string given.',
        ];

        yield 'maxLength not an int' => [
            ['type' => 'string', 'maxLength' => 'x'],
            'string schema "maxLength" must be an int or null, string given.',
        ];

        yield 'format not a string' => [
            ['type' => 'string', 'format' => 1],
            'string schema "format" must be a string or null, int given.',
        ];

        yield 'format unknown' => [
            ['type' => 'string', 'format' => 'phone'],
            'string schema "format" must be one of "date", "date-time", "email", "uri".',
        ];

        yield 'default not a string' => [
            ['type' => 'string', 'default' => 1],
            'string schema "default" must be a string or null, int given.',
        ];
    }
}
