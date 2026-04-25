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
     * Spec keys that the SDK represents on the class as a constant or static
     * accessor instead of a constructor parameter (always-required values).
     */
    private const array SPEC_KEY_TO_NON_PROPERTY_REPRESENTATION = [
        'jsonrpc' => ['kind' => 'constant', 'name' => 'JSONRPC_VERSION'],
        'method' => ['kind' => 'static-method', 'name' => 'method'],
    ];

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

        self::assertDescriptionMatches($description, $docComment, $schemaClass);
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
            self::assertArrayHasKey($schema, self::$latestSchema);
            $schemaDef = self::$latestSchema[$schema];
            self::assertIsArray($schemaDef);

            $specProperties = $schemaDef['properties'] ?? [];
            self::assertIsArray($specProperties);

            $specRequired = $schemaDef['required'] ?? [];
            self::assertIsArray($specRequired);

            $specPropertyKeys = array_filter(array_keys($specProperties), is_string(...));
            $specRequiredKeys = array_values(array_filter($specRequired, is_string(...)));
            $specOptionalKeys = array_values(array_diff($specPropertyKeys, $specRequiredKeys));

            $findings = [];

            $shape = self::extractArrayableShape($reflection);

            if (null !== $shape) {
                foreach (self::diffShapeAgainstSpec($shape, $specRequiredKeys, $specOptionalKeys, $schema) as $finding) {
                    $findings[] = '[@implements Arrayable<...>] '.$finding;
                }
            }

            foreach (self::diffPropertiesAgainstSpec($reflection, $specRequiredKeys, $specOptionalKeys) as $finding) {
                $findings[] = '[property structure] '.$finding;
            }

            self::assertSame([], $findings, \sprintf(
                "Schema class \"%s\" diverges from spec \"%s\":\n  * %s",
                $schemaClass,
                $schema,
                implode("\n  * ", $findings),
            ));
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

    /**
     * @return iterable<string, array{string, class-string}>
     */
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

    /**
     * @return iterable<string, array{string, class-string}>
     */
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

    private static function assertDescriptionMatches(string $description, string $docComment, string $schemaClass): void
    {
        $body = self::extractDocblockNarrative($docComment);

        $normalise = static fn(string $s): string => rtrim(trim((string) preg_replace('/\s+/', ' ', $s)), '.');

        self::assertSame(
            $normalise($description),
            $normalise($body),
            \sprintf('Schema class "%s" PHPDoc narrative must match the spec description verbatim.', $schemaClass),
        );
    }

    /**
     * Verify each spec property has a corresponding PHP representation and
     * that required/optional matches: required spec keys must have no default;
     * optional spec keys must have a default. Spec keys backed by a constant
     * or static method (jsonrpc, method) are always-required and verified
     * to exist on the class.
     *
     * @param \ReflectionClass<object> $reflection
     * @param list<string>             $specRequired
     * @param list<string>             $specOptional
     *
     * @return list<string>
     */
    private static function diffPropertiesAgainstSpec(
        \ReflectionClass $reflection,
        array $specRequired,
        array $specOptional,
    ): array {
        $findings = [];
        $constructor = $reflection->getConstructor();
        $params = [];

        if (null !== $constructor) {
            foreach ($constructor->getParameters() as $param) {
                $params[$param->getName()] = $param;
            }
        }

        foreach ([...$specRequired, ...$specOptional] as $key) {
            $isRequired = \in_array($key, $specRequired, true);

            if (isset(self::SPEC_KEY_TO_NON_PROPERTY_REPRESENTATION[$key])) {
                $rep = self::SPEC_KEY_TO_NON_PROPERTY_REPRESENTATION[$key];

                if ('constant' === $rep['kind'] && ! $reflection->hasConstant($rep['name'])) {
                    $findings[] = \sprintf(
                        'spec \'%s\' must be backed by class constant %s::%s but it is not defined.',
                        $key,
                        $reflection->getShortName(),
                        $rep['name'],
                    );
                }

                if ('static-method' === $rep['kind']) {
                    if (! $reflection->hasMethod($rep['name'])) {
                        $findings[] = \sprintf(
                            'spec \'%s\' must be backed by public static method %s::%s() but it is not defined.',
                            $key,
                            $reflection->getShortName(),
                            $rep['name'],
                        );
                    } else {
                        $accessor = $reflection->getMethod($rep['name']);

                        if (! $accessor->isPublic() || ! $accessor->isStatic()) {
                            $findings[] = \sprintf(
                                'spec \'%s\' must be backed by public static method %s::%s() but its visibility/staticness is wrong.',
                                $key,
                                $reflection->getShortName(),
                                $rep['name'],
                            );
                        }
                    }
                }

                if (! $isRequired) {
                    $findings[] = \sprintf(
                        'spec \'%s\' is optional but is backed by an always-present constant/method; consider whether the spec marks it required.',
                        $key,
                    );
                }

                continue;
            }

            $phpName = self::specKeyToPhpName($key);

            if (\array_key_exists($phpName, $params)) {
                $param = $params[$phpName];
                $hasDefault = $param->isDefaultValueAvailable();
                $type = $param->getType();
                $allowsNull = null !== $type && $type->allowsNull();
                $source = \sprintf('constructor parameter $%s', $phpName);
            } elseif ($reflection->hasProperty($phpName) && $reflection->getProperty($phpName)->isPublic()) {
                $property = $reflection->getProperty($phpName);
                $hasDefault = $property->hasDefaultValue();
                $type = $property->getType();
                $allowsNull = null !== $type && $type->allowsNull();
                $source = \sprintf('property $%s', $phpName);
            } else {
                $findings[] = \sprintf(
                    'spec \'%s\' has no constructor parameter $%s, no public property $%s, no class constant, and no public static method on %s.',
                    $key,
                    $phpName,
                    $phpName,
                    $reflection->getShortName(),
                );

                continue;
            }

            if ($isRequired) {
                if ($hasDefault) {
                    $findings[] = \sprintf(
                        'spec \'%s\' is required but %s has a default value; remove the default.',
                        $key,
                        $source,
                    );
                }

                if ($allowsNull) {
                    $findings[] = \sprintf(
                        'spec \'%s\' is required but %s is nullable; remove the \'?\' from its type.',
                        $key,
                        $source,
                    );
                }
            } elseif (! $hasDefault && ! $allowsNull) {
                $findings[] = \sprintf(
                    'spec \'%s\' is optional but %s is neither nullable nor has a default value; add `= null` (or any default) so callers can omit it, or change the type to `?T`.',
                    $key,
                    $source,
                );
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * Map a spec property name to its expected PHP constructor parameter name.
     * The leading underscore on `_meta` is dropped to match the project's
     * convention of `$meta` for the meta value object.
     */
    private static function specKeyToPhpName(string $key): string
    {
        return ltrim($key, '_');
    }

    /**
     * Compare a parsed shape against the spec's required/optional sets and
     * return human-readable findings explaining exactly what is wrong and how
     * to fix it. An empty list means the shape matches the spec.
     *
     * @param array{required: list<string>, optional: list<string>} $shape
     * @param list<string>                                          $specRequired
     * @param list<string>                                          $specOptional
     *
     * @return list<string>
     */
    private static function diffShapeAgainstSpec(array $shape, array $specRequired, array $specOptional, string $schema): array
    {
        $findings = [];

        foreach (array_diff($shape['required'], $specRequired) as $key) {
            if (\in_array($key, $specOptional, true)) {
                $findings[] = \sprintf('\'%s\' is declared as required (no \'?\') but spec "%s" marks it optional; use \'%s?:\' instead.', $key, $schema, $key);
            } else {
                $findings[] = \sprintf('\'%s\' is declared as required but spec "%s" does not list it; remove it from the shape.', $key, $schema);
            }
        }

        foreach (array_diff($shape['optional'], $specOptional) as $key) {
            if (\in_array($key, $specRequired, true)) {
                $findings[] = \sprintf('\'%s\' is declared as optional (\'%s?:\') but spec "%s" marks it required; drop the \'?\'.', $key, $key, $schema);
            } else {
                $findings[] = \sprintf('\'%s\' is declared as optional but spec "%s" does not list it; remove it from the shape.', $key, $schema);
            }
        }

        foreach (array_diff($specRequired, $shape['required'], $shape['optional']) as $key) {
            $findings[] = \sprintf('\'%s\' is required by spec "%s" but is missing from the shape; add \'%s:\'.', $key, $schema, $key);
        }

        foreach (array_diff($specOptional, $shape['optional'], $shape['required']) as $key) {
            $findings[] = \sprintf('\'%s\' is optional in spec "%s" but is missing from the shape; add \'%s?:\'.', $key, $schema, $key);
        }

        sort($findings);

        return $findings;
    }

    /**
     * Extract top-level keys from a class's `@implements Arrayable<array{...}>`
     * docblock. Reads the class's own docblock (no walk-up). Returns null when
     * the class has no docblock, no `@implements Arrayable<array{...}>`, or a
     * loose shape (e.g. `Arrayable<array<string, mixed>>`).
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return null|array{required: list<string>, optional: list<string>}
     */
    private static function extractArrayableShape(\ReflectionClass $reflection): ?array
    {
        $docComment = $reflection->getDocComment();

        if (! \is_string($docComment)) {
            return null;
        }

        return self::parseShapeAfter($docComment, '/@implements\s+Arrayable\s*<\s*array\{/');
    }

    /**
     * Find the literal `array{` opener using `$openerPattern`, balance-extract
     * its inner content, then split into top-level keys.
     *
     * @return null|array{required: list<string>, optional: list<string>}
     */
    private static function parseShapeAfter(string $docComment, string $openerPattern): ?array
    {
        if (1 !== preg_match($openerPattern, $docComment, $matches)) {
            return null;
        }

        $opener = array_shift($matches);

        if (! \is_string($opener)) {
            return null;
        }

        $offset = strpos($docComment, $opener);

        if (false === $offset) {
            return null;
        }

        $start = $offset + \strlen($opener);
        $inner = self::extractBalancedBraces($docComment, $start);

        if (null === $inner) {
            return null;
        }

        $inner = (string) preg_replace('/\n\s*\*\s?/', "\n", $inner);

        return self::parseShapeKeys($inner);
    }

    /**
     * Walk forward from `$start` (just past an opening `{`), tracking nested
     * `{}` and `<>` and quote runs, until the matching `}`. Returns the inner
     * content (excluding the closing brace), or null if unbalanced.
     */
    private static function extractBalancedBraces(string $haystack, int $start): ?string
    {
        $depth = 1;
        $offset = $start;
        $length = \strlen($haystack);
        $inSingle = false;
        $inDouble = false;

        while ($offset < $length && $depth > 0) {
            $char = $haystack[$offset];

            if ($inSingle) {
                if ('\'' === $char) {
                    $inSingle = false;
                }
            } elseif ($inDouble) {
                if ('"' === $char) {
                    $inDouble = false;
                }
            } elseif ('\'' === $char) {
                $inSingle = true;
            } elseif ('"' === $char) {
                $inDouble = true;
            } elseif ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
            }

            if ($depth > 0) {
                ++$offset;
            }
        }

        if (0 !== $depth) {
            return null;
        }

        return substr($haystack, $start, $offset - $start);
    }

    /**
     * Split a shape body into top-level keys, distinguishing `key:` (required)
     * from `key?:` (optional).
     *
     * @return array{required: list<string>, optional: list<string>}
     */
    private static function parseShapeKeys(string $inner): array
    {
        $required = [];
        $optional = [];
        $current = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;

        $flush = static function (string $entry) use (&$required, &$optional): void {
            $entry = trim($entry);

            if ('' === $entry) {
                return;
            }

            if (1 !== preg_match('/^([\'"]?)([A-Za-z_][\w\-]*)\1(\??):/', $entry, $m)) {
                return;
            }

            if ('?' === $m[3]) {
                $optional[] = $m[2];
            } else {
                $required[] = $m[2];
            }
        };

        foreach (mb_str_split($inner) as $char) {
            if ($inSingle) {
                if ('\'' === $char) {
                    $inSingle = false;
                }
            } elseif ($inDouble) {
                if ('"' === $char) {
                    $inDouble = false;
                }
            } elseif ('\'' === $char) {
                $inSingle = true;
            } elseif ('"' === $char) {
                $inDouble = true;
            } elseif ('{' === $char || '<' === $char) {
                ++$depth;
            } elseif ('}' === $char || '>' === $char) {
                --$depth;
            } elseif (',' === $char && 0 === $depth) {
                $flush($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $flush($current);

        return ['required' => $required, 'optional' => $optional];
    }

    /**
     * Extract the narrative prose of a docblock: lines between the opener and
     * the first `@tag`, with the `*` line prefix stripped.
     */
    private static function extractDocblockNarrative(string $docComment): string
    {
        $body = [];

        foreach (explode("\n", $docComment) as $line) {
            $trimmed = trim($line);

            if ('/**' === $trimmed || '*/' === $trimmed) {
                continue;
            }

            $content = preg_replace('/^\s*\*\s?/', '', $line) ?? '';

            if (str_starts_with(ltrim($content), '@')) {
                break;
            }

            $body[] = $content;
        }

        return implode("\n", $body);
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
