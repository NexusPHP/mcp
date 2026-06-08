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
    public const string LATEST_SCHEMA_JSON_URL = 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/draft/schema.json';
    public const string LATEST_SCHEMA_JSON_PATH = __DIR__.'/../../latest-schema.json';
    public const string SORTED_SCHEMA_JSON_PATH = __DIR__.'/../../sorted-schema.json';
    public const string LATEST_SCHEMA_TS_URL = 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/draft/schema.ts';
    public const string LATEST_SCHEMA_TS_PATH = __DIR__.'/../../latest-schema.ts';

    /**
     * Fetches the upstream `schema.ts` source and saves it locally as a
     * developer reference for inheritance and type aliases that the JSON schema
     * does not preserve. The file is git-ignored.
     */
    public static function fetchAndSaveLatestSchemaTs(): void
    {
        $schemaTs = file_get_contents(self::LATEST_SCHEMA_TS_URL);

        if (false === $schemaTs) {
            throw new \RuntimeException(\sprintf('Failed to fetch the latest schema.ts from %s', self::LATEST_SCHEMA_TS_URL));
        }

        file_put_contents(self::LATEST_SCHEMA_TS_PATH, $schemaTs);
    }

    /**
     * Loads the latest schema. Resolution priority:
     *
     * - `MCP_FETCH_LATEST_SCHEMA` is truthy: fetch from the remote URL and overwrite the local cache.
     * - Local cache `latest-schema.json` exists: read from disk.
     * - Otherwise: fetch from the remote URL and write the local cache (fresh-clone bootstrap).
     *
     * @return array<string, mixed>
     */
    public static function fetchAndSaveLatestSchema(): array
    {
        $forceFetch = filter_var(getenv('MCP_FETCH_LATEST_SCHEMA'), \FILTER_VALIDATE_BOOL);
        $schemaJson = $forceFetch ? false : @file_get_contents(self::LATEST_SCHEMA_JSON_PATH);
        $fetched = false === $schemaJson;

        if ($fetched) {
            $schemaJson = file_get_contents(self::LATEST_SCHEMA_JSON_URL);

            if (false === $schemaJson) {
                throw new \RuntimeException(\sprintf('Failed to fetch the latest schema from %s', self::LATEST_SCHEMA_JSON_URL));
            }

            file_put_contents(self::LATEST_SCHEMA_JSON_PATH, $schemaJson);
        }

        /** @var array{'$schema': string, '$defs': array<string, mixed>} $decodedSchema */
        $decodedSchema = json_decode($schemaJson, true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decodedSchema)) {
            throw new \RuntimeException('The decoded schema is not a valid array.');
        }

        if (! \array_key_exists('$defs', $decodedSchema) || ! \is_array($decodedSchema['$defs'])) {
            throw new \RuntimeException('The latest schema does not contain valid $defs.');
        }

        $decodedSchema['$defs'] = self::resolveLinks($decodedSchema['$defs']);

        if ($fetched) {
            $schemaJson = json_encode(self::convertEmptyArraysToObjects($decodedSchema), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            file_put_contents(self::LATEST_SCHEMA_JSON_PATH, $schemaJson);
        }

        return self::resolveRefAliases($decodedSchema['$defs']);
    }

    /**
     * @param array<string, mixed> $schemaDefs
     *
     * @return array{
     *   processed_schema: array<string, class-string>,
     *   internal_schema: array<string, class-string>,
     *   unprocessed_schema: list<string>
     * }
     */
    public static function sortAndSaveSchema(array $schemaDefs): array
    {
        $processedSchema = [];
        $internalSchema = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                __DIR__.'/../../src/Core/Schema/',
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

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

        $unprocessedSchema = array_diff(array_keys($schemaDefs), array_keys($processedSchema));
        sort($unprocessedSchema);

        $sortedSchema = [
            'processed_schema' => $processedSchema,
            'internal_schema' => $internalSchema,
            'unprocessed_schema' => $unprocessedSchema,
        ];

        if (filter_var(getenv('MCP_FETCH_LATEST_SCHEMA'), \FILTER_VALIDATE_BOOL)) {
            $data = [
                'created_at' => date('l, d F Y H:i:sP'),
                'sorted_schema' => $sortedSchema,
            ];
            file_put_contents(self::SORTED_SCHEMA_JSON_PATH, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        }

        return $sortedSchema;
    }

    /**
     * Resolves a class basename to the matching top-level spec key, accounting
     * for naming conventions that differ between PHP (camelCase `JsonRpc`,
     * `Url`) and the MCP spec (uppercase-acronym `JSONRPC`, `URL`). Each
     * acronym fragment is rewritten wherever it appears in the basename, not
     * just at the start, so e.g. `ElicitRequestUrlParams` maps to spec
     * `ElicitRequestURLParams`.
     *
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
     * Inlines `$ref` aliases that point to other top-level `$defs` entries, so
     * aliased schemas (e.g. `EmptyResult = { "$ref": "#/$defs/Result" }`) expose
     * the same fields as the underlying definition for conformance testing.
     *
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

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>|\stdClass
     */
    private static function convertEmptyArraysToObjects(array $value): array|\stdClass
    {
        if ([] === $value) {
            return new \stdClass();
        }

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::convertEmptyArraysToObjects($item);
            }
        }

        return $value;
    }
}
