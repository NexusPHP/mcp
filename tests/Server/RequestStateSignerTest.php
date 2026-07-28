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

namespace Nexus\Mcp\Tests\Server;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Server\RequestStateSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestStateSigner::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class RequestStateSignerTest extends TestCase
{
    public function testVerifyReturnsThePayloadItSigned(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertSame('round-2|Alice', $signer->verify($signer->sign('round-2|Alice')));
    }

    public function testAPayloadContainingTheSeparatorSurvives(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertSame('a.b.c', $signer->verify($signer->sign('a.b.c')));
    }

    public function testAnEmptyPayloadSurvives(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertSame('', $signer->verify($signer->sign('')));
    }

    public function testSigningIsDeterministicForOneSecret(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertSame($signer->sign('payload'), $signer->sign('payload'));
    }

    #[DataProvider('provideVerifyRejectsATamperedStateCases')]
    public function testVerifyRejectsATamperedState(string $state): void
    {
        self::assertNull(new RequestStateSigner('secret')->verify($state));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideVerifyRejectsATamperedStateCases(): iterable
    {
        $signer = new RequestStateSigner('secret');
        $signed = $signer->sign('round-1');

        yield 'no separator' => ['round-1'];

        yield 'empty' => [''];

        yield 'payload swapped, signature kept' => ['round-9'.substr($signed, \strlen('round-1'))];

        yield 'signature truncated' => [substr($signed, 0, -1)];

        yield 'signature emptied' => ['round-1.'];

        yield 'signed by another secret' => [new RequestStateSigner('other')->sign('round-1')];
    }

    public function testTwoSecretsDoNotVerifyEachOther(): void
    {
        $mine = new RequestStateSigner('mine');

        self::assertNull($mine->verify(new RequestStateSigner('yours')->sign('round-1')));
    }

    public function testAGeneratedSignerVerifiesItsOwnState(): void
    {
        $signer = RequestStateSigner::generate();

        self::assertSame('round-1', $signer->verify($signer->sign('round-1')));
    }

    public function testTwoGeneratedSignersDoNotShareASecret(): void
    {
        $first = RequestStateSigner::generate();

        self::assertNull($first->verify(RequestStateSigner::generate()->sign('round-1')));
    }

    public function testAnAlternativeAlgorithmRoundTrips(): void
    {
        $signer = new RequestStateSigner('secret', 'sha512');

        self::assertSame('round-1', $signer->verify($signer->sign('round-1')));
        self::assertNull(new RequestStateSigner('secret')->verify($signer->sign('round-1')));
    }

    public function testConstructorRejectsAnEmptySecret(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('The request-state signing secret must be a non-empty string.');

        new RequestStateSigner('');
    }

    public function testConstructorRejectsAnUnavailableAlgorithm(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('The request-state signing algorithm "rot13" is not available.');

        new RequestStateSigner('secret', 'rot13');
    }
}
