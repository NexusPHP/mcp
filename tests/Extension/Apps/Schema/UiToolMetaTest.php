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

use Nexus\Mcp\Extension\Apps\Schema\Enum\ToolVisibility;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UiToolMeta::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UiToolMetaTest extends AbstractMcpTestCase
{
    public function testRoundTripsTheFullShape(): void
    {
        $meta = new UiToolMeta(
            resourceUri: 'ui://weather-server/dashboard',
            visibility: [ToolVisibility::Model, ToolVisibility::App],
        );

        self::assertSame([
            'resourceUri' => 'ui://weather-server/dashboard',
            'visibility' => ['model', 'app'],
        ], $meta->toArray());
        self::assertSame($meta->toArray(), UiToolMeta::fromArray($meta->toArray())->toArray());
        self::assertSame(
            json_encode($meta->toArray(), \JSON_THROW_ON_ERROR),
            json_encode($meta, \JSON_THROW_ON_ERROR),
        );
    }

    public function testOmitsAbsentSlotsAndDoesNotMaterialiseTheDefaultVisibility(): void
    {
        $meta = new UiToolMeta(resourceUri: 'ui://demo/panel');

        self::assertSame(['resourceUri' => 'ui://demo/panel'], $meta->toArray());
        self::assertNull($meta->visibility);
    }

    public function testAnEmptyMetaEncodesAsAnEmptyObject(): void
    {
        $meta = new UiToolMeta();

        self::assertSame([], $meta->toArray());
        self::assertSame('{}', json_encode($meta, \JSON_THROW_ON_ERROR));
    }

    public function testRejectsAResourceUriOutsideTheUiScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"_meta.ui.resourceUri" must start with "ui://".');

        new UiToolMeta(resourceUri: 'https://example.com/panel');
    }

    public function testRejectsANonListVisibilityArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"_meta.ui.visibility" must be a list, array given.');

        // @phpstan-ignore argument.type
        new UiToolMeta(visibility: ['first' => ToolVisibility::Model]);
    }

    public function testRejectsAVisibilityEntryThatIsNotAToolVisibility(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "_meta.ui.visibility" must be a tool visibility, string given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new UiToolMeta(visibility: ['model']);
    }

    public function testFromArrayToleratesUnknownKeys(): void
    {
        $meta = UiToolMeta::fromArray(['resourceUri' => 'ui://demo/panel', 'future' => true]);

        self::assertSame(['resourceUri' => 'ui://demo/panel'], $meta->toArray());
    }

    public function testFromArrayDecodesTheVisibilityValues(): void
    {
        $meta = UiToolMeta::fromArray(['visibility' => ['app']]);

        self::assertSame([ToolVisibility::App], $meta->visibility);
    }

    #[DataProvider('provideFromArrayRejectsMalformedInputCases')]
    public function testFromArrayRejectsMalformedInput(mixed $value, string $key, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UiToolMeta::fromArray([$key => $value]);
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function provideFromArrayRejectsMalformedInputCases(): iterable
    {
        yield 'resourceUri int' => [123, 'resourceUri', '"_meta.ui.resourceUri" must be a non-empty string or null, int given.'];

        yield 'resourceUri empty' => ['', 'resourceUri', '"_meta.ui.resourceUri" must be a non-empty string or null, string given.'];

        yield 'visibility non-list' => ['model', 'visibility', '"_meta.ui.visibility" must be a list, string given.'];

        yield 'visibility unknown value' => [['nope'], 'visibility', 'each "_meta.ui.visibility" must be one of [\'model\', \'app\'], \'nope\' given.'];
    }
}
