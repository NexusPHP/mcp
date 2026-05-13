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

namespace Nexus\Mcp\Tests\Core\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\IncludeContext;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Enum\ToolChoiceMode;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\CreateMessageRequestParams;
use Nexus\Mcp\Core\Schema\Sampling\ModelHint;
use Nexus\Mcp\Core\Schema\Sampling\ModelPreferences;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolChoice;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CreateMessageRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CreateMessageRequestParamsTest extends TestCase
{
    public function testMinimalConstruction(): void
    {
        $params = new CreateMessageRequestParams(
            maxTokens: 100,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
        );

        self::assertSame(100, $params->maxTokens);
        self::assertCount(1, $params->messages);
        self::assertNull($params->includeContext);
        self::assertNull($params->modelPreferences);
        self::assertNull($params->stopSequences);
        self::assertNull($params->systemPrompt);
        self::assertNull($params->task);
        self::assertNull($params->temperature);
        self::assertNull($params->toolChoice);
        self::assertNull($params->tools);
        self::assertNull($params->metadata);
        self::assertSame([], $params->meta->toArray());
    }

    public function testConstructorRejectsNegativeMaxTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams maxTokens must be a non-negative integer.');

        new CreateMessageRequestParams(maxTokens: -1, messages: []);
    }

    public function testConstructorAcceptsZeroMaxTokens(): void
    {
        $params = new CreateMessageRequestParams(maxTokens: 0, messages: []);

        self::assertSame(0, $params->maxTokens);
    }

    public function testConstructorRejectsNonSamplingMessageEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Value "\'not-a-message\'" in iterable is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Sampling\\\\SamplingMessage\' but got string instead.');

        new CreateMessageRequestParams(maxTokens: 1, messages: ['not-a-message']); // @phpstan-ignore argument.type
    }

    public function testConstructorRejectsEmptySystemPrompt(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams systemPrompt must be a non-empty string or null.');

        new CreateMessageRequestParams(maxTokens: 1, messages: [], systemPrompt: '');
    }

    public function testConstructorRejectsNonStringStopSequencesEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams stopSequences entries must be strings, int given.');

        new CreateMessageRequestParams(maxTokens: 1, messages: [], stopSequences: [42]); // @phpstan-ignore argument.type
    }

    public function testConstructorRejectsOutOfRangeTemperature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams temperature must be between 0.0 and 2.0.');

        new CreateMessageRequestParams(maxTokens: 1, messages: [], temperature: 3.0);
    }

    public function testConstructorAcceptsTemperatureBoundaries(): void
    {
        $low = new CreateMessageRequestParams(maxTokens: 1, messages: [], temperature: 0.0);
        $high = new CreateMessageRequestParams(maxTokens: 1, messages: [], temperature: 2.0);

        self::assertSame(0.0, $low->temperature);
        self::assertSame(2.0, $high->temperature);
    }

    public function testConstructorRejectsNonToolEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Value "\'not-a-tool\'" in iterable is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Tool\\\\Tool\' but got string instead.');

        new CreateMessageRequestParams(maxTokens: 1, messages: [], tools: ['not-a-tool']); // @phpstan-ignore argument.type
    }

    public function testToArrayMinimal(): void
    {
        $params = new CreateMessageRequestParams(maxTokens: 100, messages: [new SamplingMessage(Role::User, new TextContent('hi'))]);

        self::assertSame(
            [
                'maxTokens' => 100,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
                ],
            ],
            $params->toArray(),
        );
    }

    public function testToArrayWithEveryField(): void
    {
        $params = new CreateMessageRequestParams(
            maxTokens: 100,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            includeContext: IncludeContext::ThisServer,
            modelPreferences: new ModelPreferences([new ModelHint('sonnet')]),
            stopSequences: ['STOP'],
            systemPrompt: 'Be concise.',
            task: new TaskMetadata(60000),
            temperature: 0.7,
            toolChoice: new ToolChoice(ToolChoiceMode::Auto),
            tools: [new Tool('search', ['type' => 'object'])],
            metadata: ['vendor' => 'acme'],
        );

        self::assertSame(
            [
                'maxTokens' => 100,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
                ],
                'includeContext' => 'thisServer',
                'modelPreferences' => ['hints' => [['name' => 'sonnet']]],
                'stopSequences' => ['STOP'],
                'systemPrompt' => 'Be concise.',
                'task' => ['ttl' => 60000],
                'temperature' => 0.7,
                'toolChoice' => ['mode' => 'auto'],
                'tools' => [['name' => 'search', 'inputSchema' => ['type' => 'object']]],
                'metadata' => ['vendor' => 'acme'],
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeWrapsEmptyMetadata(): void
    {
        $params = new CreateMessageRequestParams(
            maxTokens: 1,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            metadata: [],
        );

        self::assertStringContainsString('"metadata":{}', (string) json_encode($params));
    }

    public function testToArrayIncludesMetaFromParentRequestParams(): void
    {
        $params = new CreateMessageRequestParams(
            maxTokens: 1,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            meta: new RequestMetaObject(extras: ['vendor' => 'acme']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'acme'],
                'maxTokens' => 1,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
                ],
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeConvertsMessagesToArrays(): void
    {
        $params = new CreateMessageRequestParams(
            maxTokens: 1,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
        );

        self::assertSame(
            [
                'maxTokens' => 1,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
                ],
            ],
            $params->jsonSerialize(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CreateMessageRequestParams(
            maxTokens: 100,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            includeContext: IncludeContext::AllServers,
            modelPreferences: new ModelPreferences([new ModelHint('sonnet')]),
            stopSequences: ['STOP'],
            systemPrompt: 'Be concise.',
            task: new TaskMetadata(60000),
            temperature: 0.7,
            toolChoice: new ToolChoice(ToolChoiceMode::Auto),
            tools: [new Tool('search', ['type' => 'object'])],
            metadata: ['vendor' => 'acme'],
        );

        $rebuilt = CreateMessageRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingMaxTokens(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams data missing "maxTokens".');

        CreateMessageRequestParams::fromArray(['messages' => []]);
    }

    public function testFromArrayRejectsNonIntMaxTokens(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "maxTokens" must be an int, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 'oops', 'messages' => []]);
    }

    public function testFromArrayRejectsMissingMessages(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams data missing "messages".');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1]);
    }

    public function testFromArrayRejectsNonListMessages(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "messages" must be a list, got non-list array.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => ['x' => 1]]);
    }

    public function testFromArrayRejectsUnknownIncludeContext(): void
    {
        $this->expectException(\ValueError::class);

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'includeContext' => 'unknown']);
    }

    public function testFromArrayRejectsNonStringIncludeContext(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "includeContext" must be a string, int given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'includeContext' => 0]);
    }

    public function testFromArrayRejectsNonObjectModelPreferences(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "modelPreferences" must be an object, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'modelPreferences' => 'oops']);
    }

    public function testFromArrayRejectsNonListStopSequences(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "stopSequences" must be a list, got non-list array.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'stopSequences' => ['x' => 1]]);
    }

    public function testFromArrayRejectsNonStringStopSequencesEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams stopSequences entry must be a string, int given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'stopSequences' => [42]]);
    }

    public function testFromArrayRejectsNonObjectTask(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "task" must be an object, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'task' => 'oops']);
    }

    public function testFromArrayRejectsNonNumericTemperature(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "temperature" must be a number or null, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'temperature' => 'hot']);
    }

    public function testFromArrayRejectsNonListTools(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "tools" must be a list, got non-list array.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'tools' => ['x' => 1]]);
    }

    public function testFromArrayRejectsNonObjectMetadata(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageRequestParams "metadata" must be an object, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'metadata' => 'oops']);
    }
}
