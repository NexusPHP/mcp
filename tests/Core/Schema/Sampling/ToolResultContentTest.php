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
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Sampling\ToolResultContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolResultContent::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolResultContentTest extends TestCase
{
    public function testConstruction(): void
    {
        $content = new ToolResultContent(toolUseId: 'tu-1', content: [new TextContent(text: 'Sunny, 22°C')]);

        self::assertSame('tu-1', $content->toolUseId);
        self::assertCount(1, $content->content);
        self::assertNull($content->isError);
        self::assertNull($content->structuredContent);
        self::assertSame([], $content->meta->toArray());
    }

    public function testConstructorRejectsEmptyToolUseId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content.toolUseId" must be a non-empty string.');

        new ToolResultContent(toolUseId: '', content: []);
    }

    public function testConstructorRejectsNonContentBlockEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Value "\'not-a-block\'" in iterable is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Arrayable\' but got string instead.');

        new ToolResultContent(toolUseId: 'tu-1', content: ['not-a-block']); // @phpstan-ignore argument.type
    }

    public function testToArrayMinimal(): void
    {
        $content = new ToolResultContent(toolUseId: 'tu-1', content: [new TextContent(text: 'hi')]);

        self::assertSame(
            [
                'content' => [['text' => 'hi', 'type' => 'text']],
                'toolUseId' => 'tu-1',
                'type' => 'tool_result',
            ],
            $content->toArray(),
        );
    }

    public function testToArrayFull(): void
    {
        $content = new ToolResultContent(toolUseId: 'tu-1', content: [new TextContent(text: 'hi')], isError: false, structuredContent: ['t' => 22], meta: new MetaObject(extras: ['trace_id' => 'abc']));

        self::assertSame(
            [
                'content' => [['text' => 'hi', 'type' => 'text']],
                'toolUseId' => 'tu-1',
                'type' => 'tool_result',
                'isError' => false,
                'structuredContent' => ['t' => 22],
                '_meta' => ['trace_id' => 'abc'],
            ],
            $content->toArray(),
        );
    }

    public function testJsonSerializeWrapsEmptyStructuredContent(): void
    {
        $content = new ToolResultContent(toolUseId: 'tu-1', content: [new TextContent(text: 'hi')], structuredContent: []);

        self::assertSame(
            '{"content":[{"text":"hi","type":"text"}],"toolUseId":"tu-1","type":"tool_result","structuredContent":{}}',
            json_encode($content),
        );
    }

    public function testJsonSerializeKeepsNonEmptyStructuredContent(): void
    {
        $content = new ToolResultContent(toolUseId: 'tu-1', content: [new TextContent(text: 'hi')], structuredContent: ['t' => 22]);

        self::assertStringContainsString('"structuredContent":{"t":22}', (string) json_encode($content));
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ToolResultContent(toolUseId: 'tu-1', content: [new TextContent(text: 'hi')], isError: true, structuredContent: ['t' => 22]);

        $rebuilt = ToolResultContent::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayReadsMeta(): void
    {
        $content = ToolResultContent::fromArray([
            'type' => 'tool_result',
            'toolUseId' => 'tu-1',
            'content' => [['text' => 'hi', 'type' => 'text']],
            '_meta' => ['trace_id' => 'abc'],
        ]);

        self::assertSame(['trace_id' => 'abc'], $content->meta->toArray());
    }

    public function testFromArrayRejectsMissingType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content" missing the required "type" key.');

        ToolResultContent::fromArray(['toolUseId' => 'x', 'content' => []]);
    }

    public function testFromArrayRejectsWrongType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content.type" must be \'tool_result\', \'text\' given.');

        ToolResultContent::fromArray(['type' => 'text', 'toolUseId' => 'x', 'content' => []]);
    }

    public function testFromArrayRejectsMissingToolUseId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content" missing the required "toolUseId" key.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'content' => []]);
    }

    public function testFromArrayRejectsMissingContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content" missing the required "content" key.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x']);
    }

    public function testFromArrayRejectsNonListContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content.content" must be a list, string given.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => 'oops']);
    }

    public function testFromArrayRejectsNonObjectContentEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "content.content" must be an object, string given.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => ['oops']]);
    }

    public function testFromArrayRejectsNonBoolIsError(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content.isError" must be a bool or null, string given.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => [], 'isError' => 'oops']);
    }

    public function testFromArrayRejectsNonObjectStructuredContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content.structuredContent" must be an object, string given.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => [], 'structuredContent' => 'oops']);
    }

    public function testFromArrayRejectsListKeyedStructuredContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content.structuredContent" must be a string-keyed object.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => [], 'structuredContent' => ['x']]);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content._meta" must be an object, string given.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => [], '_meta' => 'oops']);
    }

    public function testFromArrayRejectsListKeyedMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"content._meta" must be a string-keyed object.');

        ToolResultContent::fromArray(['type' => 'tool_result', 'toolUseId' => 'x', 'content' => [], '_meta' => ['x']]);
    }
}
