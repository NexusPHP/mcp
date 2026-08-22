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

use Nexus\Mcp\Core\Validation\ExtensionIdentifierValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ExtensionIdentifierValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ExtensionIdentifierValidatorTest extends AbstractMcpTestCase
{
    #[DataProvider('provideValidIdentifierPassesCases')]
    public function testValidIdentifierPasses(string $identifier): void
    {
        $this->expectNotToPerformAssertions();

        ExtensionIdentifierValidator::validate($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidIdentifierPassesCases(): iterable
    {
        yield 'official tasks identifier' => ['io.modelcontextprotocol/tasks'];

        yield 'official oauth identifier' => ['io.modelcontextprotocol/oauth-client-credentials'];

        yield 'single-char prefix and name' => ['a/b'];

        yield 'name starting with a digit' => ['a/1abc'];

        yield 'prefix label ending with a digit' => ['a1/b'];

        yield 'hyphenated prefix labels' => ['a-b.c-d/feature'];

        yield 'name with inner dots, hyphens, and underscores' => ['com.example/x_y.z-1'];

        yield 'uppercase letters' => ['Com.Example/Feature'];
    }

    #[DataProvider('provideInvalidIdentifierIsRejectedCases')]
    public function testInvalidIdentifierIsRejected(string $identifier, string $exported): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Extension identifier must be "{vendor-prefix}/{name}" following the "_meta" key grammar with a mandatory prefix, %s given.',
            $exported,
        ));

        ExtensionIdentifierValidator::validate($identifier);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidIdentifierIsRejectedCases(): iterable
    {
        yield 'empty string' => ['', '\'\''];

        yield 'no prefix' => ['tasks', '\'tasks\''];

        yield 'empty prefix' => ['/tasks', '\'/tasks\''];

        yield 'empty name' => ['a/', '\'a/\''];

        yield 'prefix label starting with a digit' => ['1a/b', '\'1a/b\''];

        yield 'prefix label ending with a hyphen' => ['a-/b', '\'a-/b\''];

        yield 'empty prefix label' => ['a..b/c', '\'a..b/c\''];

        yield 'leading dot in prefix' => ['.a/b', '\'.a/b\''];

        yield 'trailing dot in prefix' => ['a./b', '\'a./b\''];

        yield 'name ending with a hyphen' => ['a/b-', '\'a/b-\''];

        yield 'second slash' => ['a/b/c', '\'a/b/c\''];

        yield 'whitespace in name' => ['a/b c', '\'a/b c\''];

        yield 'underscore in prefix' => ['a_b/c', '\'a_b/c\''];
    }
}
