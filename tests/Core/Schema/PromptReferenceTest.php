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
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\PromptReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PromptReference::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PromptReferenceTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $reference = new PromptReference('my-prompt');

        self::assertSame('my-prompt', $reference->name);
        self::assertNull($reference->title);
    }

    public function testConstructionWithTitle(): void
    {
        $reference = new PromptReference('my-prompt', 'My Prompt');

        self::assertSame('my-prompt', $reference->name);
        self::assertSame('My Prompt', $reference->title);
    }

    public function testToArrayMinimal(): void
    {
        $reference = new PromptReference('my-prompt');

        self::assertSame(
            ['name' => 'my-prompt', 'type' => 'ref/prompt'],
            $reference->toArray(),
        );
    }

    public function testToArrayWithTitle(): void
    {
        $reference = new PromptReference('my-prompt', 'My Prompt');

        self::assertSame(
            ['name' => 'my-prompt', 'type' => 'ref/prompt', 'title' => 'My Prompt'],
            $reference->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $reference = new PromptReference('my-prompt', 'My Prompt');

        self::assertSame($reference->toArray(), $reference->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $reference = PromptReference::fromArray([
            'type' => 'ref/prompt',
            'name' => 'my-prompt',
        ]);

        self::assertSame('my-prompt', $reference->name);
        self::assertNull($reference->title);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $reference = PromptReference::fromArray([
            'type' => 'ref/prompt',
            'name' => 'my-prompt',
            'title' => 'My Prompt',
        ]);

        self::assertSame('my-prompt', $reference->name);
        self::assertSame('My Prompt', $reference->title);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new PromptReference('my-prompt', 'My Prompt');

        $rebuilt = PromptReference::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\APromptReference name must be 1-128 characters/');

        new PromptReference('my prompt');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        PromptReference::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['name' => 'my-prompt'],
            'PromptReference wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'ref/resource', 'name' => 'my-prompt'],
            'PromptReference wire "type" must be "ref/prompt", \'ref/resource\' given.',
        ];

        yield 'missing name' => [
            ['type' => 'ref/prompt'],
            'PromptReference wire data missing "name".',
        ];

        yield 'name not a string' => [
            ['type' => 'ref/prompt', 'name' => 1],
            'PromptReference wire "name" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['type' => 'ref/prompt', 'name' => 'my-prompt', 'title' => 1],
            'PromptReference wire "title" must be a string or null, int given.',
        ];
    }
}
