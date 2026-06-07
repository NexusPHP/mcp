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
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Prompt::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PromptTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $prompt = new Prompt('code-review');

        self::assertSame('code-review', $prompt->name);
        self::assertNull($prompt->title);
        self::assertNull($prompt->description);
        self::assertNull($prompt->arguments);
        self::assertNull($prompt->icons);
        self::assertSame([], $prompt->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $prompt = new Prompt('code-review');

        self::assertSame(['name' => 'code-review'], $prompt->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $prompt = new Prompt(
            'code-review',
            'Code Review',
            'Reviews changes against the project guidelines.',
            [new PromptArgument('topic', 'Topic')],
            [new Icon('https://example.com/icon.png')],
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'name' => 'code-review',
                'title' => 'Code Review',
                'description' => 'Reviews changes against the project guidelines.',
                'arguments' => [['name' => 'topic', 'title' => 'Topic']],
                'icons' => [['src' => 'https://example.com/icon.png']],
                '_meta' => ['vendor' => 'x'],
            ],
            $prompt->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $prompt = new Prompt('code-review', 'Code Review', null, null, null, new MetaObject(['k' => 'v']));

        self::assertSame($prompt->toArray(), $prompt->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $prompt = Prompt::fromArray(['name' => 'code-review']);

        self::assertSame('code-review', $prompt->name);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $prompt = Prompt::fromArray([
            'name' => 'code-review',
            'title' => 'Code Review',
            'description' => 'desc',
            'arguments' => [['name' => 'topic']],
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('Code Review', $prompt->title);
        self::assertSame('desc', $prompt->description);
        self::assertNotNull($prompt->arguments);
        self::assertCount(1, $prompt->arguments);
        self::assertSame('topic', $prompt->arguments[0]->name);
        self::assertNotNull($prompt->icons);
        self::assertCount(1, $prompt->icons);
        self::assertSame('https://example.com/icon.png', $prompt->icons[0]->src);
        self::assertSame(['vendor' => 'x'], $prompt->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Prompt(
            'code-review',
            'Code Review',
            'desc',
            [new PromptArgument('topic', null, null, true)],
            [new Icon('https://example.com/icon.png')],
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = Prompt::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\Aprompt "name" must be 1-128 characters/');

        new Prompt('bad name');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('prompt "description" must be a non-empty string or null.');

        new Prompt('code-review', null, '');
    }

    public function testConstructorRejectsNonPromptArgumentEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new Prompt('code-review', null, null, [42]);
    }

    public function testConstructorRejectsNonIconEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new Prompt('code-review', null, null, null, [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        Prompt::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            [],
            'prompt missing the required "name" key.',
        ];

        yield 'name not a string' => [
            ['name' => 1],
            'prompt "name" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'code-review', 'title' => 1],
            'prompt "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'code-review', 'description' => 1],
            'prompt "description" must be a string or null, int given.',
        ];

        yield 'arguments not an array' => [
            ['name' => 'code-review', 'arguments' => 'oops'],
            'prompt "arguments" must be a list, string given.',
        ];

        yield 'argument entry not an object' => [
            ['name' => 'code-review', 'arguments' => ['oops']],
            'each prompt "arguments" must be an object, string given.',
        ];

        yield 'argument entry list-keyed' => [
            ['name' => 'code-review', 'arguments' => [['x']]],
            'each prompt "arguments" must be a string-keyed object.',
        ];

        yield 'icons not an array' => [
            ['name' => 'code-review', 'icons' => 'oops'],
            'prompt "icons" must be a list, string given.',
        ];

        yield 'icon entry not an object' => [
            ['name' => 'code-review', 'icons' => ['oops']],
            'each prompt "icons" must be an object, string given.',
        ];

        yield 'icon entry list-keyed' => [
            ['name' => 'code-review', 'icons' => [['x']]],
            'each prompt "icons" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['name' => 'code-review', '_meta' => 'oops'],
            'prompt "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'code-review', '_meta' => ['x']],
            'prompt "_meta" must be a string-keyed object.',
        ];
    }
}
