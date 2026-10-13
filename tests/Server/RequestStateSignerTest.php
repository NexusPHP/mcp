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

use Nexus\Clock\FrozenClock;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Server\RequestStateSigner;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
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
    private const int NOW = 1_700_000_000;

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
        $signer = new RequestStateSigner('secret', clock: new FrozenClock('@'.self::NOW));

        self::assertSame($expected, $signer->sign($payload, $binding));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTheSignatureFormatIsPinnedCases(): iterable
    {
        yield 'bound' => [
            'round-2',
            'user-alice',
            'round-2.1700000600.9cdba496b6d22fce06163732090f6e2e7d656e3a423b9bb7f1c8f9149ae05ba2',
        ];

        yield 'unbound' => [
            'round-2',
            '',
            'round-2.1700000600.0fc9f1d6d7e02c62ab8260cb32d654df3f5ea8918691a6f266649c4546e6ea7c',
        ];
    }

    public function testAStateVerifiesUntilItsExpiryAndNotAfter(): void
    {
        $state = (new RequestStateSigner('secret', ttlSeconds: 60, clock: new FrozenClock('@'.self::NOW)))->sign('round-1');

        self::assertSame('round-1', (new RequestStateSigner('secret', clock: new FrozenClock('@'.(self::NOW + 60))))->verify($state));
        self::assertNull((new RequestStateSigner('secret', clock: new FrozenClock('@'.(self::NOW + 61))))->verify($state));
    }

    public function testTheDefaultTtlIsTenMinutes(): void
    {
        $state = (new RequestStateSigner('secret', clock: new FrozenClock('@'.self::NOW)))->sign('round-1');

        self::assertSame('round-1', (new RequestStateSigner('secret', clock: new FrozenClock('@'.(self::NOW + 600))))->verify($state));
        self::assertNull((new RequestStateSigner('secret', clock: new FrozenClock('@'.(self::NOW + 601))))->verify($state));
    }

    public function testAGeneratedSignerTakesItsTtl(): void
    {
        $before = time();
        $state = RequestStateSigner::generate(ttlSeconds: 1)->sign('round-1');
        $after = time();

        self::assertSame(1, preg_match('/\Around-1\.(\d+)\.[0-9a-f]{64}\z/', $state, $parts));
        self::assertGreaterThanOrEqual($before + 1, (int) $parts[1]);
        self::assertLessThanOrEqual($after + 1, (int) $parts[1]);
    }

    public function testTheExpiryCannotBeEdited(): void
    {
        $signer = new RequestStateSigner('secret', clock: new FrozenClock('@'.self::NOW));
        $state = $signer->sign('round-1');

        self::assertNull($signer->verify(str_replace('.1700000600.', '.1700009999.', $state)));
    }

    public function testABoundSignerAppliesItsBindingByDefault(): void
    {
        $signer = new RequestStateSigner('secret');
        $bound = $signer->bindTo('user-alice');

        self::assertSame('round-2', $bound->verify($bound->sign('round-2')));
        self::assertSame('round-2', $signer->verify($bound->sign('round-2'), 'user-alice'));
        self::assertNull($signer->verify($bound->sign('round-2')));
        self::assertNull($bound->verify($signer->sign('round-2')));
    }

    public function testAnExplicitBindingOverridesTheBoundOne(): void
    {
        $bound = (new RequestStateSigner('secret'))->bindTo('user-alice');

        self::assertSame('round-2', $bound->verify($bound->sign('round-2', 'user-bob'), 'user-bob'));
        self::assertNull($bound->verify($bound->sign('round-2', 'user-bob')));
        self::assertSame('round-2', $bound->verify($bound->sign('round-2', ''), 'user-alice'));
    }

    /**
     * @param JsonRpcRequest<non-empty-string> $request
     */
    #[DataProvider('provideBindForDerivesTheSubjectMethodAndTargetCases')]
    public function testBindForDerivesTheSubjectMethodAndTarget(JsonRpcRequest $request, ReceiveContext $receiveContext, string $expected): void
    {
        $signer = new RequestStateSigner('secret');

        self::assertSame($expected, RequestStateSigner::deriveBinding($request, $receiveContext));
        self::assertSame('round-1', $signer->bindFor($request, $receiveContext)->verify($signer->sign('round-1', $expected)));
    }

    /**
     * @return iterable<string, array{JsonRpcRequest<non-empty-string>, ReceiveContext, string}>
     */
    public static function provideBindForDerivesTheSubjectMethodAndTargetCases(): iterable
    {
        $meta = RequestMetaObjectFactory::shape();
        $authenticated = new ReceiveContext(authInfo: new VerifiedAccessToken(['https://mcp.example.com'], 2_000_000_000, [], subject: 'alice'));
        $digest = hash('sha256', '{"a":{"x":1,"y":[2,1]},"b":"two"}');

        yield 'tool call by an authenticated caller' => [
            CallToolRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta, 'name' => 'deploy']]),
            $authenticated,
            "alice\0tools/call\0deploy\0",
        ];

        yield 'tool call with arguments, keys in any order' => [
            CallToolRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta, 'name' => 'deploy', 'arguments' => ['b' => 'two', 'a' => ['y' => [2, 1], 'x' => 1]]]]),
            $authenticated,
            "alice\0tools/call\0deploy\0".$digest,
        ];

        yield 'prompt by an anonymous caller' => [
            GetPromptRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta, 'name' => 'review', 'arguments' => ['b' => 'two', 'a' => 'one']]]),
            new ReceiveContext(),
            "\0prompts/get\0review\0".hash('sha256', '{"a":"one","b":"two"}'),
        ];

        yield 'resource read' => [
            ReadResourceRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta, 'uri' => 'file:///a']]),
            $authenticated,
            "alice\0resources/read\0file:///a\0",
        ];

        yield 'a request without a target' => [
            ListToolsRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta]]),
            $authenticated,
            "alice\0tools/list\0\0",
        ];
    }

    public function testDifferentArgumentsBindDifferently(): void
    {
        $meta = RequestMetaObjectFactory::shape();
        $staging = CallToolRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta, 'name' => 'deploy', 'arguments' => ['target' => 'staging']]]);
        $production = CallToolRequest::fromArray(['id' => 1, 'params' => ['_meta' => $meta, 'name' => 'deploy', 'arguments' => ['target' => 'production']]]);
        $signer = new RequestStateSigner('secret');

        $state = $signer->bindFor($staging, new ReceiveContext())->sign('awaiting-confirmation');

        self::assertSame('awaiting-confirmation', $signer->bindFor($staging, new ReceiveContext())->verify($state));
        self::assertNull($signer->bindFor($production, new ReceiveContext())->verify($state));
    }

    public function testBindByReplacesTheDefaultPolicy(): void
    {
        $request = CallToolRequest::fromArray(['id' => 1, 'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'deploy']]);
        $seen = [];
        $signer = (new RequestStateSigner('secret'))->bindBy(static function (JsonRpcRequest $request, ReceiveContext $receiveContext) use (&$seen): string {
            $seen[] = [$request, $receiveContext];

            return 'tenant-7';
        });
        $receiveContext = new ReceiveContext();

        $bound = $signer->bindFor($request, $receiveContext);

        self::assertSame([[$request, $receiveContext]], $seen);
        self::assertSame('round-1', $bound->verify($signer->sign('round-1', 'tenant-7')));
        self::assertNull($bound->verify($signer->sign('round-1', RequestStateSigner::deriveBinding($request, $receiveContext))));
    }

    public function testBindingKeepsTheSecretAlgorithmTtlAndClock(): void
    {
        $clock = new FrozenClock('@'.self::NOW);
        $bound = (new RequestStateSigner('secret', 'sha512', 60, $clock))->bindTo('user-alice');

        self::assertSame('round-1.1700000060.', substr($bound->sign('round-1'), 0, 19));
        self::assertSame('round-1', (new RequestStateSigner('secret', 'sha512', clock: $clock))->verify($bound->sign('round-1'), 'user-alice'));
    }

    public function testConstructorRejectsANonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The request-state TTL must be a positive number of seconds, 0 given.');

        // @phpstan-ignore argument.type (a zero TTL drives the runtime guard PHPStan rejects statically)
        new RequestStateSigner('secret', ttlSeconds: 0);
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

        yield 'one separator' => ['round-1.'.substr($signed, (int) strrpos($signed, '.') + 1)];

        yield 'empty' => [''];

        yield 'payload swapped, signature kept' => ['round-9'.substr($signed, \strlen('round-1'))];

        yield 'expiry not a number' => [(string) preg_replace('/\.\d+\./', '.soon.', $signed)];

        yield 'signature truncated' => [substr($signed, 0, -1)];

        yield 'signature emptied' => [(string) preg_replace('/[0-9a-f]{64}\z/', '', $signed)];

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
