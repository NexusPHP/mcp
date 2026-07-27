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

use Nexus\Mcp\Client\Auth\PkcePair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PkcePair::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class PkcePairTest extends TestCase
{
    /**
     * The worked example from RFC 7636 Appendix B.
     */
    private const string RFC_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private const string RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testFromVerifierDerivesTheChallengeOfTheSpecificationExample(): void
    {
        $pair = PkcePair::fromVerifier(self::RFC_VERIFIER);

        self::assertSame(self::RFC_VERIFIER, $pair->verifier);
        self::assertSame(self::RFC_CHALLENGE, $pair->challenge);
    }

    public function testGenerateProducesAVerifierOfTheMinimumLength(): void
    {
        self::assertSame(43, \strlen(PkcePair::generate()->verifier));
    }

    public function testGenerateProducesUrlSafeUnpaddedValues(): void
    {
        $pair = PkcePair::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $pair->verifier);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $pair->challenge);
    }

    public function testGenerateDerivesTheChallengeFromTheVerifierItProduced(): void
    {
        $pair = PkcePair::generate();

        self::assertSame(PkcePair::fromVerifier($pair->verifier)->challenge, $pair->challenge);
    }

    public function testGenerateProducesAFreshVerifierEachTime(): void
    {
        self::assertNotSame(PkcePair::generate()->verifier, PkcePair::generate()->verifier);
    }
}
