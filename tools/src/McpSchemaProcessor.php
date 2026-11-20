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

namespace Nexus\Mcp\Tools;

/**
 * @internal
 */
final class McpSchemaProcessor
{
    public const string LATEST_SCHEMA_JSON_URL = 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/2026-07-28/schema.json';
    public const string LATEST_SCHEMA_JSON_PATH = __DIR__.'/../../schema/2026-07-28/schema.json';
    public const string SORTED_SCHEMA_JSON_PATH = __DIR__.'/../../sorted-schema.json';
    public const string LATEST_SCHEMA_TS_URL = 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/2026-07-28/schema.ts';
    public const string LATEST_SCHEMA_TS_PATH = __DIR__.'/../../schema/2026-07-28/schema.ts';

    /**
     * Directories holding each extension's `schema.json` and `schema.ts`, keyed by its directory under `src/Extension/`.
     */
    public const array EXTENSION_SCHEMAS = [
        'Skills' => [
            'url' => 'https://raw.githubusercontent.com/modelcontextprotocol/ext-skills/main/schema/beta',
            'path' => __DIR__.'/../../schema/ext-skills/beta',
        ],
    ];

    private const array INLINED_SPEC_DEFS = [
        'JSONValue',
        'JSONObject',
        'JSONArray',
        'InputRequests',
        'InputResponses',
    ];

    public static function fetchAndSaveLatestSchemaTs(): void
    {
        $sources = [self::LATEST_SCHEMA_TS_URL => self::LATEST_SCHEMA_TS_PATH];

        foreach (self::EXTENSION_SCHEMAS as ['url' => $url, 'path' => $path]) {
            $sources[$url.'/schema.ts'] = $path.'/schema.ts';
        }

        foreach ($sources as $url => $path) {
            $schemaTs = file_get_contents($url);

            if (false === $schemaTs) {
                throw new \RuntimeException(\sprintf('Failed to fetch the schema.ts from %s', $url));
            }

            file_put_contents($path, $schemaTs);
        }
    }

    /**
     * Core defs plus the extensions' own, each schema fetched when `MCP_FETCH_LATEST_SCHEMA` is truthy or uncached.
     *
     * @return array<string, mixed>
     */
    public static function fetchAndSaveLatestSchema(): array
    {
        $defs = self::fetchAndSaveSchemaDefs(self::LATEST_SCHEMA_JSON_URL, self::LATEST_SCHEMA_JSON_PATH);

        foreach (self::EXTENSION_SCHEMAS as ['url' => $url, 'path' => $path]) {
            $defs += self::fetchAndSaveSchemaDefs($url.'/schema.json', $path.'/schema.json');
        }

        return self::resolveRefAliases($defs);
    }

    /**
     * @param array<string, mixed> $schemaDefs
     *
     * @return array{
     *   processed_schema: array<string, class-string>,
     *   internal_schema: array<string, class-string>,
     *   deprecated_schema: list<string>,
     *   inlined_schema: list<string>,
     *   unprocessed_schema: list<string>
     * }
     */
    public static function sortAndSaveSchema(array $schemaDefs): array
    {
        $processedSchema = [];
        $internalSchema = [];

        $iterator = new \AppendIterator();

        foreach (['Core', ...array_map(static fn(string $name): string => 'Extension/'.$name, array_keys(self::EXTENSION_SCHEMAS))] as $package) {
            $iterator->append(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    __DIR__.'/../../src/'.$package.'/Schema/',
                    \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::UNIX_PATHS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ));
        }

        $rootSrc = realpath(__DIR__.'/../../src/');
        \assert(false !== $rootSrc, 'Could not determine root src directory path.');

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

            if (! class_exists($schemaClass) && ! interface_exists($schemaClass) && ! enum_exists($schemaClass)) {
                continue;
            }

            $specKey = self::resolveSpecKey($basename, $schemaDefs);

