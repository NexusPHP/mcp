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

    #[DataProvider('provideFromArrayRejectsANonObjectValueCases')]
    public function testFromArrayRejectsANonObjectValue(mixed $value, string $slot, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UiResourcePermissions::fromArray([$slot => $value]);
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function provideFromArrayRejectsANonObjectValueCases(): iterable
    {
        yield 'camera false' => [false, 'camera', '"_meta.ui.permissions.camera" must be an object, bool given.'];

        yield 'microphone null' => [null, 'microphone', '"_meta.ui.permissions.microphone" must be an object, null given.'];

        yield 'geolocation string' => ['granted', 'geolocation', '"_meta.ui.permissions.geolocation" must be an object, string given.'];

        yield 'clipboardWrite int' => [1, 'clipboardWrite', '"_meta.ui.permissions.clipboardWrite" must be an object, int given.'];
    }

    public function testRoundTripsTheDecodedForm(): void
    {
        $permissions = new UiResourcePermissions(camera: true, geolocation: true);

        // json_decode(assoc) renders each `{}` value as an empty array.
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
