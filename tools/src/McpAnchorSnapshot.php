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
 * Snapshot of the heading anchor IDs rendered on each MCP spec docs page.
 *
 * @internal
 */
final class McpAnchorSnapshot
{
    public const string SPEC_BASE_URL = 'https://modelcontextprotocol.io/specification/2026-07-28';
    public const string SNAPSHOT_PATH = __DIR__.'/../../tests/AutoReview/data/spec-anchors.json';
    private const array SPEC_PAGES = [
        'schema',
        'basic',
        'basic/versioning',
        'server/tools',
    ];

    /**
     * Anchor IDs backed by no top-level `$defs` key, keyed by page-relative path.
     */
    private const array EXTRA_VALID_ANCHORS = [
        'basic' => ['_meta', 'icons'],
        'basic/versioning' => ['protocol-version-negotiation'],
        'server/tools' => ['tool-names'],
    ];

    /**
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

        file_put_contents(
            self::SNAPSHOT_PATH,
            json_encode(['anchors' => $snapshot], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n",
        );

        return $snapshot;
    }

    /**
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
