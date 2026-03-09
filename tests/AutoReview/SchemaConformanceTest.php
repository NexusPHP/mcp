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
    public const string LATEST_SCHEMA_URL = 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/2025-11-25/schema.json';
    private const string LATEST_SCHEMA_JSON_PATH = __DIR__.'/../../latest-schema.json';
    private const string SORTED_SCHEMA_JSON_PATH = __DIR__.'/../../sorted-schema.json';

    /**
     * @var array<string, mixed>
     */
    private static array $latestSchema = [];

    /**
     * @var array{
     *   schema: array<string, class-string>,
     *   nonSchema: array<string, class-string>,
     * }
     */
    private static array $sortedSchema = [
        'schema' => [],
        'nonSchema' => [],
    ];

    /**
     * @param class-string $schemaClass
     */
    #[DataProvider('provideSchemaDescriptionIsAccurateCases')]
    public function testSchemaDescriptionIsAccurate(string $schema, string $schemaClass): void
    {
        self::assertArrayHasKey($schema, self::$latestSchema, \sprintf('Schema key "%s" is missing in the latest schema definitions.', $schema));
        self::assertIsArray(self::$latestSchema[$schema], \sprintf('Schema definition for "%s" is not an array.', $schema));
        self::assertArrayHasKey('description', self::$latestSchema[$schema], \sprintf('Schema definition for "%s" does not have a description.', $schema));

        $description = self::$latestSchema[$schema]['description'];
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
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (self::$sortedSchema['schema'] as $basename => $schemaClass) {
            yield $basename => [$basename, $schemaClass];
        }
    }

    /**
     * @param class-string $schemaClass
     */
    #[DataProvider('provideSchemaTypeMatchesPropertiesCases')]
    public function testSchemaTypeMatchesProperties(string $schema, string $schemaClass): void
    {
        self::assertArrayHasKey($schema, self::$latestSchema, \sprintf('Schema key "%s" is missing in the latest schema definitions.', $schema));
        self::assertIsArray(self::$latestSchema[$schema], \sprintf('Schema definition for "%s" is not an array.', $schema));
        self::assertArrayHasKey('type', self::$latestSchema[$schema], \sprintf('Schema definition for "%s" does not have a type.', $schema));

        $type = self::$latestSchema[$schema]['type'];
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
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (self::$sortedSchema['schema'] as $basename => $schemaClass) {
            if (enum_exists($schemaClass)) {
                continue;
            }

            yield $basename => [$basename, $schemaClass];
        }
    }

    /**
     * @param class-string<\UnitEnum> $schemaClass
     */
    #[DataProvider('provideSchemaEnumMatchesCasesCases')]
    public function testSchemaEnumMatchesCases(string $schema, string $schemaClass): void
    {
        self::assertArrayHasKey($schema, self::$latestSchema, \sprintf('Schema key "%s" is missing in the latest schema definitions.', $schema));
        self::assertIsArray(self::$latestSchema[$schema], \sprintf('Schema definition for "%s" is not an array.', $schema));
        self::assertArrayHasKey('enum', self::$latestSchema[$schema], \sprintf('Schema definition for "%s" does not have an enum.', $schema));

        $enum = self::$latestSchema[$schema]['enum'];
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
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (self::$sortedSchema['schema'] as $basename => $schemaClass) {
            if (! enum_exists($schemaClass)) {
                continue;
            }

            yield $basename => [$basename, $schemaClass];
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

        $schemaJson = file_get_contents(self::LATEST_SCHEMA_URL);

        if (false === $schemaJson) {
            throw new \RuntimeException(\sprintf('Failed to fetch the latest schema from %s.', self::LATEST_SCHEMA_URL));
        }

        $decodedSchema = json_decode($schemaJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(\sprintf('Failed to decode the latest schema JSON: %s', json_last_error_msg()));
        }

        if (! \is_array($decodedSchema)) {
            throw new \RuntimeException('The decoded schema is not a valid array.');
        }

        if (! is_file(self::LATEST_SCHEMA_JSON_PATH) || getenv('MCP_FETCH_LATEST_SCHEMA') !== false) {
            // for debugging
            $encodedSchema = json_encode($decodedSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (false === $encodedSchema) {
                throw new \RuntimeException(\sprintf('Failed to encode the latest schema to JSON: %s', json_last_error_msg()));
            }

            $encodedSchema = strtr($encodedSchema, [
                '"additionalProperties": []' => '"additionalProperties": {}',
                '"properties": []' => '"properties": {}',
            ]);
            file_put_contents(self::LATEST_SCHEMA_JSON_PATH, $encodedSchema);
        }

        unset($decodedSchema['$schema']);

        if (! \array_key_exists('$defs', $decodedSchema) || ! \is_array($decodedSchema['$defs'])) {
            throw new \RuntimeException('The latest schema does not contain valid $defs.');
        }

        self::$latestSchema = $decodedSchema['$defs']; // @phpstan-ignore assign.propertyType
    }

    private static function sortSchemaDefinition(): void
    {
        if ([] === self::$latestSchema) {
            throw new \RuntimeException('Latest schema is not generated yet.');
        }

        if (
            ['schema' => [], 'nonSchema' => []] !== self::$sortedSchema
            && getenv('MCP_FETCH_LATEST_SCHEMA') === false
        ) {
            return;
        }

        foreach ([
            __DIR__.'/../../src/Core/Schema',
            __DIR__.'/../../src/Client/Schema',
            __DIR__.'/../../src/Server/Schema',
        ] as $schemaDir) {
            if (! is_dir($schemaDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($schemaDir, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::UNIX_PATHS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            $rootSrc = realpath(__DIR__.'/../../src/');
            \assert(false !== $rootSrc);

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $schemaClass = \sprintf(
                    'Nexus\\Mcp\\%s',
                    str_replace('/', '\\', substr((string) $file->getRealPath(), \strlen($rootSrc) + 1, -4)),
                );
                $basename = $file->getBasename('.php');

                \assert(class_exists($schemaClass) || interface_exists($schemaClass));

                if (\array_key_exists($basename, self::$latestSchema)) {
                    self::$sortedSchema['schema'][$basename] = $schemaClass;
                } else {
                    self::$sortedSchema['nonSchema'][$basename] = $schemaClass;
                }
            }
        }

        ksort(self::$sortedSchema['schema']);
        ksort(self::$sortedSchema['nonSchema']);

        if (! is_file(self::SORTED_SCHEMA_JSON_PATH) || getenv('MCP_FETCH_LATEST_SCHEMA') !== false) {
            $data = [
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('D, d M Y H:i:s T'),
                'sortedSchema' => self::$sortedSchema,
            ];

            $encodedData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (false === $encodedData) {
                throw new \RuntimeException(\sprintf('Failed to encode sorted schema data to JSON: %s', json_last_error_msg()));
            }

            file_put_contents(self::SORTED_SCHEMA_JSON_PATH, $encodedData);
        }
    }
}
