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

namespace Nexus\Mcp\Core\Validation;

use Nexus\Assert\Assert;

/**
 * Validator for the RFC 3986 `URI` grammar with a required scheme, IP literals included.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986
 */
final class Rfc3986UriValidator
{
    private const string PCT_ENCODED = '%[0-9A-Fa-f]{2}';
    private const string UNRESERVED_OR_SUB_DELIM = 'A-Za-z0-9\-._~!$&\'()*+,;=';
    private const string PCHAR = '(?:['.self::UNRESERVED_OR_SUB_DELIM.':@]|'.self::PCT_ENCODED.')';
    private const string ABSOLUTE_URI = '{\A[A-Za-z][A-Za-z0-9+.\-]*:'
        .'(?://(?:(?:['.self::UNRESERVED_OR_SUB_DELIM.':]|'.self::PCT_ENCODED.')*@)?'
        .'(?:\[(?<literal>[^\]]*)\]|(?:['.self::UNRESERVED_OR_SUB_DELIM.']|'.self::PCT_ENCODED.')*)'
        .'(?::[0-9]*)?(?:/'.self::PCHAR.'*)*'
        .'|/(?:'.self::PCHAR.'+(?:/'.self::PCHAR.'*)*)?'
        .'|'.self::PCHAR.'+(?:/'.self::PCHAR.'*)*)?'
        .'(?:\?(?:'.self::PCHAR.'|[/?])*)?'
        .'(?:\#(?:'.self::PCHAR.'|[/?])*)?\z}';
    private const string IP_FUTURE = '{\A[vV][0-9A-Fa-f]+\.['.self::UNRESERVED_OR_SUB_DELIM.':]+\z}';

    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "Resource", "ResourceContents")
     *
     * @throws \InvalidArgumentException
     *
     * @phpstan-assert non-empty-string $uri
     */
    public static function validate(string $uri, string $context): void
    {
        Assert::that($uri)
            ->isNonEmptyString(\sprintf('%s must be a non-empty string.', $context))
            ->matchesRegularExpression(
                '/\A[\x21-\x7E]+\z/',
                \sprintf(
                    '%s must be ASCII printable without whitespace or control characters, {value} given.',
                    $context,
                ),
            )
        ;

        if (preg_match(self::ABSOLUTE_URI, $uri, $matches, \PREG_UNMATCHED_AS_NULL) !== 1) {
            self::refuse($uri, $context);
        }

        $literal = $matches['literal'];

        if (null !== $literal && ! self::isIpv6Address($literal) && preg_match(self::IP_FUTURE, $literal) !== 1) {
            self::refuse($uri, $context);
        }
    }

    /**
     * RFC 3986 §3.2.2: an IPv6 literal carries no zone identifier, which `inet_pton()` would otherwise accept.
     */
    private static function isIpv6Address(string $literal): bool
    {
        $address = inet_pton($literal);

        return false !== $address
            && \strlen($address) === 16
            && preg_match('/\A[0-9A-Fa-f:.]+\z/', $literal) === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function refuse(string $uri, string $context): never
    {
        throw new \InvalidArgumentException(\sprintf(
            '%s must be a valid RFC 3986 absolute URI, %s given.',
            $context,
            var_export($uri, true),
        ));
    }
}
