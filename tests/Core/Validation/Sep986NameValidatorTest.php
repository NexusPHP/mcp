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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Validation\Sep986NameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Sep986NameValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class Sep986NameValidatorTest extends TestCase
{
    /**
     * @param non-empty-string $name
     */
    #[DataProvider('provideAcceptsValidNameCases')]
    public function testAcceptsValidName(string $name): void
    {
        $this->expectNotToPerformAssertions();

        Sep986NameValidator::validate($name, 'Resource');
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

        yield 'forward-slash namespace' => ['user-profile/update'];

        yield 'single character' => ['a'];

        yield 'exactly 64 characters' => [str_repeat('a', 64)];
    }

    /**
     * @param non-empty-string $name
     */
    #[DataProvider('provideRejectsInvalidNameCases')]
    public function testRejectsInvalidName(string $name): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResource name must be 1-64 characters/');

        Sep986NameValidator::validate($name, 'Resource');
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

        yield 'longer than 64 characters' => [str_repeat('a', 65)];

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
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        Sep986NameValidator::validate($name, $context);
    }

    /**
     * @return iterable<string, array{string, non-empty-string, non-empty-string}>
     */
    public static function provideContextPrefixAppearsInErrorMessageCases(): iterable
    {
        yield 'Tool prefix' => ['bad name', 'Tool', '/\ATool name must be 1-64 characters/'];

        yield 'Prompt prefix' => ['bad name', 'Prompt', '/\APrompt name must be 1-64 characters/'];
    }
}
