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

use Nexus\Mcp\Extension\Apps\Schema\UiResourcePermissions;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UiResourcePermissions::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UiResourcePermissionsTest extends AbstractMcpTestCase
{
    public function testEncodesEachRequestedPermissionAsAnEmptyObject(): void
    {
        $permissions = new UiResourcePermissions(camera: true, clipboardWrite: true);

        self::assertSame(['camera', 'clipboardWrite'], array_keys($permissions->toArray()));
        self::assertSame(
            '{"camera":{},"clipboardWrite":{}}',
            json_encode($permissions, \JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            json_encode($permissions, \JSON_THROW_ON_ERROR),
            json_encode($permissions->toArray(), \JSON_THROW_ON_ERROR),
        );
    }

    public function testEncodesEveryPermission(): void
    {
        $permissions = new UiResourcePermissions(
            camera: true,
            microphone: true,
            geolocation: true,
            clipboardWrite: true,
        );

        self::assertSame(
            '{"camera":{},"microphone":{},"geolocation":{},"clipboardWrite":{}}',
            json_encode($permissions, \JSON_THROW_ON_ERROR),
        );
    }

    public function testNoRequestedPermissionEncodesAsAnEmptyObject(): void
    {
        $permissions = new UiResourcePermissions();

        self::assertSame([], $permissions->toArray());
        self::assertSame('{}', json_encode($permissions, \JSON_THROW_ON_ERROR));
    }

    public function testFromArrayReadsKeyPresence(): void
    {
        $permissions = UiResourcePermissions::fromArray(['microphone' => [], 'geolocation' => new \stdClass()]);

        self::assertFalse($permissions->camera);
        self::assertTrue($permissions->microphone);
        self::assertTrue($permissions->geolocation);
        self::assertFalse($permissions->clipboardWrite);
    }

    #[DataProvider('provideFromArrayReadsAnyPresentValueAsRequestedCases')]
    public function testFromArrayReadsAnyPresentValueAsRequested(mixed $value, bool $expected): void
    {
        $permissions = UiResourcePermissions::fromArray([
            'camera' => $value,
            'microphone' => $value,
            'geolocation' => $value,
            'clipboardWrite' => $value,
        ]);

        self::assertSame($expected, $permissions->camera);
        self::assertSame($expected, $permissions->microphone);
        self::assertSame($expected, $permissions->geolocation);
        self::assertSame($expected, $permissions->clipboardWrite);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideFromArrayReadsAnyPresentValueAsRequestedCases(): iterable
    {
        yield 'true' => [true, true];

        yield 'an empty object' => [new \stdClass(), true];

        yield 'an empty array' => [[], true];

        yield 'a string' => ['granted', true];

        yield 'an int' => [1, true];

        yield 'false' => [false, false];

        yield 'null' => [null, false];
    }

    public function testRoundTripsTheDecodedForm(): void
    {
        $permissions = new UiResourcePermissions(camera: true, geolocation: true);

        $reconstructed = UiResourcePermissions::fromArray(['camera' => [], 'geolocation' => []]);

        self::assertSame(
            json_encode($permissions, \JSON_THROW_ON_ERROR),
            json_encode($reconstructed, \JSON_THROW_ON_ERROR),
        );
        self::assertTrue($reconstructed->camera);
        self::assertFalse($reconstructed->microphone);
        self::assertTrue($reconstructed->geolocation);
        self::assertFalse($reconstructed->clipboardWrite);
    }
}
