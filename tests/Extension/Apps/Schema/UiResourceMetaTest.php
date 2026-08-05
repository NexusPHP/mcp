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
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Schema\UiResourcePermissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UiResourceMeta::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UiResourceMetaTest extends TestCase
{
    public function testRoundTripsTheFullShape(): void
    {
        $meta = new UiResourceMeta(
            csp: new UiResourceCsp(
                connectDomains: ['https://api.openweathermap.org'],
                resourceDomains: ['https://cdn.jsdelivr.net'],
            ),
            permissions: new UiResourcePermissions(camera: true),
            domain: 'abc123.claudemcpcontent.com',
            prefersBorder: true,
        );

        self::assertSame(
            '{"csp":{"connectDomains":["https://api.openweathermap.org"],"resourceDomains":["https://cdn.jsdelivr.net"]},'
            .'"permissions":{"camera":{}},"domain":"abc123.claudemcpcontent.com","prefersBorder":true}',
            json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
        self::assertSame(
            json_encode($meta, \JSON_THROW_ON_ERROR),
            json_encode($meta->toArray(), \JSON_THROW_ON_ERROR),
        );

        $reconstructed = UiResourceMeta::fromArray([
            'csp' => [
                'connectDomains' => ['https://api.openweathermap.org'],
                'resourceDomains' => ['https://cdn.jsdelivr.net'],
            ],
            'permissions' => ['camera' => []],
            'domain' => 'abc123.claudemcpcontent.com',
            'prefersBorder' => true,
        ]);

        self::assertSame(
            json_encode($meta, \JSON_THROW_ON_ERROR),
            json_encode($reconstructed, \JSON_THROW_ON_ERROR),
        );
    }

    public function testOmitsAbsentSlots(): void
    {
        $meta = new UiResourceMeta(prefersBorder: false);

        self::assertSame(['prefersBorder' => false], $meta->toArray());
        self::assertNull($meta->csp);
        self::assertNull($meta->permissions);
        self::assertNull($meta->domain);
    }

    public function testAnEmptyMetaEncodesAsAnEmptyObject(): void
    {
        $meta = new UiResourceMeta();

        self::assertSame([], $meta->toArray());
        self::assertSame('{}', json_encode($meta, \JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyCspCollapsesToNull(): void
    {
        $meta = new UiResourceMeta(csp: new UiResourceCsp(connectDomains: []));

        self::assertNull($meta->csp);
        self::assertSame([], $meta->toArray());
    }

    public function testANonEmptyCspIsKept(): void
    {
        $csp = new UiResourceCsp(frameDomains: ['https://frames.example.com']);

        self::assertSame($csp, new UiResourceMeta(csp: $csp)->csp);
    }

    public function testEmptyPermissionsCollapseToNull(): void
    {
        $meta = new UiResourceMeta(permissions: new UiResourcePermissions());

        self::assertNull($meta->permissions);
        self::assertSame([], $meta->toArray());
    }

    public function testNonEmptyPermissionsAreKept(): void
    {
        $permissions = new UiResourcePermissions(geolocation: true);

        self::assertSame($permissions, new UiResourceMeta(permissions: $permissions)->permissions);
    }

    public function testRejectsAnEmptyDomain(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"_meta.ui.domain" must be a non-empty string or null.');

        // @phpstan-ignore argument.type
        new UiResourceMeta(domain: '');
    }

    #[DataProvider('provideFromArrayRejectsMalformedInputCases')]
    public function testFromArrayRejectsMalformedInput(mixed $value, string $key, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UiResourceMeta::fromArray([$key => $value]);
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function provideFromArrayRejectsMalformedInputCases(): iterable
    {
        yield 'csp non-array' => ['none', 'csp', '"_meta.ui.csp" must be an array, string given.'];

        yield 'csp int-keyed' => [['https://api.example.com'], 'csp', '"_meta.ui.csp" must be a string-keyed object.'];

        yield 'permissions non-array' => [true, 'permissions', '"_meta.ui.permissions" must be an array, bool given.'];

        yield 'permissions int-keyed' => [['camera'], 'permissions', '"_meta.ui.permissions" must be a string-keyed object.'];

        yield 'domain non-string' => [123, 'domain', '"_meta.ui.domain" must be a non-empty string or null.'];

        yield 'prefersBorder non-bool' => ['yes', 'prefersBorder', '"_meta.ui.prefersBorder" must be a bool or null, string given.'];
    }
}
