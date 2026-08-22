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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\EnumOption;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use Nexus\Mcp\Core\Schema\Icons;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\ClientNotification;
use Nexus\Mcp\Core\Schema\Notification\ServerNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Request\PaginatedRequest;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\InputResponseCarrierInterface;
use Nexus\Mcp\Core\Schema\RequestParams\ResourceRequestParams;
use Nexus\Mcp\Core\Schema\RequestParamsInterface;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ClientResult;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Core\Schema\Result\ServerResult;
use Nexus\Mcp\Core\Schema\Result\TaskHandleResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tools\McpAnchorSnapshot;
use Nexus\Mcp\Tools\McpSchemaProcessor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class SchemaConformanceTest extends AbstractMcpTestCase
{
    private const array SPEC_KEY_TO_NON_PROPERTY_REPRESENTATION = [
        'jsonrpc' => ['kind' => 'constant', 'name' => 'JSONRPC_VERSION'],
        'method' => ['kind' => 'static-method', 'name' => 'getMethod'],
        'resultType' => ['kind' => 'method', 'name' => 'getResultType'],
        'type' => ['kind' => 'constant', 'name' => 'TYPE'],
    ];
    private const array SPEC_KEY_TO_PHP_NAME = [
        'io.modelcontextprotocol/protocolVersion' => 'protocolVersion',
        'io.modelcontextprotocol/clientInfo' => 'clientInfo',
        'io.modelcontextprotocol/clientCapabilities' => 'clientCapabilities',
        'io.modelcontextprotocol/logLevel' => 'logLevel',
        'io.modelcontextprotocol/serverInfo' => 'serverInfo',
        'io.modelcontextprotocol/subscriptionId' => 'subscriptionId',
    ];
    private const string SCHEMA_ANCHOR_BASE_URL = 'https://modelcontextprotocol.io/specification/2026-07-28/schema#';
    private const string JSON_RPC_ERROR_OBJECT_URL = 'https://www.jsonrpc.org/specification#error_object';
    private const string TS_SCHEMA_FILE_URL = 'https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts';
    private const array NON_SCHEMA_ANCHOR_SEE_URLS = [
        BaseMetadata::class => self::TS_SCHEMA_FILE_URL,
        ElicitRequestedSchema::class => self::TS_SCHEMA_FILE_URL,
        EnumOption::class => self::TS_SCHEMA_FILE_URL,
        CacheScope::class => self::TS_SCHEMA_FILE_URL,
        ElicitAction::class => self::TS_SCHEMA_FILE_URL,
        ProtocolErrorCode::class => self::JSON_RPC_ERROR_OBJECT_URL,
        SdkErrorCode::class => self::JSON_RPC_ERROR_OBJECT_URL,
        UnknownProtocolError::class => self::JSON_RPC_ERROR_OBJECT_URL,
        Icons::class => 'https://modelcontextprotocol.io/specification/2026-07-28/basic#icons',
        Notification::class => self::TS_SCHEMA_FILE_URL,
        EmptyNotificationParams::class => self::TS_SCHEMA_FILE_URL,
        ClientNotification::class => self::TS_SCHEMA_FILE_URL,
        ServerNotification::class => self::TS_SCHEMA_FILE_URL,
        ProtocolVersion::class => 'https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning#protocol-version-negotiation',
        Request::class => self::TS_SCHEMA_FILE_URL,
        EmptyRequestParams::class => self::TS_SCHEMA_FILE_URL,
        ResourceRequestParams::class => self::TS_SCHEMA_FILE_URL,
        ClientRequest::class => self::TS_SCHEMA_FILE_URL,
        InputRequest::class => self::TS_SCHEMA_FILE_URL,
        PaginatedRequest::class => self::TS_SCHEMA_FILE_URL,
        ResourceContents::class => self::TS_SCHEMA_FILE_URL,
        CacheableResult::class => self::TS_SCHEMA_FILE_URL,
        ClientResult::class => self::TS_SCHEMA_FILE_URL,
        InputResponse::class => self::TS_SCHEMA_FILE_URL,
        PaginatedResult::class => self::TS_SCHEMA_FILE_URL,
        ServerResult::class => self::TS_SCHEMA_FILE_URL,
        TaskHandleResult::class => 'https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md',
    ];
    private const array SEE_ANNOTATION_EXEMPT = [
        Arrayable::class,
        GenericResultResponse::class,
        GenericResultMetaObject::class,
        InputResponseCarrierInterface::class,
        PayloadMetaObject::class,
        RequestParamsInterface::class,
    ];
    private const array DEPRECATED_OMITTED_PROPERTIES = [
        'ClientCapabilities' => ['roots', 'sampling'],
    ];

    /**
     * @var array<string, mixed>
     */
    private static array $latestSchema = [];

    /**
     * @var array{
     *   processed_schema: array<string, class-string>,
     *   internal_schema: array<string, class-string>,
     *   deprecated_schema: list<string>,
     *   inlined_schema: list<string>,
     *   unprocessed_schema: list<string>,
     * }
     */
    private static array $sortedSchema = [
        'processed_schema' => [],
        'internal_schema' => [],
        'deprecated_schema' => [],
        'inlined_schema' => [],
        'unprocessed_schema' => [],
    ];

    /**
     * @var null|array<string, array{members: list<string>, allowsResultSubclass: bool}>
     */
    private static ?array $specUnions = null;

    /**
     * @var null|array<string, list<string>>
     */
    private static ?array $anchorSnapshot = null;

    /**
     * @param class-string $schemaClass
     */
    #[DataProvider('provideSchemaDescriptionIsAccurateCases')]
    public function testSchemaDescriptionIsAccurate(string $schema, string $schemaClass): void
    {
        $description = $this->getSchemaProperty($schema, 'description');
        self::assertIsString($description, \sprintf('Description for schema "%s" is not a string.', $schema));

        $reflection = new \ReflectionClass($schemaClass);
        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment, \sprintf('Schema class "%s" does not have a PHPDoc comment.', $schemaClass));

        $this->assertDescriptionMatches($description, $docComment, $schemaClass);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function provideSchemaDescriptionIsAccurateCases(): iterable
    {
        /** @var list<class-string> $exclusions */
        static $exclusions = [
            Error::class,
            ClientNotification::class,
            ClientResult::class,
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
        $type = $this->getSchemaProperty($schema, 'type');
        self::assertThat($type, self::logicalOr(self::isArray(), self::isString()), \sprintf('Type for schema "%s" is neither string nor array.', $schema));
        \assert(\is_string($type) || \is_array($type));

        $reflection = new \ReflectionClass($schemaClass);
        $properties = $reflection->getProperties();

        if (\is_array($type)) {
            $type = array_map($this->normaliseJsonType(...), $type); // @phpstan-ignore argument.type
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
            $specOptionalKeys = array_values(array_diff(
                $specOptionalKeys,
                self::DEPRECATED_OMITTED_PROPERTIES[$schema] ?? [],
            ));

            $findings = [];

            $shape = $this->extractArrayableShape($reflection);

            if (null !== $shape) {
                foreach ($this->diffShapeAgainstSpec($shape, $specRequiredKeys, $specOptionalKeys, $schema) as $finding) {
                    $findings[] = \sprintf('[array shape] %s', $finding);
                }
            }

            $typedSpecProperties = [];

            foreach ($specProperties as $propertyName => $propertyShape) {
                if (\is_string($propertyName)) {
                    Assert::that($propertyShape)->isMap('Spec property shape must be a string-keyed object.');
                    $typedSpecProperties[$propertyName] = $propertyShape;
                }
            }

            foreach ($this->diffPropertiesAgainstSpec($reflection, $specRequiredKeys, $specOptionalKeys, $typedSpecProperties) as $finding) {
                $findings[] = \sprintf('[property] %s', $finding);
            }

            self::assertSame([], $findings, \sprintf(
                "Schema class \"%s\" diverges from spec's \"%s\":\n  * %s",
                $schemaClass,
                $schema,
                implode("\n  * ", $findings),
            ));
        } else {
            self::assertCount(1, $properties);
            self::assertInstanceOf(\ReflectionNamedType::class, $properties[0]->getType());

            $type = $this->normaliseJsonType($type);
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
            static function (string $class, string $basename): bool {
                $definition = self::$latestSchema[$basename] ?? null;

                if (! \is_array($definition) || ! \array_key_exists('type', $definition) || enum_exists($class) || interface_exists($class)) {
                    return false;
                }

                $properties = $definition['properties'] ?? [];

                return ! (is_subclass_of($class, Error::class) && \is_array($properties) && \array_key_exists('error', $properties));
            },
        );
    }

    /**
     * @param class-string<\BackedEnum> $schemaClass
     */
    #[DataProvider('provideSchemaEnumMatchesCasesCases')]
    public function testSchemaEnumMatchesCases(string $schema, string $schemaClass): void
    {
        $enum = $this->getSchemaProperty($schema, 'enum');
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
        yield from self::getProtocolSchemasForTesting(static function (string $class, string $basename): bool {
            if (! enum_exists($class)) {
                return false;
            }

            $definition = self::$latestSchema[$basename] ?? null;

            return \is_array($definition) && \is_array($definition['enum'] ?? null);
        });
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
     * @param class-string $schemaClass
     */
    #[DataProvider('provideSchemaClassDeclaresExpectedSeeAnnotationCases')]
    public function testSchemaClassDeclaresExpectedSeeAnnotation(string $schemaClass, string $expectedUrl): void
    {
        $reflection = new \ReflectionClass($schemaClass);
        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment, \sprintf('Schema class "%s" does not have a PHPDoc comment.', $schemaClass));

        $pattern = '/^\s*\*\s*@see\s+'.preg_quote($expectedUrl, '/').'\s*$/m';
        self::assertMatchesRegularExpression($pattern, $docComment, \sprintf(
            'Schema class "%s" must declare "@see %s" in its PHPDoc.',
            $schemaClass,
            $expectedUrl,
        ));

        preg_match_all('/^\s*\*\s*(@\S+)/m', $docComment, $tagMatches);
        $tags = $tagMatches[1];
        self::assertNotEmpty($tags, \sprintf('Schema class "%s" docblock has no recognisable tags.', $schemaClass));
        self::assertSame('@see', end($tags), \sprintf(
            'Schema class "%s": "@see" must be the last tag in the docblock, but the last tag is "%s".',
            $schemaClass,
            end($tags),
        ));

        if (parse_url($expectedUrl, \PHP_URL_HOST) !== 'modelcontextprotocol.io') {
            return;
        }

        $snapshot = $this->loadAnchorSnapshot();

        $hash = strpos($expectedUrl, '#');
        $pageUrl = false === $hash ? $expectedUrl : substr($expectedUrl, 0, $hash);
        $fragment = false === $hash ? null : substr($expectedUrl, $hash + 1);

        self::assertArrayHasKey($pageUrl, $snapshot, \sprintf(
            'Schema class "%s" references docs page "%s", but that page is not in the spec anchor snapshot. Add the page to McpAnchorSnapshot::SPEC_PAGES and run `composer spec:snapshot-anchors`.',
            $schemaClass,
            $pageUrl,
        ));

        if (null === $fragment) {
            return;
        }

        self::assertContains($fragment, $snapshot[$pageUrl], \sprintf(
            'Schema class "%s" references "%s#%s", but the snapshot of "%s" has no anchor "%s". Either fix the URL or refresh the snapshot via `composer spec:snapshot-anchors`.',
            $schemaClass,
            $pageUrl,
            $fragment,
            $pageUrl,
            $fragment,
        ));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function provideSchemaClassDeclaresExpectedSeeAnnotationCases(): iterable
    {
        foreach (self::getProtocolSchemasForTesting() as $basename => [, $schemaClass]) {
            if (\array_key_exists($schemaClass, self::NON_SCHEMA_ANCHOR_SEE_URLS)) {
                continue;
            }

            yield $basename => [$schemaClass, self::SCHEMA_ANCHOR_BASE_URL.strtolower($basename)];
        }

        foreach (self::NON_SCHEMA_ANCHOR_SEE_URLS as $schemaClass => $expectedUrl) {
            yield (new \ReflectionClass($schemaClass))->getShortName() => [$schemaClass, $expectedUrl];
        }
    }

    public function testEverySchemaClassIsAccountedForBySeeConformance(): void
    {
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        $accounted = [
            ...array_keys(self::NON_SCHEMA_ANCHOR_SEE_URLS),
            ...self::SEE_ANNOTATION_EXEMPT,
        ];

        $unaccounted = array_values(array_diff(
            array_values(self::$sortedSchema['internal_schema']),
            $accounted,
        ));

        self::assertSame([], $unaccounted, \sprintf(
            'Schema classes without a 1:1 spec def must be listed in either NON_SCHEMA_ANCHOR_SEE_URLS (with the spec URL their @see should reference) or SEE_ANNOTATION_EXEMPT (PHP-only infrastructure). Unaccounted: %s.',
            implode(', ', $unaccounted),
        ));
    }

    public function testEveryParamsDefCarryingAContinuationFieldImplementsTheCarrier(): void
    {
        $missing = [];

        foreach (self::getProtocolSchemasForTesting() as $basename => [, $schemaClass]) {
            if (! is_a($schemaClass, RequestParamsInterface::class, true)) {
                continue;
            }

            $definition = self::$latestSchema[$basename] ?? null;
            $properties = \is_array($definition) ? $definition['properties'] ?? [] : [];

            if (! \is_array($properties)) {
                continue;
            }

            $carries = \array_key_exists('inputResponses', $properties)
                || \array_key_exists('requestState', $properties);

            if ($carries && ! is_a($schemaClass, InputResponseCarrierInterface::class, true)) {
                $missing[] = $schemaClass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Params classes for spec defs carrying "inputResponses" or "requestState" must implement "%s", since PHP cannot inherit the spec\'s second base. Missing: %s.',
            InputResponseCarrierInterface::class,
            implode(', ', $missing),
        ));
    }

    public function testNonSchemaAnchorSeeUrlsIsSortedByKey(): void
    {
        $keys = array_keys(self::NON_SCHEMA_ANCHOR_SEE_URLS);
        $sorted = $keys;
        sort($sorted);

        self::assertSame($sorted, $keys, 'NON_SCHEMA_ANCHOR_SEE_URLS entries must be sorted by key (class FQN).');
    }

    public function testSeeAnnotationExemptClassesHaveNoSeeTag(): void
    {
        foreach (self::SEE_ANNOTATION_EXEMPT as $schemaClass) {
            $reflection = new \ReflectionClass($schemaClass);
            $docComment = $reflection->getDocComment();

            if (! \is_string($docComment)) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/^\s*\*\s*@see\s+/m',
                $docComment,
                \sprintf('Schema class "%s" is in SEE_ANNOTATION_EXEMPT but declares an @see tag. Remove the tag or move the class out of the exemption list.', $schemaClass),
            );
        }
    }

    public function testDeprecatedOmittedPropertiesStayOmittable(): void
    {
        self::generateLatestSchema();
        self::sortSchemaDefinition();

        foreach (self::DEPRECATED_OMITTED_PROPERTIES as $schema => $keys) {
            $schemaDef = self::$latestSchema[$schema] ?? null;
            self::assertIsArray($schemaDef, \sprintf(
                'Deprecated schema "%s" is no longer in the spec. Remove its DEPRECATED_OMITTED_PROPERTIES entry.',
                $schema,
            ));

            $properties = $schemaDef['properties'] ?? [];
            self::assertIsArray($properties);

            $required = $schemaDef['required'] ?? [];
            self::assertIsArray($required);

            $schemaClass = self::$sortedSchema['processed_schema'][$schema] ?? null;
            self::assertIsString($schemaClass, \sprintf('Deprecated schema "%s" has no PHP class.', $schema));

            $reflection = new \ReflectionClass($schemaClass);
            $constructor = $reflection->getConstructor();
            $ctorParams = null === $constructor
                ? []
                : array_map(static fn(\ReflectionParameter $param) => $param->getName(), $constructor->getParameters());

            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $properties, \sprintf(
                    'Deprecated property "%s.%s" is gone from the spec. Remove the DEPRECATED_OMITTED_PROPERTIES entry.',
                    $schema,
                    $key,
                ));

                self::assertNotContains($key, $required, \sprintf(
                    'Deprecated property "%s.%s" is now required and can no longer be omitted. The SDK must implement it.',
                    $schema,
                    $key,
                ));

                $phpName = $this->specKeyToPhpName($key);
                $modelled = \in_array($phpName, $ctorParams, true)
                    || ($reflection->hasProperty($phpName) && $reflection->getProperty($phpName)->isPublic());

                self::assertFalse($modelled, \sprintf(
                    'Deprecated property "%s.%s" now has a PHP representation. Remove its DEPRECATED_OMITTED_PROPERTIES entry so conformance enforces it.',
                    $schema,
                    $key,
                ));
            }
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

        if (! $licensed) {
            foreach ($unionData['members'] as $memberBasename) {
                $memberClass = self::$sortedSchema['processed_schema'][$memberBasename] ?? null;

                if (\is_string($memberClass) && is_subclass_of($implementer, $memberClass)) {
                    $licensed = true;

                    break;
                }
            }
        }

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

                if (\array_key_exists($basename, self::getSpecUnions())) {
                    continue;
                }

                yield \sprintf('%s implements %s', $candidate, $union) => [$union, $unionInterface, $candidate, $basename];
            }
        }
    }

    private function assertDescriptionMatches(string $description, string $docComment, string $schemaClass): void
    {
        $body = $this->extractDocblockNarrative($docComment);

        $normalise = static fn(string $s): string => rtrim(trim((string) preg_replace('/\s+/', ' ', $s)), '.');

        self::assertSame(
            $normalise($description),
            $normalise($body),
            \sprintf('Schema class "%s" PHPDoc narrative must match the spec description verbatim.', $schemaClass),
        );
    }

    /**
     * @param \ReflectionClass<object>            $reflection
     * @param list<string>                        $specRequired
     * @param list<string>                        $specOptional
     * @param array<string, array<string, mixed>> $specProperties
     *
     * @return list<string>
     */
    private function diffPropertiesAgainstSpec(
        \ReflectionClass $reflection,
        array $specRequired,
        array $specOptional,
        array $specProperties = [],
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
            $specType = $specProperties[$key]['type'] ?? null;
            $specAllowsNull = \is_array($specType) && \in_array('null', $specType, true);

            if (isset(self::SPEC_KEY_TO_NON_PROPERTY_REPRESENTATION[$key])) {
                $rep = self::SPEC_KEY_TO_NON_PROPERTY_REPRESENTATION[$key];

                if ('constant' === $rep['kind'] && ! $reflection->hasConstant($rep['name'])) {
                    $findings[] = \sprintf(
                        '"%s" must be backed by class constant %s::%s but it is not defined.',
                        $key,
                        $reflection->getShortName(),
                        $rep['name'],
                    );
                }

                if ('static-method' === $rep['kind']) {
                    if (! $reflection->hasMethod($rep['name'])) {
                        $findings[] = \sprintf(
                            '"%s" must be backed by public static method %s::%s() but it is not defined.',
                            $key,
                            $reflection->getShortName(),
                            $rep['name'],
                        );
                    } else {
                        $accessor = $reflection->getMethod($rep['name']);

                        if (! $accessor->isPublic() || ! $accessor->isStatic()) {
                            $findings[] = \sprintf(
                                '"%s" must be backed by public static method %s::%s() but its visibility/staticness is wrong.',
                                $key,
                                $reflection->getShortName(),
                                $rep['name'],
                            );
                        }
                    }
                }

                if ('method' === $rep['kind'] && ! $reflection->hasMethod($rep['name'])) {
                    $findings[] = \sprintf(
                        '"%s" must be backed by method %s::%s() but it is not defined.',
                        $key,
                        $reflection->getShortName(),
                        $rep['name'],
                    );
                }

                if (! $isRequired) {
                    $findings[] = \sprintf(
                        '"%s" is optional but is backed by an always-present constant/method. Consider whether the spec marks it as required.',
                        $key,
                    );
                }

                continue;
            }

            $phpName = $this->specKeyToPhpName($key);

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
                    '"%s" has no constructor parameter $%s, no public property $%s, no class constant, and no public static method on %s.',
                    $key,
                    $phpName,
                    $phpName,
                    $reflection->getShortName(),
                );

                continue;
            }

            $isMixed = $type instanceof \ReflectionNamedType && $type->getName() === 'mixed';

            if ($isRequired) {
                if ($hasDefault) {
                    $findings[] = \sprintf(
                        '"%s" is required but %s has a default value. Remove the default.',
                        $key,
                        $source,
                    );
                }

                if ($allowsNull && ! $isMixed && ! $specAllowsNull) {
                    $findings[] = \sprintf(
                        '"%s" is required but %s is nullable. Remove the "?" from its type.',
                        $key,
                        $source,
                    );
                }
            } elseif (! $hasDefault && ! $allowsNull) {
                $findings[] = \sprintf(
                    '"%s" is optional but %s is neither nullable nor has a default value. Add `= null` (or any default) so callers can omit it, or change the type to `?T`.',
                    $key,
                    $source,
                );
            }

            $specScalar = $this->resolveUnambiguousScalarJsonType($specProperties[$key] ?? []);

            if (
                $this->specAllowsAnyJsonValue($specProperties[$key] ?? [])
                && ! ($type instanceof \ReflectionNamedType && $type->getName() === 'mixed')
            ) {
                $findings[] = \sprintf(
                    '"%s" is any JSON value in the spec but %s is typed "%s".',
                    $key,
                    $source,
                    null === $type ? 'untyped' : (string) $type,
                );
            }

            if (
                null !== $specScalar
                && $type instanceof \ReflectionNamedType
                && $type->isBuiltin()
                && \in_array($type->getName(), ['int', 'float', 'string', 'bool'], true)
            ) {
                $expectedPhp = $this->normaliseJsonType($specScalar);

                if ($type->getName() !== $expectedPhp) {
                    $findings[] = \sprintf(
                        '"%s" is spec JSON type "%s" (PHP "%s") but %s is typed "%s".',
                        $key,
                        $specScalar,
                        $expectedPhp,
                        $source,
                        $type->getName(),
                    );
                }
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * @param array<string, mixed> $propertyShape
     */
    private function specAllowsAnyJsonValue(array $propertyShape): bool
    {
        foreach (['$ref', 'anyOf', 'oneOf', 'allOf', 'enum', 'const', 'type'] as $narrowing) {
            if (\array_key_exists($narrowing, $propertyShape)) {
                return false;
            }
        }

        return [] !== $propertyShape;
    }

    /**
     * @param array<string, mixed> $propertyShape
     */
    private function resolveUnambiguousScalarJsonType(array $propertyShape): ?string
    {
        foreach (['$ref', 'anyOf', 'oneOf', 'allOf', 'enum', 'const'] as $disqualifier) {
            if (\array_key_exists($disqualifier, $propertyShape)) {
                return null;
            }
        }

        $type = $propertyShape['type'] ?? null;

        if (\is_array($type)) {
            $type = array_values(array_filter($type, static fn(mixed $member): bool => 'null' !== $member));
            $type = \count($type) === 1 ? $type[0] : null;
        }

        return \is_string($type) && \in_array($type, ['string', 'integer', 'number', 'boolean'], true)
            ? $type
            : null;
    }

    private function specKeyToPhpName(string $key): string
    {
        return self::SPEC_KEY_TO_PHP_NAME[$key] ?? ltrim($key, '_');
    }

    /**
     * @param array{required: list<string>, optional: list<string>} $shape
     * @param list<string>                                          $specRequired
     * @param list<string>                                          $specOptional
     *
     * @return list<string>
     */
    private function diffShapeAgainstSpec(array $shape, array $specRequired, array $specOptional, string $schema): array
    {
        $findings = [];

        foreach (array_diff($shape['required'], $specRequired) as $key) {
            if (\in_array($key, $specOptional, true)) {
                $findings[] = \sprintf('"%s" is declared as required (no "?") but spec "%s" marks it optional. Use "%s?:" instead.', $key, $schema, $key);
            } else {
                $findings[] = \sprintf('"%s" is declared as required but spec "%s" does not list it. Remove it from the shape.', $key, $schema);
            }
        }

        foreach (array_diff($shape['optional'], $specOptional) as $key) {
            if (\in_array($key, $specRequired, true)) {
                $findings[] = \sprintf('"%s" is declared as optional ("%s?:") but spec "%s" marks it required. Drop the "?".', $key, $key, $schema);
            } else {
                $findings[] = \sprintf('"%s" is declared as optional but spec "%s" does not list it. Remove it from the shape.', $key, $schema);
            }
        }

        foreach (array_diff($specRequired, $shape['required'], $shape['optional']) as $key) {
            $findings[] = \sprintf('"%s" is required by spec "%s" but is missing from the shape. Add "%s:".', $key, $schema, $key);
        }

        foreach (array_diff($specOptional, $shape['optional'], $shape['required']) as $key) {
            $findings[] = \sprintf('"%s" is optional in spec "%s" but is missing from the shape. Add "%s?:".', $key, $schema, $key);
        }

        sort($findings);

        return $findings;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return null|array{required: list<string>, optional: list<string>}
     */
    private function extractArrayableShape(\ReflectionClass $reflection): ?array
    {
        $docComment = $reflection->getDocComment();

        if (! \is_string($docComment)) {
            return null;
        }

        return $this->parseShapeAfter($docComment, '/@implements\s+\w+\s*<\s*array\{/')
            ?? $this->parseShapeAfter($docComment, '/@extends\s+\w+\s*<\s*[^<>]*?array\{/');
    }

    /**
     * @return null|array{required: list<string>, optional: list<string>}
     */
    private function parseShapeAfter(string $docComment, string $openerPattern): ?array
    {
        if (preg_match($openerPattern, $docComment, $matches) !== 1) {
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
        $inner = $this->extractBalancedBraces($docComment, $start);

        if (null === $inner) {
            return null;
        }

        $inner = (string) preg_replace('/\n\s*\*\s?/', "\n", $inner);

        return $this->parseShapeKeys($inner);
    }

    private function extractBalancedBraces(string $haystack, int $start): ?string
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
     * @return array{required: list<string>, optional: list<string>}
     */
    private function parseShapeKeys(string $inner): array
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

            if (preg_match('/^([\'"]?)([A-Za-z_][\w\-.\/]*)\1(\??):/', $entry, $m) !== 1) {
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

    private function extractDocblockNarrative(string $docComment): string
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

    private function normaliseJsonType(string $type): string
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

        $emptySchema = ['processed_schema' => [], 'internal_schema' => [], 'deprecated_schema' => [], 'inlined_schema' => [], 'unprocessed_schema' => []];

        if ($emptySchema !== self::$sortedSchema && getenv('MCP_FETCH_LATEST_SCHEMA') === false) {
            return;
        }

        self::$sortedSchema = McpSchemaProcessor::sortAndSaveSchema(self::$latestSchema);
    }

    private function getSchemaProperty(string $schema, string $property): mixed
    {
        self::assertArrayHasKey($schema, self::$latestSchema, \sprintf('Schema key "%s" is missing in the latest schema definitions.', $schema));
        self::assertIsArray(self::$latestSchema[$schema], \sprintf('Schema definition for "%s" is not an array.', $schema));
        self::assertArrayHasKey($property, self::$latestSchema[$schema], \sprintf('Schema definition for "%s" does not have "%s".', $schema, $property));

        return self::$latestSchema[$schema][$property]; // @phpstan-ignore offsetAccess.notFound
    }

    /**
     * @return array<string, array{members: list<string>, allowsResultSubclass: bool}>
     */
    private static function getSpecUnions(): array
    {
        if (null !== self::$specUnions) {
            return self::$specUnions;
        }

        self::generateLatestSchema();
        self::sortSchemaDefinition();

        $prefix = '#/$defs/';
        $unions = [];

        foreach (self::$latestSchema as $name => $def) {
            if (! \is_array($def) || ! \is_array($def['anyOf'] ?? null)) {
                continue;
            }

            if (! \is_string($name) || ! isset(self::$sortedSchema['processed_schema'][$name])) {
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

        self::$specUnions = $unions;

        return self::$specUnions;
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadAnchorSnapshot(): array
    {
        if (null !== self::$anchorSnapshot) {
            return self::$anchorSnapshot;
        }

        self::$anchorSnapshot = McpAnchorSnapshot::loadAnchorSnapshot();

        return self::$anchorSnapshot;
    }

    /**
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
