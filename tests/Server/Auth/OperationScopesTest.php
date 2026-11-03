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

namespace Nexus\Mcp\Tests\Server\Auth;

use Nexus\Mcp\Server\Auth\OperationScopes;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(OperationScopes::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class OperationScopesTest extends AbstractMcpTestCase
{
    public function testAToolIsLookedUpByName(): void
    {
        $scopes = new OperationScopes(tools: ['write_file' => ['files:read', 'files:write'], 'ping' => []]);

        self::assertSame(['files:read', 'files:write'], $scopes->forTool('write_file')->values);
        self::assertSame([], $scopes->forTool('ping')->values);
        self::assertSame([], $scopes->forTool('unknown')->values);
        self::assertSame([], $scopes->forPrompt('write_file')->values);
    }

    public function testAToolNamedByDigitsIsLookedUpByName(): void
    {
        $scopes = new OperationScopes(tools: [2_048 => ['game:play']]);

        self::assertSame(['game:play'], $scopes->forTool('2048')->values);
    }

    public function testAPromptIsLookedUpByName(): void
    {
        $scopes = new OperationScopes(prompts: ['release_notes' => ['files:read']]);

        self::assertSame(['files:read'], $scopes->forPrompt('release_notes')->values);
        self::assertSame([], $scopes->forPrompt('unknown')->values);
        self::assertSame([], $scopes->forTool('release_notes')->values);
    }

    public function testAResourceIsMatchedByUriOrTemplate(): void
    {
        $scopes = new OperationScopes(resources: [
            'config://deploy' => ['files:read'],
            'file:///reports/{name}' => ['reports:read'],
            'file:///reports/{id}' => ['files:write'],
        ]);

        self::assertSame(['files:read'], $scopes->forResource('config://deploy')->values);
        self::assertSame(['reports:read', 'files:write'], $scopes->forResource('file:///reports/q3')->values);
        self::assertSame([], $scopes->forResource('config://other')->values);
    }

    public function testAPercentEncodedUriMatchesItsDecodedTemplate(): void
    {
        $scopes = new OperationScopes(resources: ['file:///reports/{name}' => ['reports:read']]);

        self::assertSame(['reports:read'], $scopes->forResource('file:///reports/q%203')->values);
    }

    public function testAUriMatchingOnlyOnceDecodedIsScoped(): void
    {
        $scopes = new OperationScopes(resources: ['file:///reports/{name}' => ['reports:read']]);

        self::assertSame(['reports:read'], $scopes->forResource('file:///rep%6Frts/x')->values);
    }

    public function testAUriMatchingOnlyAsWrittenIsScoped(): void
    {
        $scopes = new OperationScopes(resources: ['file:///reports/{name}' => ['reports:read']]);

        self::assertSame(['reports:read'], $scopes->forResource('file:///reports/a%252Fb')->values);
    }

    public function testNothingIsRequiredByDefault(): void
    {
        $scopes = new OperationScopes();

        self::assertSame([], $scopes->forTool('any')->values);
        self::assertSame([], $scopes->forPrompt('any')->values);
        self::assertSame([], $scopes->forResource('config://any')->values);
    }

    /**
     * @param array<array-key, mixed> $tools
     * @param array<array-key, mixed> $prompts
     * @param array<array-key, mixed> $resources
     */
    #[DataProvider('provideAMalformedDeclarationIsRefusedCases')]
    public function testAMalformedDeclarationIsRefused(
        array $tools,
        array $prompts,
        array $resources,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type, argument.type, argument.type (deliberately malformed to exercise the runtime guards)
        new OperationScopes($tools, $prompts, $resources);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>, array<array-key, mixed>, string}>
     */
    public static function provideAMalformedDeclarationIsRefusedCases(): iterable
    {
        yield 'a tool keyed by an empty name' => [
            ['' => ['files:write']],
            [],
            [],
            'each operation scope tool key must be an int or non-empty string, \'\' given.',
        ];

        yield 'a prompt keyed by an empty name' => [
            [],
            ['' => ['files:write']],
            [],
            'each operation scope prompt key must be an int or non-empty string, \'\' given.',
        ];

        yield 'a resource keyed by an empty name' => [
            [],
            [],
            ['' => ['files:write']],
            'each operation scope resource key must be an int or non-empty string, \'\' given.',
        ];

        yield 'a tool entry that is not a list' => [
            ['deploy' => ['a' => 'files:write']],
            [],
            [],
            'each operation scope tool entry must be a list of scopes, array given.',
        ];

        yield 'a prompt entry that is a string' => [
            [],
            ['review' => 'files:write'],
            [],
            'each operation scope prompt entry must be a list of scopes, string given.',
        ];

        yield 'a resource entry that is a string' => [
            [],
            [],
            ['file:///a' => 'files:write'],
            'each operation scope resource entry must be a list of scopes, string given.',
        ];

        yield 'a scope with a space' => [
            ['deploy' => ['files:read files:write']],
            [],
            [],
            'each operation scope must be an RFC 6749 scope-token, \'files:read files:write\' given.',
        ];

        yield 'an empty scope' => [
            ['deploy' => ['']],
            [],
            [],
            'each operation scope must be an RFC 6749 scope-token, \'\' given.',
        ];

        yield 'a resource template with adjacent expressions' => [
            [],
            [],
            ['file:///{a}{b}' => ['files:read']],
            'Operation scope resource URI template must include literal text between adjacent expressions, got "file:///{a}{b}".',
        ];
    }
}
