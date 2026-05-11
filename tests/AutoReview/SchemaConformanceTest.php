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
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\Enum\IncludeContext;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\TaskSupport;
use Nexus\Mcp\Core\Schema\Enum\ToolChoiceMode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use Nexus\Mcp\Core\Schema\Icons;
use Nexus\Mcp\Core\Schema\JsonRpc\PaginatedRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\UrlElicitationRequiredError;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\ClientNotification;
use Nexus\Mcp\Core\Schema\Notification\ServerNotification;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\ElicitationCompleteNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\Request\ServerRequest;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\CancelTaskRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\GetTaskPayloadRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\GetTaskRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ResourceRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\TaskAugmentedRequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ClientResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Core\Schema\Result\ServerResult;
use Nexus\Mcp\Tools\McpAnchorSnapshot;
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
        'type' => ['kind' => 'constant', 'name' => 'TYPE'],
    ];

    private const string SCHEMA_ANCHOR_BASE_URL = 'https://modelcontextprotocol.io/specification/2025-11-25/schema#';
    private const string JSON_RPC_ERROR_OBJECT_URL = 'https://www.jsonrpc.org/specification#error_object';
    private const string TS_SCHEMA_FILE_URL = 'https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts';

    /**
     * Schema classes whose `@see` cannot be the default 1:1 schema-anchor URL,
     * because either (a) the class has no dedicated spec def, (b) the class's
     * spec def is on a different docs page than the schema reference, or
     * (c) the spec def exists in the TypeScript schema but the docs site
     * does not render it as a navigable anchor (abstract bases and unions).
     * Each entry pins the exact URL the class must reference instead.
     */
    private const array NON_SCHEMA_ANCHOR_SEE_URLS = [
        MetaObject::class => 'https://modelcontextprotocol.io/specification/2025-11-25/basic#_meta',
        RequestMetaObject::class => 'https://modelcontextprotocol.io/specification/2025-11-25/basic#_meta',
        Icons::class => 'https://modelcontextprotocol.io/specification/2025-11-25/basic#icons',
        ProtocolVersion::class => 'https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle#version-negotiation',
        BaseMetadata::class => self::TS_SCHEMA_FILE_URL,
        Notification::class => self::TS_SCHEMA_FILE_URL,
        NotificationParams::class => self::TS_SCHEMA_FILE_URL,
        EmptyNotificationParams::class => self::TS_SCHEMA_FILE_URL,
        PaginatedRequest::class => self::TS_SCHEMA_FILE_URL,
        PaginatedRequestParams::class => self::TS_SCHEMA_FILE_URL,
        PaginatedResult::class => self::TS_SCHEMA_FILE_URL,
        Request::class => self::TS_SCHEMA_FILE_URL,
        RequestParams::class => self::TS_SCHEMA_FILE_URL,
        EmptyRequestParams::class => self::TS_SCHEMA_FILE_URL,
        ResourceContents::class => self::TS_SCHEMA_FILE_URL,
        ResourceRequestParams::class => self::TS_SCHEMA_FILE_URL,
        TaskAugmentedRequestParams::class => self::TS_SCHEMA_FILE_URL,
        TaskSupport::class => self::TS_SCHEMA_FILE_URL,
        ToolChoiceMode::class => self::TS_SCHEMA_FILE_URL,
        IncludeContext::class => self::TS_SCHEMA_FILE_URL,
        GetTaskRequestParams::class => 'https://modelcontextprotocol.io/specification/2025-11-25/schema#gettaskrequest',
        GetTaskPayloadRequestParams::class => 'https://modelcontextprotocol.io/specification/2025-11-25/schema#gettaskpayloadrequest',
        CancelTaskRequestParams::class => 'https://modelcontextprotocol.io/specification/2025-11-25/schema#canceltaskrequest',
        ClientNotification::class => self::TS_SCHEMA_FILE_URL,
        ServerNotification::class => self::TS_SCHEMA_FILE_URL,
        ClientRequest::class => self::TS_SCHEMA_FILE_URL,
        ServerRequest::class => self::TS_SCHEMA_FILE_URL,
        ClientResult::class => self::TS_SCHEMA_FILE_URL,
        ServerResult::class => self::TS_SCHEMA_FILE_URL,
        ProtocolErrorCode::class => self::JSON_RPC_ERROR_OBJECT_URL,
        InternalError::class => self::JSON_RPC_ERROR_OBJECT_URL,
        InvalidParamsError::class => self::JSON_RPC_ERROR_OBJECT_URL,
        InvalidRequestError::class => self::JSON_RPC_ERROR_OBJECT_URL,
        MethodNotFoundError::class => self::JSON_RPC_ERROR_OBJECT_URL,
        ParseError::class => self::JSON_RPC_ERROR_OBJECT_URL,
        UrlElicitationRequiredErrorPayload::class => self::JSON_RPC_ERROR_OBJECT_URL,
        ElicitRequestedSchema::class => self::TS_SCHEMA_FILE_URL,
        EnumOption::class => self::TS_SCHEMA_FILE_URL,
        ElicitationCompleteNotificationParams::class => self::TS_SCHEMA_FILE_URL,
        UrlElicitationRequiredError::class => self::TS_SCHEMA_FILE_URL,
        ElicitAction::class => self::TS_SCHEMA_FILE_URL,
    ];

    /**
     * Schema classes intentionally not annotated with `@see` because they are
     * PHP-only infrastructure with no protocol mapping.
     */
    private const array SEE_ANNOTATION_EXEMPT = [
        Arrayable::class,
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
     * @var null|array<string, list<string>>
     */
    private static ?array $anchorSnapshot = null;

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

            $typedSpecProperties = [];

            foreach ($specProperties as $propertyName => $propertyShape) {
                if (\is_string($propertyName)) {
                    Assert::that($propertyShape)->isMap('Spec property shape must be a string-keyed object.');
                    $typedSpecProperties[$propertyName] = $propertyShape;
                }
            }

            foreach (self::diffPropertiesAgainstSpec($reflection, $specRequiredKeys, $specOptionalKeys, $typedSpecProperties) as $finding) {
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

        // Off-site URLs (TS schema on github.com, JSON-RPC spec on
        // jsonrpc.org) are not in the snapshot and are out of scope.
        if (parse_url($expectedUrl, \PHP_URL_HOST) !== 'modelcontextprotocol.io') {
            return;
        }

        $snapshot = self::loadAnchorSnapshot();

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
            yield new \ReflectionClass($schemaClass)->getShortName() => [$schemaClass, $expectedUrl];
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
                \sprintf('Schema class "%s" is in SEE_ANNOTATION_EXEMPT but declares an @see tag; remove the tag or move the class out of the exemption list.', $schemaClass),
            );
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
            // Transitive license: the implementer is a subclass of a member.
            // Wire-envelope unions like `JSONRPCMessage` list the abstract
            // bases (`JSONRPCRequest`, `JSONRPCNotification`, ...) as members;
            // every concrete request/notification/response inherits the
            // marker through one of those bases and is thereby licensed.
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
                    // Candidate is itself a spec union (e.g. `JSONRPCResponse`
                    // sitting under the broader `JSONRPCMessage`); license
                    // it via its own union's members rather than the parent.
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
     * @param \ReflectionClass<object>            $reflection
     * @param list<string>                        $specRequired
     * @param list<string>                        $specOptional
     * @param array<string, array<string, mixed>> $specProperties
     *
     * @return list<string>
     */
    private static function diffPropertiesAgainstSpec(
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

            $isMixed = $type instanceof \ReflectionNamedType && $type->getName() === 'mixed';

            if ($isRequired) {
                if ($hasDefault) {
                    $findings[] = \sprintf(
                        'spec \'%s\' is required but %s has a default value; remove the default.',
                        $key,
                        $source,
                    );
                }

                if ($allowsNull && ! $isMixed && ! $specAllowsNull) {
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

            if (preg_match('/^([\'"]?)([A-Za-z_][\w\-]*)\1(\??):/', $entry, $m) !== 1) {
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
     * Resolve every spec-level `anyOf` union (direction unions like
     * `ClientRequest`, content unions like `ContentBlock`, wire-envelope
     * unions like `JSONRPCMessage`, etc.) to its explicit member basenames.
     * The bare `{"$ref": "Result"}` entry appearing in `ClientResult` and
     * `ServerResult` is recorded separately as a structural license, not
     * folded into the explicit members list.
     *
     * Unions whose PHP marker class/interface does not yet exist (because the
     * type hasn't been built in this SDK yet) are filtered out so the test
     * stays green until the marker lands.
     *
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
                // Union whose PHP marker hasn't been built yet; skip until it lands.
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
    private static function loadAnchorSnapshot(): array
    {
        if (null !== self::$anchorSnapshot) {
            return self::$anchorSnapshot;
        }

        self::$anchorSnapshot = McpAnchorSnapshot::loadAnchorSnapshot();

        return self::$anchorSnapshot;
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
