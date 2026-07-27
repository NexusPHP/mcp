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
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc8707.html#section-2
 * @see https://modelcontextprotocol.io/specification/draft/basic/authorization#canonical-server-uri
 */
final readonly class ResourceIdentifier
{
    public string $value;

    public function __construct(string $uri)
    {
        $canonical = self::canonicalise($uri);

        Assert::that($canonical)->not()->isNull(\sprintf(
            'The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "%s" given.',
            $uri,
        ));

        $this->value = $canonical;
    }

    /**
     * Reports whether any of a token's audience values names this resource.
     *
     * @param list<string> $audience
     */
    public function matchesAudience(array $audience): bool
    {
        foreach ($audience as $value) {
            if (self::canonicalise($value) === $this->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercases the scheme and host and drops a bare root path, or returns `null` when the URI is not a
     * usable resource identifier.
     */
    private static function canonicalise(string $uri): ?string
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
        $path = $parts['path'] ?? '';

        return \sprintf(
            '%s://%s%s%s%s',
            $scheme,
            strtolower($parts['host']),
            self::renderPort($scheme, $parts['port'] ?? null),
            '/' === $path ? '' : $path,
            isset($parts['query']) ? '?'.$parts['query'] : '',
        );
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
