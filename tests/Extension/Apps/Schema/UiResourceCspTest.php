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

namespace Nexus\Mcp\Tests\Extension\Apps\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceCsp;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UiResourceCsp::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UiResourceCspTest extends AbstractMcpTestCase
{
    public function testRoundTripsTheFullShape(): void
    {
        $csp = new UiResourceCsp(
            connectDomains: ['https://api.openweathermap.org'],
            resourceDomains: ['https://cdn.jsdelivr.net'],
            frameDomains: ['https://frames.example.com'],
            baseUriDomains: ['https://base.example.com'],
        );

        self::assertSame([
            'connectDomains' => ['https://api.openweathermap.org'],
            'resourceDomains' => ['https://cdn.jsdelivr.net'],
            'frameDomains' => ['https://frames.example.com'],
            'baseUriDomains' => ['https://base.example.com'],
        ], $csp->toArray());
        self::assertSame($csp->toArray(), UiResourceCsp::fromArray($csp->toArray())->toArray());
        self::assertSame(
            json_encode($csp->toArray(), \JSON_THROW_ON_ERROR),
            json_encode($csp, \JSON_THROW_ON_ERROR),
        );
    }

    #[DataProvider('provideAnEmptyListIsOmittedLikeAnAbsentOneCases')]
    public function testAnEmptyListIsOmittedLikeAnAbsentOne(UiResourceCsp $csp): void
    {
        self::assertSame([], $csp->toArray());
    }

    /**
     * @return iterable<string, array{UiResourceCsp}>
     */
    public static function provideAnEmptyListIsOmittedLikeAnAbsentOneCases(): iterable
    {
        yield 'connectDomains' => [new UiResourceCsp(connectDomains: [])];

        yield 'resourceDomains' => [new UiResourceCsp(resourceDomains: [])];

        yield 'frameDomains' => [new UiResourceCsp(frameDomains: [])];

        yield 'baseUriDomains' => [new UiResourceCsp(baseUriDomains: [])];
    }

    public function testAnEmptyCspEncodesAsAnEmptyObject(): void
    {
        self::assertSame('{}', json_encode(new UiResourceCsp(), \JSON_THROW_ON_ERROR));
    }

    public function testRejectsANonListDomainArray(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"_meta.ui.csp.connectDomains" must be a list, array given.');

        // @phpstan-ignore argument.type
        new UiResourceCsp(connectDomains: ['api' => 'https://api.example.com']);
    }

    public function testFromArrayReadsAbsentSlotsAsNull(): void
    {
        $csp = UiResourceCsp::fromArray(['frameDomains' => ['https://frames.example.com']]);

        self::assertNull($csp->connectDomains);
        self::assertNull($csp->resourceDomains);
        self::assertSame(['https://frames.example.com'], $csp->frameDomains);
        self::assertNull($csp->baseUriDomains);
    }

    /**
     * @param \Closure(): UiResourceCsp $construct
     */
    #[DataProvider('provideRejectsAMalformedDomainEntryCases')]
    public function testRejectsAMalformedDomainEntry(\Closure $construct, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        $construct();
    }

    /**
     * @return iterable<string, array{\Closure(): UiResourceCsp, string}>
     */
    public static function provideRejectsAMalformedDomainEntryCases(): iterable
    {
        yield 'connectDomains' => [
            // @phpstan-ignore argument.type
            static fn(): UiResourceCsp => new UiResourceCsp(connectDomains: ['']),
            'each "_meta.ui.csp.connectDomains" must be a non-empty string, string given.',
        ];

        yield 'resourceDomains' => [
            // @phpstan-ignore argument.type
            static fn(): UiResourceCsp => new UiResourceCsp(resourceDomains: ['']),
            'each "_meta.ui.csp.resourceDomains" must be a non-empty string, string given.',
        ];

        yield 'frameDomains' => [
            // @phpstan-ignore argument.type
            static fn(): UiResourceCsp => new UiResourceCsp(frameDomains: ['']),
            'each "_meta.ui.csp.frameDomains" must be a non-empty string, string given.',
        ];

        yield 'baseUriDomains' => [
            // @phpstan-ignore argument.type
            static fn(): UiResourceCsp => new UiResourceCsp(baseUriDomains: ['']),
            'each "_meta.ui.csp.baseUriDomains" must be a non-empty string, string given.',
        ];
    }

    #[DataProvider('provideFromArrayRejectsMalformedInputCases')]
    public function testFromArrayRejectsMalformedInput(mixed $value, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UiResourceCsp::fromArray(['connectDomains' => $value]);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideFromArrayRejectsMalformedInputCases(): iterable
    {
        yield 'non-list' => ['https://api.example.com', '"_meta.ui.csp.connectDomains" must be a list, string given.'];

        yield 'map' => [['a' => 'https://api.example.com'], '"_meta.ui.csp.connectDomains" must be a list, array given.'];

        yield 'int entry' => [[123], 'each "_meta.ui.csp.connectDomains" must be a non-empty string, int given.'];
    }
}
