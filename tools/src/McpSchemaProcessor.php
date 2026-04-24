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
    public const string LATEST_SCHEMA_URL = 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/2025-11-25/schema.json';
    public const string LATEST_SCHEMA_PATH = __DIR__.'/../../latest-schema.json';
    public const string SORTED_SCHEMA_PATH = __DIR__.'/../../sorted-schema.json';

    /**
     * Fetches the latest schema from the remote URL and saves it locally.
     *
     * @return array<string, mixed>
     */
    public static function fetchAndSaveLatestSchema(): array
    {
        $schemaJson = file_get_contents(self::LATEST_SCHEMA_URL);

        if (false === $schemaJson) {
            throw new \RuntimeException(\sprintf('Failed to fetch the latest schema from %s', self::LATEST_SCHEMA_URL));
        }

        if (filter_var(getenv('MCP_FETCH_LATEST_SCHEMA'), \FILTER_VALIDATE_BOOL)) {
            file_put_contents(self::LATEST_SCHEMA_PATH, $schemaJson);
        }

        $decodedSchema = json_decode($schemaJson, true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decodedSchema)) {
            throw new \RuntimeException('The decoded schema is not a valid array.');
        }

        unset($decodedSchema['$schema']);

        if (! \array_key_exists('$defs', $decodedSchema) || ! \is_array($decodedSchema['$defs'])) {
            throw new \RuntimeException('The latest schema does not contain valid $defs.');
        }

        /** @var array<string, mixed> $defs */
        $defs = $decodedSchema['$defs'];

        return self::resolveRefAliases($defs);
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
        $unprocessedSchema = [];

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
            file_put_contents(self::SORTED_SCHEMA_PATH, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        }

        return $sortedSchema;
    }

    /**
     * Resolves a class basename to the matching top-level spec key, accounting
     * for naming conventions that differ between PHP (camelCase `JsonRpc*`) and
     * the MCP spec (uppercase-acronym `JSONRPC*`).
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

        if (str_starts_with($basename, 'JsonRpc')) {
            $candidate = 'JSONRPC'.substr($basename, \strlen('JsonRpc'));

            if (\array_key_exists($candidate, $schemaDefs)) {
                return $candidate;
            }
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
}