            if (null !== $specKey) {
                $processedSchema[$specKey] = $schemaClass;
            } else {
                $internalSchema[$basename] = $schemaClass;
            }
        }

        ksort($processedSchema);
        ksort($internalSchema);

        $missingFromSpec = array_diff(array_keys($schemaDefs), array_keys($processedSchema));
        $deprecatedDefs = self::listDeprecatedSpecDefNames();

        $deprecatedSchema = array_intersect($missingFromSpec, $deprecatedDefs);
        $inlinedSchema = array_intersect($missingFromSpec, self::INLINED_SPEC_DEFS);
        $unprocessedSchema = array_diff($missingFromSpec, $deprecatedDefs, self::INLINED_SPEC_DEFS);
        sort($deprecatedSchema);
        sort($inlinedSchema);
        sort($unprocessedSchema);

        $sortedSchema = [
            'processed_schema' => $processedSchema,
            'internal_schema' => $internalSchema,
            'deprecated_schema' => $deprecatedSchema,
            'inlined_schema' => $inlinedSchema,
            'unprocessed_schema' => $unprocessedSchema,
        ];

        if (filter_var(getenv('MCP_FETCH_LATEST_SCHEMA'), \FILTER_VALIDATE_BOOL)) {
            $data = [
                'created_at' => date('l, d F Y H:i:sP'),
                'sorted_schema' => $sortedSchema,
            ];
            file_put_contents(
                self::SORTED_SCHEMA_JSON_PATH,
                json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            );
        }

        return $sortedSchema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchAndSaveSchemaDefs(string $url, string $path): array
    {
        $forceFetch = filter_var(getenv('MCP_FETCH_LATEST_SCHEMA'), \FILTER_VALIDATE_BOOL);
        $schemaJson = $forceFetch ? false : @file_get_contents($path);

        if (false === $schemaJson) {
            $schemaJson = file_get_contents($url);

            if (false === $schemaJson) {
                throw new \RuntimeException(\sprintf('Failed to fetch the schema from %s', $url));
            }

            file_put_contents($path, $schemaJson);
        }

        $decodedSchema = json_decode($schemaJson, true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decodedSchema)) {
            throw new \RuntimeException(\sprintf('The decoded schema from %s is not a valid array.', $url));
        }

        if (! \array_key_exists('$defs', $decodedSchema) || ! \is_array($decodedSchema['$defs'])) {
            throw new \RuntimeException(\sprintf('The schema from %s does not contain valid $defs.', $url));
        }

        /** @var array<string, mixed> $schemaDefs */
        $schemaDefs = $decodedSchema['$defs'];

        return self::resolveLinks($schemaDefs);
    }

    /**
     * Read from the TypeScript schema, since the generated JSON drops the `@deprecated` tag.
     *
     * @return list<string>
     */
    private static function listDeprecatedSpecDefNames(): array
    {
        $ts = @file_get_contents(self::LATEST_SCHEMA_TS_PATH);

        if (false === $ts) {
            return [];
        }

        $matched = preg_match_all(
            '#/\*\*(?:(?!\*/).)*?@deprecated(?:(?!\*/).)*?\*/\s*export\s+(?:interface|type)\s+(\w+)#s',
            $ts,
            $matches,
        );

        return 0 === $matched || false === $matched ? [] : $matches[1];
    }

    /**
     * @param array<string, mixed> $schemaDefs
     *
     * @return null|non-empty-string
     */
    private static function resolveSpecKey(string $basename, array $schemaDefs): ?string
    {
        if (\array_key_exists($basename, $schemaDefs)) {
            return '' === $basename ? null : $basename;
        }

        $candidate = strtr($basename, ['JsonRpc' => 'JSONRPC', 'Url' => 'URL']);

        if ('' !== $candidate && $candidate !== $basename && \array_key_exists($candidate, $schemaDefs)) {
            return $candidate;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $defs
     *
     * @return array<string, mixed>
     */
    private static function resolveRefAliases(array $defs): array
    {
        $prefix = '#/$defs/';

        foreach ($defs as $key => $def) {
            if (! \is_array($def) || ! isset($def['$ref']) || \count($def) !== 1) {
                continue;
            }

            $ref = $def['$ref'];

            if (! \is_string($ref) || ! str_starts_with($ref, $prefix)) {
                continue;
            }

            $target = substr($ref, \strlen($prefix));

            if (\array_key_exists($target, $defs) && \is_array($defs[$target])) {
                $defs[$key] = $defs[$target];
            }
        }

        return $defs;
    }

    /**
     * @param array<string, mixed> $defs
     *
     * @return array<string, mixed>
     */
    private static function resolveLinks(array $defs): array
    {
        foreach ($defs as $key => $def) {
            if (! \is_array($def) || ! isset($def['description']) || ! \is_string($def['description'])) {
                continue;
            }

            $def['description'] = preg_replace_callback(
                '/\{@link\s+([^\}]+)\}/',
                static function (array $matches) use ($defs) {
                    $targetKey = $matches[1];

                    if (\array_key_exists($targetKey, $defs)) {
                        return \sprintf('`%s`', $targetKey);
                    }

                    if (str_contains($targetKey, '/')) {
                        $chunk = explode('/', $targetKey)[0];

                        while ('' !== $chunk) {
                            if (\array_key_exists($chunk, $defs)) {
                                return \sprintf('`%s`', substr($targetKey, \strlen($chunk)));
                            }

                            $chunk = substr($chunk, 0, -1);
                        }
                    }

                    return $matches[0];
                },
                $def['description'],
            );
            $defs[$key] = $def;
        }

        return $defs;
    }
}
