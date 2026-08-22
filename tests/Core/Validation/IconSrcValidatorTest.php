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

namespace Nexus\Mcp\Tests\Core\Validation;

use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Validation\IconSrcValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(IconSrcValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class IconSrcValidatorTest extends AbstractMcpTestCase
{
    /**
     * @param non-empty-string $src
     */
    #[DataProvider('provideAcceptsAConservativeSrcCases')]
    public function testAcceptsAConservativeSrc(string $src): void
    {
        $this->expectNotToPerformAssertions();

        IconSrcValidator::validate([new Icon(src: $src)], 'tool');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAcceptsAConservativeSrcCases(): iterable
    {
        yield 'https URL' => ['https://example.com/icon.png'];

        yield 'http URL' => ['http://example.com/icon.png'];

        yield 'base64 data URI' => ['data:image/png;base64,iVBORw0KGgo='];

        yield 'parameterised base64 data URI' => ['data:image/svg+xml;charset=utf-8;base64,PHN2Zz48L3N2Zz4='];

        yield 'mediatype-less base64 data URI' => ['data:;base64,aWNvbg=='];
    }

    /**
     * @param non-empty-string $src
     */
    #[DataProvider('provideRejectsANonConservativeSrcCases')]
    public function testRejectsANonConservativeSrc(string $src): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'tool "icons.src" must be an HTTP/HTTPS URL or a data: URI with base64-encoded data, \'%s\' given.',
            $src,
        ));

        IconSrcValidator::validate([new Icon(src: $src)], 'tool');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideRejectsANonConservativeSrcCases(): iterable
    {
        yield 'ftp URL' => ['ftp://example.com/icon.png'];

        yield 'file URI' => ['file:///icons/a.png'];

        yield 'non-base64 data URI' => ['data:image/png,notbase64'];
    }

    public function testANullListPasses(): void
    {
        $this->expectNotToPerformAssertions();

        IconSrcValidator::validate(null, 'tool');
    }

    public function testEveryIconInTheListIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('prompt "icons.src" must be an HTTP/HTTPS URL or a data: URI with base64-encoded data, \'ftp://example.com/b.png\' given.');

        IconSrcValidator::validate([
            new Icon(src: 'https://example.com/a.png'),
            new Icon(src: 'ftp://example.com/b.png'),
        ], 'prompt');
    }
}
