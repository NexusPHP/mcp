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
 * Snapshots the heading anchor IDs rendered on each MCP spec docs page so the
 * auto-review test can verify that every `@see` URL in schema classes points
 * at an anchor that actually resolves on the live site.
 *
 * @internal
 */
final class McpAnchorSnapshot
{
    public const string SPEC_BASE_URL = 'https://modelcontextprotocol.io/specification/2025-11-25';
    public const string SNAPSHOT_PATH = __DIR__.'/../../tests/AutoReview/data/spec-anchors.json';

    /**
     * Spec docs pages whose anchors `@see` URLs may target. Add a page here
     * when extending `@see` references to a new spec area; the test reads the
     * snapshot keyed by the full URL of each page.
     */
    private const array SPEC_PAGES = [
        'schema',
        'basic',
        'basic/lifecycle',
        'basic/utilities/cancellation',
        'basic/utilities/progress',
        'basic/utilities/ping',
        'server/resources',
        'server/prompts',
        'server/tools',
        'server/utilities/logging',
        'server/utilities/completion',
        'client/roots',
        'client/sampling',
        'client/elicitation',
    ];

    /**
     * Concept-page anchors that aren't backed by a top-level `$defs` key but
     * are still referenced from `@see` URLs. Keys are page-relative paths
     * (matching {@see self::SPEC_PAGES} entries); values are anchor IDs to
     * keep when intersecting against the schema's `$defs`.
     */
    private const array EXTRA_VALID_ANCHORS = [
        'basic' => ['_meta', 'icons'],
        'basic/lifecycle' => ['version-negotiation'],
    ];

    /**
     * Fetches every page in {@see self::SPEC_PAGES}, intersects each page's
     * rendered heading anchors with the lowercased `$defs` keys from the
     * upstream schema (plus the {@see self::EXTRA_VALID_ANCHORS} carve-outs),
     * and writes the snapshot to disk. Sub-property anchors and page chrome
     * IDs are filtered out implicitly because they don't appear in `$defs`.
     *
     * @return array<string, list<string>>
     */
    public static function fetchAndSaveAnchorSnapshot(): array
    {
        $schemaDefs = McpSchemaProcessor::fetchAndSaveLatestSchema();
        $defAnchors = array_map(strtolower(...), array_keys($schemaDefs));

        $snapshot = [];

        foreach (self::SPEC_PAGES as $relPath) {
            $url = self::SPEC_BASE_URL.'/'.$relPath;
            $html = file_get_contents($url);

            if (false === $html) {
                throw new \RuntimeException(\sprintf('Failed to fetch spec docs page at %s', $url));
            }

            $allowed = array_values(array_unique([...$defAnchors, ...(self::EXTRA_VALID_ANCHORS[$relPath] ?? [])]));

            $anchors = self::extractAnchors($html, $allowed);

            if ([] === $anchors) {
                continue;
            }

            sort($anchors);
            $snapshot[$url] = $anchors;
        }

        ksort($snapshot);

        $payload = [
            'created_at' => date('l, d F Y H:i:sP'),
            'anchors' => $snapshot,
        ];

        file_put_contents(
            self::SNAPSHOT_PATH,
            json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n",
        );

        return $snapshot;
    }

    /**
     * Reads the saved snapshot from disk, returning the page-URL → anchor-IDs
     * map. Throws when the snapshot file is missing or malformed; refresh via
     * `composer spec:snapshot-anchors`.
     *
     * @return array<string, list<string>>
     */
    public static function loadAnchorSnapshot(): array
    {
        $contents = file_get_contents(self::SNAPSHOT_PATH);

        if (false === $contents) {
            throw new \RuntimeException(\sprintf(
                'Spec anchor snapshot is missing at %s. Run `composer spec:snapshot-anchors` to generate it.',
                self::SNAPSHOT_PATH,
            ));
        }

        $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded) || ! \is_array($decoded['anchors'] ?? null)) {
            throw new \RuntimeException(\sprintf('Spec anchor snapshot at %s is malformed.', self::SNAPSHOT_PATH));
        }

        /** @var array<string, list<string>> $anchors */
        $anchors = $decoded['anchors'];

        return $anchors;
    }

    /**
     * Extracts every `id="..."` attribute from the HTML and returns the
     * subset that's in `$allowed` (lowercased `$defs` keys plus the
     * concept-page extras). Page chrome, sub-property anchors, and
     * framework-internal IDs fall away because they don't appear in `$allowed`.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function extractAnchors(string $html, array $allowed): array
    {
        if (preg_match_all('/\bid="([^"]+)"/i', $html, $matches) === false) {
            return [];
        }

        $allowedSet = array_flip($allowed);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn(string $id): bool => isset($allowedSet[$id]),
        )));
    }
}
