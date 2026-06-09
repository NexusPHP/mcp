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

namespace Nexus\Mcp\Tests\Core\Schema\Prompt;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PromptArgument::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PromptArgumentTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $arg = new PromptArgument(name: 'topic');

        self::assertSame('topic', $arg->name);
        self::assertNull($arg->title);
        self::assertNull($arg->description);
        self::assertNull($arg->required);
    }

    public function testConstructionWithAllFields(): void
    {
        $arg = new PromptArgument(name: 'topic', title: 'Topic', description: 'The topic of discussion.', required: true);

        self::assertSame('topic', $arg->name);
        self::assertSame('Topic', $arg->title);
        self::assertSame('The topic of discussion.', $arg->description);
        self::assertTrue($arg->required);
    }

    public function testToArrayMinimal(): void
    {
        $arg = new PromptArgument(name: 'topic');

        self::assertSame(['name' => 'topic'], $arg->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $arg = new PromptArgument(name: 'topic', title: 'Topic', description: 'The topic of discussion.', required: true);

        self::assertSame(
            [
                'name' => 'topic',
                'title' => 'Topic',
                'description' => 'The topic of discussion.',
                'required' => true,
            ],
            $arg->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $arg = new PromptArgument(name: 'topic', title: 'Topic', description: 'desc', required: false);

        self::assertSame($arg->toArray(), $arg->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $arg = PromptArgument::fromArray(['name' => 'topic']);

        self::assertSame('topic', $arg->name);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $arg = PromptArgument::fromArray([
            'name' => 'topic',
            'title' => 'Topic',
            'description' => 'desc',
            'required' => true,
        ]);

        self::assertSame('Topic', $arg->title);
        self::assertSame('desc', $arg->description);
        self::assertTrue($arg->required);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new PromptArgument(name: 'topic', title: 'Topic', description: 'desc', required: true);

        $rebuilt = PromptArgument::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\A"arguments.name" must be 1-128 characters/');

        new PromptArgument(name: 'bad name');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"arguments.description" must be a non-empty string or null.');

        new PromptArgument(name: 'topic', title: null, description: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        PromptArgument::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            [],
            '"arguments" missing the required "name" key.',
        ];

        yield 'name not a string' => [
            ['name' => 1],
            '"arguments.name" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'topic', 'title' => 1],
            '"arguments.title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'topic', 'description' => 1],
            '"arguments.description" must be a string or null, int given.',
        ];

        yield 'required not a bool' => [
            ['name' => 'topic', 'required' => 'yes'],
            '"arguments.required" must be a bool or null, string given.',
        ];
    }
}
