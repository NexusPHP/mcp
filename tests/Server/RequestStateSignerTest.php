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

use Nexus\Mcp\Server\RequestStateSigner;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(RequestStateSigner::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class RequestStateSignerTest extends AbstractMcpTestCase
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

    public function testVerifyReturnsThePayloadWhenTheBindingMatches(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertSame('round-2', $signer->verify($signer->sign('round-2', 'user-alice'), 'user-alice'));
    }

    public function testAStateBoundToOneCallerIsRefusedForAnother(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertNull($signer->verify($signer->sign('round-2', 'user-alice'), 'user-bob'));
    }

    public function testABoundStateIsRefusedWhenTheBindingIsOmitted(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertNull($signer->verify($signer->sign('round-2', 'user-alice')));
    }

    public function testAnUnboundStateIsRefusedForABoundCaller(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertNull($signer->verify($signer->sign('round-2'), 'user-alice'));
    }

    public function testTheBindingCannotBeShiftedIntoThePayload(): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertNotSame(
            $signer->sign('c', 'ab'),
            $signer->sign('bc', 'a'),
            'Length-prefixing is what stops one signing input serving two binding-and-payload pairs.',
        );
    }

    /**
     * Pins the signature format, since a state minted by one instance must verify on another.
     */
    #[DataProvider('provideTheSignatureFormatIsPinnedCases')]
    public function testTheSignatureFormatIsPinned(string $payload, string $binding, string $expected): void
    {
        self::assertSame($expected, (new RequestStateSigner('secret'))->sign($payload, $binding));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTheSignatureFormatIsPinnedCases(): iterable
    {
        yield 'bound' => [
            'round-2',
            'user-alice',
            'round-2.e5d866e7d054283b1f0674c2b640dd37a84c1d2018cf812a4112d250d315a92f',
        ];

        yield 'unbound' => [
            'round-2',
            '',
            'round-2.4d02aaa8b774574e17218fa69b962651e907fd265a2ecdad591dfda265e2e69e',
        ];
    }

    #[DataProvider('provideVerifyRejectsATamperedStateCases')]
    public function testVerifyRejectsATamperedState(string $state): void
    {
        self::assertNull((new RequestStateSigner('secret'))->verify($state));
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

        yield 'signed by another secret' => [(new RequestStateSigner('other'))->sign('round-1')];
    }

    public function testTwoSecretsDoNotVerifyEachOther(): void
    {
        $mine = new RequestStateSigner('mine');

        self::assertNull($mine->verify((new RequestStateSigner('yours'))->sign('round-1')));
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
        self::assertNull((new RequestStateSigner('secret'))->verify($signer->sign('round-1')));
    }

    public function testConstructorRejectsAnEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The request-state signing secret must be a non-empty string.');

        new RequestStateSigner('');
    }

    public function testConstructorRejectsAnUnavailableAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The request-state signing algorithm "rot13" is not available.');

        new RequestStateSigner('secret', 'rot13');
    }
}
