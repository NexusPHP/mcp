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
use Nexus\Mcp\Server\Validation\ValidationErrorFormatter;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ValidationErrorFormatter::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ValidationErrorFormatterTest extends AbstractMcpTestCase
{
    public function testARootTypeMismatchStaysBare(): void
    {
        self::assertSame(
            ['must be an object, array given.'],
            $this->format([1, 2], ['type' => 'object']),
        );
    }

    public function testAMultiTypeMismatchNamesEveryExpectedType(): void
    {
        self::assertSame(
            ['"v" must be an object or a string, int given.'],
            $this->format(
                ['v' => 5],
                ['type' => 'object', 'properties' => ['v' => ['type' => ['object', 'string']]]],
            ),
        );
    }

    public function testARootMissingKeyStaysBare(): void
    {
        self::assertSame(
            ['missing the required "n" key.'],
            $this->format(
                ['other' => 1],
                ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']],
            ),
        );
    }

    public function testEveryMissingKeyIsNamed(): void
    {
        self::assertSame(
            ['missing the required "a" key.', 'missing the required "b" key.'],
            $this->format(['other' => 1], ['type' => 'object', 'required' => ['a', 'b']]),
        );
    }

    public function testANestedMissingKeyNamesItsParentPath(): void
    {
        self::assertSame(
            ['"point" is missing the required "longitude" key.'],
            $this->format(
                ['point' => ['latitude' => 1.5]],
                ['type' => 'object', 'properties' => ['point' => ['type' => 'object', 'required' => ['latitude', 'longitude']]]],
            ),
        );
    }

    public function testAnEnumMismatchListsTheAllowedValues(): void
    {
        self::assertSame(
            ['"mode" must be one of [\'a\', \'b\'], \'z\' given.'],
            $this->format(
                ['mode' => 'z'],
                ['type' => 'object', 'properties' => ['mode' => ['type' => 'string', 'enum' => ['a', 'b']]]],
            ),
        );
    }

    public function testAnUncuratedKeywordNamesTheFailedConstraint(): void
    {
        self::assertSame(
            ['"n" does not satisfy the schema\'s "minimum" constraint.'],
            $this->format(
                ['n' => 0],
                ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => 1]]],
            ),
        );
    }

    public function testARootUncuratedKeywordLabelsTheValue(): void
    {
        self::assertSame(
            ['the value does not satisfy the schema\'s "not" constraint.'],
            $this->format('x', ['not' => ['type' => 'string']]),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('provideEveryTypeDescriptorRendersCases')]
    public function testEveryTypeDescriptorRenders(array $data, string $expectedType, string $message): void
    {
        self::assertSame(
            [$message],
            $this->format(
                $data,
                ['type' => 'object', 'properties' => ['v' => ['type' => $expectedType]]],
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function provideEveryTypeDescriptorRendersCases(): iterable
    {
        yield 'an array expected' => [['v' => 5], 'array', '"v" must be an array, int given.'];

        yield 'an integer expected' => [['v' => 'x'], 'integer', '"v" must be an integer, string given.'];

        yield 'a boolean expected' => [['v' => 5], 'boolean', '"v" must be a boolean, int given.'];

        yield 'a number expected, bool given' => [['v' => true], 'number', '"v" must be a number, bool given.'];

        yield 'a string expected, float given' => [['v' => 1.5], 'string', '"v" must be a string, float given.'];

        yield 'a string expected, map given' => [['v' => ['a' => 1]], 'string', '"v" must be a string, array given.'];

        yield 'null expected' => [['v' => 'x'], 'null', '"v" must be null, string given.'];
    }

    public function testUndeclaredKeysAreEachNamedBareAtTheRoot(): void
    {
        self::assertSame(
            ['carries the undeclared "evil" key.', 'carries the undeclared "worse" key.'],
            $this->format(
                ['n' => 1, 'evil' => 1, 'worse' => 2],
                ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'additionalProperties' => false],
            ),
        );
    }

    public function testAnUndeclaredNestedKeyNamesItsParentPath(): void
    {
        self::assertSame(
            ['"point" carries the undeclared "x" key.'],
            $this->format(
                ['point' => ['x' => 1]],
                ['type' => 'object', 'properties' => ['point' => ['type' => 'object', 'additionalProperties' => false]]],
            ),
        );
    }

    public function testADeepPathRendersDotted(): void
    {
        self::assertSame(
            ['"point.latitude" must be a number, string given.'],
            $this->format(
                ['point' => ['latitude' => 'x']],
                ['type' => 'object', 'properties' => ['point' => ['type' => 'object', 'properties' => ['latitude' => ['type' => 'number']]]]],
            ),
        );
    }

    public function testARootViolationCarriesAnEmptyPointer(): void
    {
        self::assertSame(
            [['pointer' => '', 'message' => 'must be an object, array given.']],
            $this->describe([1, 2], ['type' => 'object']),
        );
    }

    public function testANestedViolationPointsAtTheOffendingValue(): void
    {
        self::assertSame(
            [['pointer' => '/point/latitude', 'message' => '"point.latitude" must be a number, string given.']],
            $this->describe(
                ['point' => ['latitude' => 'north']],
                ['type' => 'object', 'properties' => ['point' => ['type' => 'object', 'properties' => ['latitude' => ['type' => 'number']]]]],
            ),
        );
    }

    public function testAnArrayElementViolationPointsAtItsIndex(): void
    {
        self::assertSame(
            [['pointer' => '/tags/1', 'message' => '"tags.1" must be a string, int given.']],
            $this->describe(
                ['tags' => ['ok', 7]],
                ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            ),
        );
    }

    public function testAMissingKeyPointsAtTheObjectThatLacksIt(): void
    {
        self::assertSame(
            [['pointer' => '/point', 'message' => '"point" is missing the required "longitude" key.']],
            $this->describe(
                ['point' => ['latitude' => 1.5]],
                ['type' => 'object', 'properties' => ['point' => ['type' => 'object', 'required' => ['latitude', 'longitude']]]],
            ),
        );
    }

    public function testPointerSegmentsEscapeTildeAndSlash(): void
    {
        self::assertSame(
            [
                ['pointer' => '/a~1b', 'message' => '"a/b" must be a string, int given.'],
                ['pointer' => '/c~0d', 'message' => '"c~d" must be a string, int given.'],
            ],
            $this->describe(
                ['a/b' => 1, 'c~d' => 2],
                ['type' => 'object', 'properties' => ['a/b' => ['type' => 'string'], 'c~d' => ['type' => 'string']]],
            ),
        );
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function format(mixed $data, array $schema): array
    {
        return array_map(
            static fn(SchemaViolation $violation): string => $violation->message,
            $this->violations($data, $schema),
        );
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<SchemaViolation>
     */
    private function violations(mixed $data, array $schema): array
    {
        $validator = new Validator(max_errors: 8);
        $error = $validator->validate(Helper::toJSON($data), (object) Helper::toJSON($schema))->error();

        if (null === $error) {
            self::fail('Expected the data to violate the schema.');
        }

        return (new ValidationErrorFormatter())->format($error);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function describe(mixed $data, array $schema): array
    {
        return array_map(
            static fn(SchemaViolation $violation): array => $violation->toArray(),
            $this->violations($data, $schema),
        );
    }
}
