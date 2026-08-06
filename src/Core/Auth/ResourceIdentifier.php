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

/**
 * The canonical URI of an MCP server, the value carried by the OAuth `resource` parameter and the audience
 * a token issued for that server must name.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8707.html#section-2
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#canonical-server-uri
 */
final readonly class ResourceIdentifier
{
    public string $value;

    /**
     * The scheme, host and non-default port of the MCP server, which is what a token and a metadata document
     * are bound to.
     */
    public string $origin;

    public function __construct(string $uri)
    {
        $canonical = self::canonicalise($uri);

        Assert::that($canonical)->isArray(\sprintf(
            'The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "%s" given.',
            $uri,
        ));

        [$this->value, $this->origin] = $canonical;
    }

    /**
     * Reports whether a URL is served from the same origin as this MCP server, which is what binds a token
     * and a metadata document to it.
     */
    public function sharesOriginWith(string $uri): bool
    {
        $canonical = self::canonicalise($uri);

        return null !== $canonical && $canonical[1] === $this->origin;
    }

    /**
     * Reports whether any of a token's audience values names this resource.
     *
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
     * Lowercases the scheme and host and drops a bare root path, or returns `null` when the URI is not a
     * usable resource identifier.
     *
     * @return null|array{string, string} The canonical identifier and its origin
     */
    private static function canonicalise(string $uri): ?array
    {
        $parts = parse_url($uri);

        if (false === $parts || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return null;
        }

        // Userinfo would be dropped by the rebuild below, collapsing two RFC 3986-distinct URIs onto one
        // identifier and letting a token minted for "evil@good" pass as one minted for "good".
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $origin = \sprintf('%s://%s%s', $scheme, $host, self::renderPort($scheme, $parts['port'] ?? null));

        return [
            $origin.('/' === $path ? '' : $path).(isset($parts['query']) ? '?'.$parts['query'] : ''),
            $origin,
        ];
    }

    /**
     * Renders the port, eliding the scheme's default so an authority that spells it out still matches one
     * that leaves it implicit.
     */
    private static function renderPort(string $scheme, ?int $port): string
    {
        $default = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return null === $port || $port === $default ? '' : ':'.$port;
    }
}
