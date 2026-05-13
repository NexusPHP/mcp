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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializeResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InitializeResultTest extends TestCase
{
    public function testConstructionExposesFields(): void
    {
        $result = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(prompts: ['listChanged' => true]),
            new Implementation('server', '1.2.3'),
            'Use these tools wisely.',
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame('2025-11-25', $result->protocolVersion->version);
        self::assertSame(['listChanged' => true], $result->capabilities->prompts);
        self::assertSame('server', $result->serverInfo->name);
        self::assertSame('Use these tools wisely.', $result->instructions);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testConstructionDefaultsOptionalFieldsToNull(): void
    {
        $result = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(),
            new Implementation('server', '1.0.0'),
        );

        self::assertNull($result->instructions);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionRejectsEmptyInstructions(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('InitializeResult instructions must be a non-empty string or null.');

        new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(),
            new Implementation('server', '1.0.0'),
            '',
        );
    }

    public function testToArrayEmitsRequiredFields(): void
    {
        $result = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(prompts: ['listChanged' => true]),
            new Implementation('server', '1.0.0'),
        );

        self::assertSame(
            [
                'protocolVersion' => '2025-11-25',
                'capabilities' => ['prompts' => ['listChanged' => true]],
                'serverInfo' => ['name' => 'server', 'version' => '1.0.0'],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesInstructionsAndMeta(): void
    {
        $result = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(),
            new Implementation('server', '1.0.0'),
            'hint',
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'serverInfo' => ['name' => 'server', 'version' => '1.0.0'],
                'instructions' => 'hint',
            ],
            $result->toArray(),
        );
    }

    public function testJsonEncodeSubstitutesStdClassForEmptyCapabilitySlots(): void
    {
        $result = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(prompts: [], tools: []),
            new Implementation('server', '1.0.0'),
        );

        $json = json_encode($result);

        self::assertIsString($json);
        self::assertStringContainsString('"prompts":{}', $json);
        self::assertStringContainsString('"tools":{}', $json);
        self::assertStringNotContainsString('"prompts":[]', $json);
        self::assertStringNotContainsString('"tools":[]', $json);
        self::assertStringNotContainsString('"instructions"', $json);
    }

    public function testJsonSerializeIncludesMetaAndInstructions(): void
    {
        $result = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(),
            new Implementation('server', '1.0.0'),
            'hint',
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            '{"_meta":{"vendor":"x"},"protocolVersion":"2025-11-25","capabilities":{},"serverInfo":{"name":"server","version":"1.0.0"},"instructions":"hint"}',
            json_encode($result, \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new InitializeResult(
            new ProtocolVersion('2025-11-25'),
            new ServerCapabilities(prompts: ['listChanged' => true]),
            new Implementation('server', '1.2.3'),
            'instructions',
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = InitializeResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        InitializeResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing protocolVersion' => [
            ['capabilities' => [], 'serverInfo' => ['name' => 's', 'version' => '1']],
            'InitializeResult data missing "protocolVersion".',
        ];

        yield 'protocolVersion not a string' => [
            ['protocolVersion' => 1, 'capabilities' => [], 'serverInfo' => ['name' => 's', 'version' => '1']],
            'InitializeResult "protocolVersion" must be a string, int given.',
        ];

        yield 'missing capabilities' => [
            ['protocolVersion' => '2025-11-25', 'serverInfo' => ['name' => 's', 'version' => '1']],
            'InitializeResult data missing "capabilities".',
        ];

        yield 'capabilities not an object' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => 'oops', 'serverInfo' => ['name' => 's', 'version' => '1']],
            'InitializeResult "capabilities" must be an object, string given.',
        ];

        yield 'missing serverInfo' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => []],
            'InitializeResult data missing "serverInfo".',
        ];

        yield 'serverInfo not an object' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'serverInfo' => 'oops'],
            'InitializeResult "serverInfo" must be an object, string given.',
        ];

        yield 'instructions not a string' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'serverInfo' => ['name' => 's', 'version' => '1'], 'instructions' => 1],
            'InitializeResult "instructions" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'serverInfo' => ['name' => 's', 'version' => '1'], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];
    }
}
