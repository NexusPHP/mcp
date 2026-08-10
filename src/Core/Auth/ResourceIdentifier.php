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

namespace Nexus\Mcp\Core\Auth;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\SafeDisplay;

/**
 * The canonical URI of an MCP server, as carried by the OAuth `resource` parameter.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8707.html#section-2
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#canonical-server-uri
 */
final readonly class ResourceIdentifier
{
    public string $value;
    public string $origin;

    public function __construct(string $uri)
    {
        $canonical = self::canonicalise($uri);

        Assert::that($canonical)->isArray(\sprintf(
            'The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "%s" given.',
            SafeDisplay::sanitiseCause($uri),
        ));

        [$this->value, $this->origin] = $canonical;
    }

    public function sharesOriginWith(string $uri): bool
    {
        $canonical = self::canonicalise($uri);

        return null !== $canonical && $canonical[1] === $this->origin;
    }

    /**
     * @param list<string> $audience
     */
    public function matchesAudience(array $audience): bool
    {
        foreach ($audience as $value) {
            $canonical = self::canonicalise($value);

            if (null !== $canonical && $canonical[0] === $this->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns `null` when the URI is not a usable resource identifier.
     *
     * @return null|array{string, string} The canonical identifier and its origin
     */
    private static function canonicalise(string $uri): ?array
    {
        $parts = parse_url($uri);

        if (false === $parts || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $origin = \sprintf('%s://%s%s', $scheme, $host, self::renderNonDefaultPort($scheme, $parts['port'] ?? null));

        return [
            $origin.('/' === $path ? '' : $path).(isset($parts['query']) ? '?'.$parts['query'] : ''),
            $origin,
        ];
    }

    private static function renderNonDefaultPort(string $scheme, ?int $port): string
    {
        $default = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return null === $port || $port === $default ? '' : ':'.$port;
    }
}
