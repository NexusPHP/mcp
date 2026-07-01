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

namespace Nexus\Mcp\Core\Http;

/**
 * Scans a tool `inputSchema` for `x-mcp-header` bindings and enforces the constraints on them.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http#custom-headers-from-tool-parameters
 */
final class ParameterHeaderScanner
{
    private const string X_MCP_HEADER_KEY = 'x-mcp-header';

    /**
     * RFC 9110 section 5.1 token syntax (`1*tchar`).
     */
    private const string TOKEN_PATTERN = '/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/';

    /**
     * JSON Schema `type` values permitted on an `x-mcp-header` property. The spec excludes `number`.
     */
    private const array PERMITTED_TYPES = ['string', 'integer', 'boolean'];

    /**
     * JSON Schema keywords whose subschemas fall outside the `properties`-only reachability chain.
     */
    private const array NON_REACHABLE_KEYWORDS = [
        'items', 'prefixItems', 'contains', 'additionalProperties', 'unevaluatedProperties',
        'unevaluatedItems', 'propertyNames', 'patternProperties', 'dependentSchemas',
        'oneOf', 'anyOf', 'allOf', 'not', 'if', 'then', 'else', '$defs', 'definitions',
    ];

    /**
     * Non-reachable keywords whose value is a `name => subschema` map rather than a single subschema or
     * a list of subschemas.
     */
    private const array OBJECT_VALUED_KEYWORDS = ['patternProperties', 'dependentSchemas', '$defs', 'definitions'];

    /**
     * @param array<string, mixed> $inputSchema
     */
    public static function scan(array $inputSchema): ParameterHeaderScanResult
    {
        $bindings = [];
        $seen = [];
        $fault = self::visit($inputSchema, [], true, $bindings, $seen);

        return null === $fault
            ? ParameterHeaderScanResult::valid($bindings)
            : ParameterHeaderScanResult::invalid($fault);
    }

    /**
     * @param list<string>                 $path
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, string>        $seen
     */
    private static function visit(mixed $node, array $path, bool $reachable, array &$bindings, array &$seen): ?string
    {
        if (! \is_array($node)) {
            return null;
        }

        if (\array_key_exists(self::X_MCP_HEADER_KEY, $node)) {
            $fault = self::inspectBinding($node, $path, $reachable, $bindings, $seen);

            if (null !== $fault) {
                return $fault;
            }
        }

        $properties = $node['properties'] ?? null;

        if (\is_array($properties)) {
            foreach ($properties as $key => $child) {
                $fault = self::visit($child, [...$path, (string) $key], $reachable, $bindings, $seen);

                if (null !== $fault) {
                    return $fault;
                }
            }
        }

        foreach (self::NON_REACHABLE_KEYWORDS as $keyword) {
            if (! \array_key_exists($keyword, $node)) {
                continue;
            }

            foreach (self::resolveBranches($keyword, $node[$keyword]) as $branch) {
                $fault = self::visit($branch, [...$path, \sprintf('<%s>', $keyword)], false, $bindings, $seen);

                if (null !== $fault) {
                    return $fault;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed>      $node
     * @param list<string>                 $path
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, string>        $seen
     */
    private static function inspectBinding(array $node, array $path, bool $reachable, array &$bindings, array &$seen): ?string
    {
        $location = self::formatPath($path);

        if (! $reachable || [] === $path) {
            return \sprintf('%s: x-mcp-header is only permitted on a property reachable through a chain of "properties" keys.', $location);
        }

        $raw = $node[self::X_MCP_HEADER_KEY] ?? null;

        if (! \is_string($raw) || '' === $raw) {
            return \sprintf('%s: x-mcp-header must be a non-empty string.', $location);
        }

        if (preg_match(self::TOKEN_PATTERN, $raw) !== 1) {
            return \sprintf('%s: x-mcp-header "%s" is not a valid RFC 9110 token.', $location, $raw);
        }

        $type = $node['type'] ?? null;

        if (! \is_string($type) || ! \in_array($type, self::PERMITTED_TYPES, true)) {
            return \sprintf('%s: x-mcp-header is only permitted on a string, integer, or boolean property.', $location);
        }

        $lower = strtolower($raw);

        if (\array_key_exists($lower, $seen)) {
            return \sprintf('x-mcp-header "%s" is not case-insensitively unique (also declared as "%s").', $raw, $seen[$lower]);
        }

        $seen[$lower] = $raw;
        $bindings[] = new ParameterHeaderBinding($path, $raw, $type);

        return null;
    }

    /**
     * @return list<mixed>
     */
    private static function resolveBranches(string $keyword, mixed $value): array
    {
        if (! \is_array($value)) {
            // A scalar keyword value (e.g. `additionalProperties: false`) carries no subschema to visit.
            return [];
        }

        if (array_is_list($value)) {
            return $value;
        }

        if (\in_array($keyword, self::OBJECT_VALUED_KEYWORDS, true)) {
            return array_values($value);
        }

        return [$value];
    }

    /**
     * @param list<string> $path
     */
    private static function formatPath(array $path): string
    {
        return [] === $path ? '<root>' : implode('.', $path);
    }
}
