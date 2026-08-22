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

use Nexus\Mcp\Core\Validation\IdentifierNameValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(IdentifierNameValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class IdentifierNameValidatorTest extends AbstractMcpTestCase
{
    /**
     * @param non-empty-string $name
     */
    #[DataProvider('provideAcceptsValidNameCases')]
    public function testAcceptsValidName(string $name): void
    {
        $this->expectNotToPerformAssertions();

        IdentifierNameValidator::validate($name, 'resource "name"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAcceptsValidNameCases(): iterable
    {
        yield 'simple kebab-case' => ['my-resource'];

        yield 'camelCase' => ['getResource'];

        yield 'underscores and digits' => ['DATA_EXPORT_v2'];

        yield 'dotted namespace' => ['admin.tools.list'];

        yield 'single character' => ['a'];

        yield 'exactly 128 characters' => [str_repeat('a', 128)];
    }

    /**
     * @param non-empty-string $name
     */
    #[DataProvider('provideRejectsInvalidNameCases')]
    public function testRejectsInvalidName(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\Aresource "name" must be 1-128 characters/');

        IdentifierNameValidator::validate($name, 'resource "name"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideRejectsInvalidNameCases(): iterable
    {
        yield 'space inside' => ['my resource'];

        yield 'leading whitespace' => [' my-resource'];

        yield 'comma' => ['my,resource'];

        yield 'unicode letter' => ['résource'];

        yield 'forward-slash' => ['user-profile/update'];

        yield 'longer than 128 characters' => [str_repeat('a', 129)];

        yield 'special character' => ['my-resource!'];

        yield 'colon' => ['my:resource'];

        yield 'trailing newline' => ["my-resource\n"];
    }

    /**
     * The `string $name` parameter type widens past the literal-string types
     * PHPStan would otherwise pin on the data-provider values, so the
     * validator's `@phpstan-assert non-empty-string $name` doesn't produce an
     * always-true error at the call site.
     *
     * @param non-empty-string $context
     * @param non-empty-string $messagePattern
     */
    #[DataProvider('provideContextPrefixAppearsInErrorMessageCases')]
    public function testContextPrefixAppearsInErrorMessage(string $name, string $context, string $messagePattern): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        IdentifierNameValidator::validate($name, $context);
    }

    /**
     * @return iterable<string, array{string, non-empty-string, non-empty-string}>
     */
    public static function provideContextPrefixAppearsInErrorMessageCases(): iterable
    {
        yield 'tool prefix' => ['bad name', 'tool "name"', '/\Atool "name" must be 1-128 characters/'];

        yield 'prompt prefix' => ['bad name', 'prompt "name"', '/\Aprompt "name" must be 1-128 characters/'];
    }
}
