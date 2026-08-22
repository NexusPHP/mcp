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
    private string $path;

    public function __construct(string $uri)
    {
        $canonical = $this->canonicalise($uri);

        Assert::that($canonical)->isArray(\sprintf(
            'The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "%s" given.',
            SafeDisplay::sanitiseCause($uri),
        ));

        $this->value = $canonical[0];
        $this->origin = $canonical[1];
        $this->path = $canonical[2];
    }

    public function sharesOriginWith(string $uri): bool
    {
        $canonical = $this->canonicalise($uri);

        return null !== $canonical && $canonical[1] === $this->origin;
    }

    /**
     * Whether a token minted for this resource may be presented to `$uri`: the resource itself or
     * anything under its path. A path a server could normalise out of the subtree (dot segments,
     * percent-encoded dots, slashes, or backslashes) is never covered.
     */
    public function covers(string $uri): bool
    {
        $canonical = $this->canonicalise($uri);

        if (null === $canonical || $canonical[1] !== $this->origin) {
            return false;
        }

        if (preg_match('~(?:^|/)\.{1,2}(?:/|\z)|%2e|%2f|%5c|\\\\~i', $canonical[2]) === 1) {
            return false;
        }

        $base = rtrim($this->path, '/');

        return $canonical[2] === $base || str_starts_with($canonical[2], $base.'/');
    }

    /**
     * @param list<string> $audience
     */
    public function matchesAudience(array $audience): bool
    {
        foreach ($audience as $value) {
            $canonical = $this->canonicalise($value);

            if (null !== $canonical && $canonical[0] === $this->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical identifier, its origin, and its path, or `null` when the URI is not a usable
     * resource identifier.
     *
     * @return null|array{string, string, string}
     */
    private function canonicalise(string $uri): ?array
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
        $origin = \sprintf('%s://%s%s', $scheme, $host, $this->renderNonDefaultPort($scheme, $parts['port'] ?? null));

        $path = '/' === $path ? '' : $path;

        return [
            $origin.$path.(isset($parts['query']) ? '?'.$parts['query'] : ''),
            $origin,
            $path,
        ];
    }

    private function renderNonDefaultPort(string $scheme, ?int $port): string
    {
        $default = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return null === $port || $port === $default ? '' : ':'.$port;
    }
}
