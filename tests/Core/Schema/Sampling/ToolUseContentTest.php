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

namespace Nexus\Mcp\Tests\Core\Schema\Sampling;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolUseContent::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolUseContentTest extends TestCase
{
    public function testConstruction(): void
    {
        $content = new ToolUseContent('tu-1', 'get_weather', ['city' => 'Paris']);

        self::assertSame('tu-1', $content->id);
        self::assertSame('get_weather', $content->name);
        self::assertSame(['city' => 'Paris'], $content->input);
        self::assertSame([], $content->meta->toArray());
    }

    public function testConstructorRejectsEmptyId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content.id" must be a non-empty string.');

        new ToolUseContent('', 'name', []);
    }

    public function testConstructorRejectsEmptyName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content.name" must be a non-empty string.');

        new ToolUseContent('id', '', []);
    }

    public function testToArray(): void
    {
        $content = new ToolUseContent('tu-1', 'get_weather', ['city' => 'Paris']);

        self::assertSame(
            ['id' => 'tu-1', 'input' => ['city' => 'Paris'], 'name' => 'get_weather', 'type' => 'tool_use'],
            $content->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $content = new ToolUseContent('tu-1', 'get_weather', ['city' => 'Paris'], new MetaObject(extras: ['trace_id' => 'abc']));

        self::assertSame(
            ['id' => 'tu-1', 'input' => ['city' => 'Paris'], 'name' => 'get_weather', 'type' => 'tool_use', '_meta' => ['trace_id' => 'abc']],
            $content->toArray(),
        );
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $content = new ToolUseContent('tu-1', 'get_weather', [], new MetaObject());

        self::assertSame(
            ['id' => 'tu-1', 'input' => [], 'name' => 'get_weather', 'type' => 'tool_use'],
            $content->toArray(),
        );
    }

    public function testJsonSerializeWrapsEmptyInput(): void
    {
        $content = new ToolUseContent('tu-1', 'get_weather', []);

        self::assertSame(
            '{"id":"tu-1","input":{},"name":"get_weather","type":"tool_use"}',
            json_encode($content),
        );
    }

    public function testJsonSerializeKeepsNonEmptyInput(): void
    {
        $content = new ToolUseContent('tu-1', 'get_weather', ['city' => 'Paris']);

        self::assertSame(
            '{"id":"tu-1","input":{"city":"Paris"},"name":"get_weather","type":"tool_use"}',
            json_encode($content),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ToolUseContent('tu-1', 'get_weather', ['city' => 'Paris']);

        $rebuilt = ToolUseContent::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content" missing the required "type" key.');

        ToolUseContent::fromArray(['id' => 'tu-1', 'name' => 'x', 'input' => []]);
    }

    public function testFromArrayRejectsWrongType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content.type" must be \'tool_use\', \'text\' given.');

        ToolUseContent::fromArray(['type' => 'text', 'id' => 'tu-1', 'name' => 'x', 'input' => []]);
    }

    public function testFromArrayRejectsMissingId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content" missing the required "id" key.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'name' => 'x', 'input' => []]);
    }

    public function testFromArrayRejectsMissingName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content" missing the required "name" key.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'tu-1', 'input' => []]);
    }

    public function testFromArrayRejectsMissingInput(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content" missing the required "input" key.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'x']);
    }

    public function testFromArrayRejectsNonObjectInput(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content.input" must be an object, string given.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'x', 'input' => 'oops']);
    }

    public function testFromArrayRejectsListKeyedInput(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content.input" must be a string-keyed object.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'x', 'input' => ['a']]);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content._meta" must be an object, string given.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'x', 'input' => [], '_meta' => 'oops']);
    }

    public function testFromArrayRejectsListKeyedMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"content._meta" must be a string-keyed object.');

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'x', 'input' => [], '_meta' => ['x']]);
    }
}
