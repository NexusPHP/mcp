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
use Nexus\Mcp\Core\Schema\Result;
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
     * @var null|array<string, array{members: list<string>, allowsResultSubclass: bool}>
     */
    private static ?array $specUnions = null;

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
        /** @var list<class-string> $exclusions */
        static $exclusions = [
            Error::class,
        ];

        yield from self::getProtocolSchemasForTesting(
            static fn(string $class, string $basename): bool => ! \in_array($class, $exclusions, true)
                && \is_array(self::$latestSchema[$basename] ?? null)
                && \array_key_exists('description', self::$latestSchema[$basename]),
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
            self::assertTrue(
                $reflection->implementsInterface(Arrayable::class) || $reflection->hasMethod('toArray'),
                \sprintf('Schema class "%s" must implement %s or expose a public toArray() method.', $schemaClass, Arrayable::class),
            );
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
        yield from self::getProtocolSchemasForTesting(
            static fn(string $class, string $basename): bool => ! enum_exists($class)
                && \is_array(self::$latestSchema[$basename] ?? null)
                && \array_key_exists('type', self::$latestSchema[$basename]),
        );
    }

    /**
     * @param class-string<\BackedEnum> $schemaClass
     */
    #[DataProvider('provideSchemaEnumMatchesCasesCases')]
    public function testSchemaEnumMatchesCases(string $schema, string $schemaClass): void
    {
        $enum = self::getSchemaProperty($schema, 'enum');
        self::assertIsArray($enum, \sprintf('Enum for schema "%s" is not an array.', $schema));
        self::assertSame($enum, array_filter($enum, is_string(...)), \sprintf('Enum for schema "%s" contains non-string values.', $schema));

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

    /**
     * @param class-string $memberClass
     * @param class-string $unionInterface
     */
    #[DataProvider('provideSchemaUnionMemberImplementsMarkerCases')]
    public function testSchemaUnionMemberImplementsMarker(string $union, string $member, string $unionInterface, string $memberClass): void
    {
        self::assertTrue(
            is_subclass_of($memberClass, $unionInterface),
            \sprintf(
                'Class "%s" is a member of spec union "%s" but does not implement marker interface "%s".',
                $memberClass,
                $union,
                $unionInterface,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string, class-string, class-string}>
     */
    public static function provideSchemaUnionMemberImplementsMarkerCases(): iterable
    {
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (self::getSpecUnions() as $union => $unionData) {
            $unionInterface = self::$sortedSchema['processed_schema'][$union] ?? null;
            self::assertIsString($unionInterface, \sprintf('Spec union "%s" has no PHP marker interface.', $union));

            foreach ($unionData['members'] as $member) {
                $memberClass = self::$sortedSchema['processed_schema'][$member] ?? null;

                if (! \is_string($memberClass)) {
                    continue;
                }

                yield \sprintf('%s contains %s', $union, $member) => [$union, $member, $unionInterface, $memberClass];
            }
        }
    }

    /**
     * @param class-string $unionInterface
     * @param class-string $implementer
     */
    #[DataProvider('provideSchemaUnionMarkerImplementersAreInUnionCases')]
    public function testSchemaUnionMarkerImplementersAreInUnion(string $union, string $unionInterface, string $implementer, string $implementerBasename): void
    {
        $unionData = self::getSpecUnions()[$union] ?? null;
        self::assertIsArray($unionData);

        $licensed = \in_array($implementerBasename, $unionData['members'], true)
            || ($unionData['allowsResultSubclass'] && is_subclass_of($implementer, Result::class));

        self::assertTrue($licensed, \sprintf(
            'Class "%s" implements marker "%s" but basename "%s" is not in spec union "%s".',
            $implementer,
            $unionInterface,
            $implementerBasename,
            $union,
        ));
    }

    /**
     * @return iterable<string, array{string, class-string, class-string, string}>
     */
    public static function provideSchemaUnionMarkerImplementersAreInUnionCases(): iterable
    {
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (array_keys(self::getSpecUnions()) as $union) {
            $unionInterface = self::$sortedSchema['processed_schema'][$union] ?? null;

            if (! \is_string($unionInterface)) {
                continue;
            }

            foreach (self::$sortedSchema['processed_schema'] as $basename => $candidate) {
                if ($candidate === $unionInterface) {
                    continue;
                }

                if (! is_subclass_of($candidate, $unionInterface)) {
                    continue;
                }

                yield \sprintf('%s implements %s', $candidate, $union) => [$union, $unionInterface, $candidate, $basename];
            }
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
     * Resolve the six spec-level direction unions (`ClientRequest`, `ServerRequest`,
     * `ClientNotification`, `ServerNotification`, `ClientResult`, `ServerResult`)
     * to their explicit member basenames. The bare `{"$ref": "Result"}` entry
     * appearing in `ClientResult` / `ServerResult` is recorded separately as a
     * structural license, not folded into the explicit members list.
     *
     * @return array<string, array{members: list<string>, allowsResultSubclass: bool}>
     */
    private static function getSpecUnions(): array
    {
        if (null !== self::$specUnions) {
            return self::$specUnions;
        }

        self::generateLatestSchema();

        $prefix = '#/$defs/';
        $unions = [];

        foreach (['ClientRequest', 'ServerRequest', 'ClientNotification', 'ServerNotification', 'ClientResult', 'ServerResult'] as $name) {
            $def = self::$latestSchema[$name] ?? null;

            if (! \is_array($def) || ! \is_array($def['anyOf'] ?? null)) {
                continue;
            }

            $members = [];
            $allowsResultSubclass = false;

            /** @var array<int, mixed> $anyOf */
            $anyOf = $def['anyOf'];

            foreach ($anyOf as $entry) {
                if (! \is_array($entry) || ! \is_string($entry['$ref'] ?? null) || ! str_starts_with($entry['$ref'], $prefix)) {
                    continue;
                }

                $member = substr($entry['$ref'], \strlen($prefix));

                if ('Result' === $member) {
                    $allowsResultSubclass = true;

                    continue;
                }

                $members[] = $member;
            }

            $unions[$name] = ['members' => $members, 'allowsResultSubclass' => $allowsResultSubclass];
        }

        return self::$specUnions = $unions;
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
