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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Icon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Icon::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class IconTest extends TestCase
{
    public function testIconCanBeCreatedWithHttpUrl(): void
    {
        $icon = new Icon(src: 'https://example.com/icon.png');

        self::assertSame('https://example.com/icon.png', $icon->src);
        self::assertNull($icon->mimeType);
        self::assertNull($icon->sizes);
        self::assertNull($icon->theme);
    }

    public function testIconCanBeCreatedWithHttpsUrl(): void
    {
        $icon = new Icon(src: 'http://example.com/icon.png');

        self::assertSame('http://example.com/icon.png', $icon->src);
    }

    public function testIconCanBeCreatedWithDataUri(): void
    {
        $icon = new Icon(src: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA');

        self::assertSame('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA', $icon->src);
    }

    public function testIconCanBeCreatedWithDataUriWithPadding(): void
    {
        $icon = new Icon(src: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmc+PC9zdmc+==');

        self::assertSame('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmc+PC9zdmc+==', $icon->src);
    }

    public function testIconCanBeCreatedWithAllParameters(): void
    {
        $icon = new Icon(
            src: 'https://example.com/icon.png',
            mimeType: 'image/png',
            sizes: ['32x32', '64x64', 'any'],
            theme: 'dark',
        );

        self::assertSame('https://example.com/icon.png', $icon->src);
        self::assertSame('image/png', $icon->mimeType);
        self::assertSame(['32x32', '64x64', 'any'], $icon->sizes);
        self::assertSame('dark', $icon->theme);
    }

    #[DataProvider('provideIconSrcValidationCases')]
    public function testIconSrcValidation(string $src, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new Icon(src: $src);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideIconSrcValidationCases(): iterable
    {
        yield 'empty string' => ['', '"icons.src" must be a non-empty string.'];

        yield 'invalid protocol' => ['ftp://example.com/icon.png', '"icons.src" must be a valid HTTP/HTTPS URL or a data URI with base64-encoded data.'];

        yield 'relative path' => ['/path/to/icon.png', '"icons.src" must be a valid HTTP/HTTPS URL or a data URI with base64-encoded data.'];

        yield 'data URI without base64' => ['data:image/png,notbase64', '"icons.src" must be a valid HTTP/HTTPS URL or a data URI with base64-encoded data.'];

        yield 'data URI without semicolon' => ['data:image/pngbase64,abc', '"icons.src" must be a valid HTTP/HTTPS URL or a data URI with base64-encoded data.'];
    }

    /**
     * @param non-empty-string $mimeType
     */
    #[DataProvider('provideIconAcceptsValidMimeTypesCases')]
    public function testIconAcceptsValidMimeTypes(string $mimeType): void
    {
        $icon = new Icon(src: 'https://example.com/icon.png', mimeType: $mimeType);

        self::assertSame($mimeType, $icon->mimeType);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideIconAcceptsValidMimeTypesCases(): iterable
    {
        yield 'image/png' => ['image/png'];

        yield 'image/svg+xml' => ['image/svg+xml'];

        yield 'image/jpeg' => ['image/jpeg'];

        yield 'application/json' => ['application/json'];

        yield 'text/plain' => ['text/plain'];

        yield 'audio/mp4' => ['audio/mp4'];

        yield 'video/h264' => ['video/h264'];

        yield 'application/vnd.api+json' => ['application/vnd.api+json'];
    }

    #[DataProvider('provideIconRejectsInvalidMimeTypesCases')]
    public function testIconRejectsInvalidMimeTypes(string $mimeType, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new Icon(src: 'https://example.com/icon.png', mimeType: $mimeType);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideIconRejectsInvalidMimeTypesCases(): iterable
    {
        yield 'empty string' => ['', '"icons.mimeType" must be a non-empty string or null.'];

        yield 'missing subtype' => ['image', '"icons.mimeType" must be a valid MIME type in the format "type/subtype".'];

        yield 'missing type' => ['/png', '"icons.mimeType" must be a valid MIME type in the format "type/subtype".'];

        yield 'type with number' => ['image2/png', '"icons.mimeType" must be a valid MIME type in the format "type/subtype".'];
    }

    /**
     * @param list<non-empty-string> $sizes
     */
    #[DataProvider('provideIconAcceptsValidSizesCases')]
    public function testIconAcceptsValidSizes(array $sizes): void
    {
        $icon = new Icon(src: 'https://example.com/icon.png', sizes: $sizes);

        self::assertSame($sizes, $icon->sizes);
    }

    /**
     * @return iterable<string, array{list<non-empty-string>}>
     */
    public static function provideIconAcceptsValidSizesCases(): iterable
    {
        yield 'single size' => [['32x32']];

        yield 'multiple sizes' => [['16x16', '32x32', '64x64']];

        yield 'any size' => [['any']];

        yield 'mixed sizes' => [['16x16', 'any', '128x128']];
    }

    /**
     * @param list<string> $sizes
     */
    #[DataProvider('provideIconRejectsInvalidSizesCases')]
    public function testIconRejectsInvalidSizes(array $sizes, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new Icon(src: 'https://example.com/icon.png', sizes: $sizes);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideIconRejectsInvalidSizesCases(): iterable
    {
        yield 'empty string' => [[''], 'each "icons.sizes" must be a non-empty string.'];

        yield 'invalid format' => [['32'], 'each "icons.sizes" must be in the format "WIDTHxHEIGHT" or "any".'];

        yield 'wrong separator' => [['32*32'], 'each "icons.sizes" must be in the format "WIDTHxHEIGHT" or "any".'];

        yield 'non-numeric dimensions' => [['widthxheight'], 'each "icons.sizes" must be in the format "WIDTHxHEIGHT" or "any".'];
    }

    /**
     * @param 'dark'|'light' $theme
     */
    #[DataProvider('provideIconAcceptsValidThemesCases')]
    public function testIconAcceptsValidThemes(string $theme): void
    {
        $icon = new Icon(src: 'https://example.com/icon.png', theme: $theme);

        self::assertSame($theme, $icon->theme);
    }

    /**
     * @return iterable<string, array{'dark'|'light'}>
     */
    public static function provideIconAcceptsValidThemesCases(): iterable
    {
        yield 'light' => ['light'];

        yield 'dark' => ['dark'];
    }

    public function testIconRejectsInvalidTheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"icons.theme" must be one of "light", "dark".');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new Icon(src: 'https://example.com/icon.png', theme: 'invalid');
    }

    public function testIconCanBeCreatedFromArray(): void
    {
        $data = [
            'src' => 'https://example.com/icon.png',
            'mimeType' => 'image/png',
            'sizes' => ['32x32', '64x64'],
            'theme' => 'dark',
        ];

        $icon = Icon::fromArray($data);

        self::assertSame('https://example.com/icon.png', $icon->src);
        self::assertSame('image/png', $icon->mimeType);
        self::assertSame(['32x32', '64x64'], $icon->sizes);
        self::assertSame('dark', $icon->theme);
    }

    public function testIconCanBeCreatedFromArrayWithTheLightTheme(): void
    {
        self::assertSame('light', Icon::fromArray(['src' => 'https://example.com/icon.png', 'theme' => 'light'])->theme);
    }

    public function testIconCanBeCreatedFromArrayWithOnlySrc(): void
    {
        $data = ['src' => 'https://example.com/icon.png'];

        $icon = Icon::fromArray($data);

        self::assertSame('https://example.com/icon.png', $icon->src);
        self::assertNull($icon->mimeType);
        self::assertNull($icon->sizes);
        self::assertNull($icon->theme);
    }

    public function testIconToArrayIncludesOnlySrc(): void
    {
        $icon = new Icon(src: 'https://example.com/icon.png');

        self::assertSame(['src' => 'https://example.com/icon.png'], $icon->toArray());
    }

    public function testIconToArrayIncludesAllSetProperties(): void
    {
        $icon = new Icon(
            src: 'https://example.com/icon.png',
            mimeType: 'image/png',
            sizes: ['32x32', '64x64'],
            theme: 'dark',
        );

        $expected = [
            'src' => 'https://example.com/icon.png',
            'mimeType' => 'image/png',
            'sizes' => ['32x32', '64x64'],
            'theme' => 'dark',
        ];

        self::assertSame($expected, $icon->toArray());
    }

    public function testIconJsonSerializeMatchesToArray(): void
    {
        $icon = new Icon(
            src: 'https://example.com/icon.png',
            mimeType: 'image/png',
            sizes: ['32x32'],
            theme: 'light',
        );

        self::assertSame($icon->toArray(), $icon->jsonSerialize());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        Icon::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing src' => [
            [],
            '"icons" is missing the required "src" key.',
        ];

        yield 'src not a string' => [
            ['src' => 1],
            '"icons.src" must be a non-empty string, int given.',
        ];

        yield 'mimeType not a string' => [
            ['src' => 'https://example.com/icon.png', 'mimeType' => 1],
            '"icons.mimeType" must be a non-empty string or null, int given.',
        ];

        yield 'sizes not an array' => [
            ['src' => 'https://example.com/icon.png', 'sizes' => 'oops'],
            '"icons.sizes" must be a list of strings or null, string given.',
        ];

        yield 'sizes entry not a string' => [
            ['src' => 'https://example.com/icon.png', 'sizes' => [1]],
            'each "icons.sizes" must be a non-empty string, int given.',
        ];

        yield 'theme not a string' => [
            ['src' => 'https://example.com/icon.png', 'theme' => 1],
            '"icons.theme" must be one of "light", "dark".',
        ];
    }
}
