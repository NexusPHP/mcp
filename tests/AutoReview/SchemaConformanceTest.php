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

namespace Nexus\Mcp\Tests\AutoReview;

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Tools\McpSchemaProcessor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class SchemaConformanceTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private static array $latestSchema = [];

    /**
     * @var array{
     *   processed_schema: array<string, class-string>,
     *   internal_schema: array<string, class-string>,
     *   unprocessed_schema: list<string>,
     * }
     */
    private static array $sortedSchema = [
        'processed_schema' => [],
        'internal_schema' => [],
        'unprocessed_schema' => [],
    ];

    /**
     * @param class-string $schemaClass
     */
    #[DataProvider('provideSchemaDescriptionIsAccurateCases')]
    public function testSchemaDescriptionIsAccurate(string $schema, string $schemaClass): void
    {
        $description = self::getSchemaProperty($schema, 'description');
        self::assertIsString($description, \sprintf('Description for schema "%s" is not a string.', $schema));

        $reflection = new \ReflectionClass($schemaClass);
        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment, \sprintf('Schema class "%s" does not have a PHPDoc comment.', $schemaClass));

        self::assertDescriptionContains($description, $docComment);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function provideSchemaDescriptionIsAccurateCases(): iterable
    {
        yield from self::getProtocolSchemasForTesting(
            static fn(string $class): bool => ! \in_array($class, [
                Error::class,
            ], true),
        );
    }

    /**
     * @param class-string $schemaClass
     */
    #[DataProvider('provideSchemaTypeMatchesPropertiesCases')]
    public function testSchemaTypeMatchesProperties(string $schema, string $schemaClass): void
    {
        $type = self::getSchemaProperty($schema, 'type');
        self::assertThat($type, self::logicalOr(self::isArray(), self::isString()), \sprintf('Type for schema "%s" is neither string nor array.', $schema));
        \assert(\is_string($type) || \is_array($type)); // for phpstan only

        $reflection = new \ReflectionClass($schemaClass);
        $properties = $reflection->getProperties();

        if (\is_array($type)) {
            $type = array_map(self::normaliseJsonType(...), $type); // @phpstan-ignore argument.type
            sort($type);

            self::assertCount(1, $properties);
            self::assertInstanceOf(\ReflectionUnionType::class, $properties[0]->getType());

            $propertyTypes = array_map(
                static fn(\ReflectionNamedType $namedType) => $namedType->getName(), // @phpstan-ignore argument.type
                $properties[0]->getType()->getTypes(),
            );
            sort($propertyTypes);

            self::assertSame($type, $propertyTypes, \sprintf(
                'Schema key "%s"\'s type ("%s") does not match property types in class "%s" ("%s").',
                $schema,
                implode('", "', $type),
                $schemaClass,
                implode('", "', $propertyTypes),
            ));
        } elseif ('object' === $type) {
            self::assertTrue($reflection->implementsInterface(Arrayable::class));
        } else {
            self::assertCount(1, $properties);
            self::assertInstanceOf(\ReflectionNamedType::class, $properties[0]->getType());

            $type = self::normaliseJsonType($type);
            $propertyType = $properties[0]->getType()->getName();

            self::assertSame($type, $propertyType, \sprintf(
                'Schema key "%s"\'s type ("%s") does not match property type in class "%s" ("%s").',
                $schema,
                $type,
                $schemaClass,
                $propertyType,
            ));
        }
    }

    public static function provideSchemaTypeMatchesPropertiesCases(): iterable
    {
        yield from self::getProtocolSchemasForTesting(static fn(string $class) => ! enum_exists($class));
    }

    /**
     * @param class-string<\UnitEnum> $schemaClass
     */
    #[DataProvider('provideSchemaEnumMatchesCasesCases')]
    public function testSchemaEnumMatchesCases(string $schema, string $schemaClass): void
    {
        $enum = self::getSchemaProperty($schema, 'enum');
        self::assertIsArray($enum, \sprintf('Enum for schema "%s" is not an array.', $schema));

        $reflection = new \ReflectionEnum($schemaClass);
        self::assertTrue($reflection->isBacked(), \sprintf('Enum class "%s" is not a backed enum.', $schemaClass));

        $caseValues = array_map(
            static fn(\ReflectionEnumBackedCase $case) => $case->getBackingValue(),
            $reflection->getCases(),
        );
        sort($caseValues);

        self::assertSame($enum, $caseValues, \sprintf(
            'Schema key "%s"\'s enum values ("%s") do not match case values in enum class "%s" ("%s").',
            $schema,
            implode('", "', $enum),
            $schemaClass,
            implode('", "', $caseValues),
        ));
    }

    public static function provideSchemaEnumMatchesCasesCases(): iterable
    {
        // @phpstan-ignore-next-line argument.type
        yield from self::getProtocolSchemasForTesting(static fn(string $class) => enum_exists($class));
    }

    public function testProtocolSchemaExistsOnlyInCore(): void
    {
        foreach (self::getProtocolSchemasForTesting() as [$schema, $schemaClass]) {
            $parts = explode('\\', $schemaClass);
            self::assertArrayHasKey(2, $parts);

            $package = $parts[2];
            self::assertSame('Core', $package, \sprintf(
                'Protocol schema "%s" (class: %s) must be defined in Core, not %s.',
                $schema,
                $schemaClass,
                $package,
            ));
        }
    }

    private static function assertDescriptionContains(string $description, string $docComment): void
    {
        $docComment = str_replace(["/**\n", ' * ', ' *', ' */'], '', $docComment);

        self::assertStringContainsString($description, $docComment, 'Schema description does not match class PHPDoc comment.');
    }

    private static function normaliseJsonType(string $type): string
    {
        return match ($type) {
            'integer' => 'int',
            'number' => 'float',
            'boolean' => 'bool',
            default => $type,
        };
    }

    private static function generateLatestSchema(): void
    {
        if ([] !== self::$latestSchema && getenv('MCP_FETCH_LATEST_SCHEMA') === false) {
            return;
        }

        self::$latestSchema = McpSchemaProcessor::fetchAndSaveLatestSchema();
    }

    private static function sortSchemaDefinition(): void
    {
        if ([] === self::$latestSchema) {
            throw new \RuntimeException('Latest schema is not generated yet.');
        }

        if (
            ['processed_schema' => [], 'internal_schema' => [], 'unprocessed_schema' => []] !== self::$sortedSchema
            && getenv('MCP_FETCH_LATEST_SCHEMA') === false
        ) {
            return;
        }

        self::$sortedSchema = McpSchemaProcessor::sortAndSaveSchema(self::$latestSchema);
    }

    /**
     * Retrieve a property from a schema definition with validation.
     */
    private static function getSchemaProperty(string $schema, string $property): mixed
    {
        self::assertArrayHasKey($schema, self::$latestSchema, \sprintf('Schema key "%s" is missing in the latest schema definitions.', $schema));
        self::assertIsArray(self::$latestSchema[$schema], \sprintf('Schema definition for "%s" is not an array.', $schema));
        self::assertArrayHasKey($property, self::$latestSchema[$schema], \sprintf('Schema definition for "%s" does not have "%s".', $schema, $property));

        return self::$latestSchema[$schema][$property]; // @phpstan-ignore offsetAccess.notFound
    }

    /**
     * Generate and yield protocol schemas from the sorted schema definition.
     *
     * @param null|(callable(string, string): bool) $filter
     *
     * @return iterable<string, array{string, class-string}>
     */
    private static function getProtocolSchemasForTesting(?callable $filter = null): iterable
    {
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (self::$sortedSchema['processed_schema'] as $basename => $schemaClass) {
            if (null !== $filter && ! $filter($schemaClass, $basename)) {
                continue;
            }

            yield $basename => [$basename, $schemaClass];
        }
    }
}
