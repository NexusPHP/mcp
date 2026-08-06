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

namespace Nexus\Mcp\Tests\Client\Auth;

use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AuthorizationCallback::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationCallbackTest extends AbstractMcpTestCase
{
    /**
     * @param array<array-key, string> $expected
     */
    #[DataProvider('provideParametersCases')]
    public function testParameters(string $callbackUrl, array $expected): void
    {
        self::assertSame($expected, (new AuthorizationCallback($callbackUrl))->parameters);
    }

    /**
     * @return iterable<string, array{string, array<array-key, string>}>
     */
    public static function provideParametersCases(): iterable
    {
        yield 'a code callback' => [
            'http://localhost:3000/callback?code=abc123&state=xyz',
            ['code' => 'abc123', 'state' => 'xyz'],
        ];

        yield 'a percent-encoded issuer is decoded' => [
            'http://localhost:3000/callback?code=abc123&state=xyz&iss=https%3A%2F%2Fauth.example.com',
            ['code' => 'abc123', 'state' => 'xyz', 'iss' => 'https://auth.example.com'],
        ];

        yield 'a plus is decoded as a space' => [
            'http://localhost:3000/callback?error_description=Consent+was+refused',
            ['error_description' => 'Consent was refused'],
        ];

        yield 'an error callback' => [
            'http://localhost:3000/callback?error=access_denied&state=xyz',
            ['error' => 'access_denied', 'state' => 'xyz'],
        ];

        yield 'a callback with no query carries no parameters' => ['http://localhost:3000/callback', []];

        yield 'an empty query carries no parameters' => ['http://localhost:3000/callback?', []];

        yield 'a fragment is not a query' => ['http://localhost:3000/callback#code=abc123', []];

        yield 'a bare query string is read' => ['?code=abc123', ['code' => 'abc123']];

        yield 'a repeated parameter keeps the last value' => [
            'http://localhost:3000/callback?code=first&code=second',
            ['code' => 'second'],
        ];

        yield 'a nested parameter is not a string and is dropped' => [
            'http://localhost:3000/callback?code=abc123&extra[]=a',
            ['code' => 'abc123'],
        ];
    }
}
