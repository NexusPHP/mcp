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
use Nexus\Mcp\Core\Schema\RequestParams\CreateMessageRequestParams;
use Nexus\Mcp\Core\Schema\Sampling\ModelHint;
use Nexus\Mcp\Core\Schema\Sampling\ModelPreferences;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolChoice;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CreateMessageRequestParams::class)]
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
        self::assertSame([], $params->modelPreferences->toArray());
        self::assertNull($params->stopSequences);
        self::assertNull($params->systemPrompt);
        self::assertNull($params->temperature);
        self::assertSame([], $params->toolChoice->toArray());
        self::assertNull($params->tools);
        self::assertNull($params->metadata);
    }

    public function testConstructorAcceptsAnyIntMaxTokens(): void
    {
        $negative = new CreateMessageRequestParams(maxTokens: -1, messages: []);
        $zero = new CreateMessageRequestParams(maxTokens: 0, messages: []);

        self::assertSame(-1, $negative->maxTokens);
        self::assertSame(0, $zero->maxTokens);
    }

    public function testConstructorRejectsNonSamplingMessageEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Value "\'not-a-message\'" in iterable is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Sampling\\\\SamplingMessage\' but got string instead.');

        new CreateMessageRequestParams(maxTokens: 1, messages: ['not-a-message']); // @phpstan-ignore argument.type
    }

    public function testConstructorRejectsEmptySystemPrompt(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.systemPrompt" must be a non-empty string or null.');

        new CreateMessageRequestParams(maxTokens: 1, messages: [], systemPrompt: '');
    }

    public function testConstructorRejectsNonStringStopSequencesEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.stopSequences" entry must be a string, int given.');

        new CreateMessageRequestParams(maxTokens: 1, messages: [], stopSequences: [42]); // @phpstan-ignore argument.type
    }

    public function testConstructorAcceptsAnyTemperature(): void
    {
        $params = new CreateMessageRequestParams(maxTokens: 1, messages: [], temperature: 2.5);

        self::assertSame(2.5, $params->temperature);
    }

    public function testConstructorRejectsNonToolEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Value "\'not-a-tool\'" in iterable is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Tool\\\\Tool\' but got string instead.');

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

    public function testJsonSerializeIncludesModelPreferencesAndToolChoice(): void
    {
        $params = new CreateMessageRequestParams(
            maxTokens: 1,
            messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            modelPreferences: new ModelPreferences([new ModelHint('sonnet')]),
            toolChoice: new ToolChoice(ToolChoiceMode::Auto),
        );

        self::assertSame(
            [
                'maxTokens' => 1,
                'messages' => [
                    ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
                ],
                'modelPreferences' => ['hints' => [['name' => 'sonnet']]],
                'toolChoice' => ['mode' => 'auto'],
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
        $this->expectExceptionMessageIs('missing the required "maxTokens" key.');

        CreateMessageRequestParams::fromArray(['messages' => []]);
    }

    public function testFromArrayRejectsNonIntMaxTokens(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.maxTokens" must be an int, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 'oops', 'messages' => []]);
    }

    public function testFromArrayRejectsMissingMessages(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('missing the required "messages" key.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1]);
    }

    public function testFromArrayRejectsNonListMessages(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.messages" must be a list, non-list array given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => ['x' => 1]]);
    }

    public function testFromArrayRejectsUnknownIncludeContext(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.includeContext" must be one of [\'allServers\', \'none\', \'thisServer\'], \'unknown\' given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'includeContext' => 'unknown']);
    }

    public function testFromArrayRejectsNonStringIncludeContext(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.includeContext" must be one of [\'allServers\', \'none\', \'thisServer\'], 0 given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'includeContext' => 0]);
    }

    public function testFromArrayRejectsNonObjectModelPreferences(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.modelPreferences" must be an object, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'modelPreferences' => 'oops']);
    }

    public function testFromArrayRejectsNonListStopSequences(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.stopSequences" must be a list, non-list array given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'stopSequences' => ['x' => 1]]);
    }

    public function testFromArrayRejectsNonStringStopSequencesEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.stopSequences" must be a string, int given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'stopSequences' => [42]]);
    }

    public function testFromArrayRejectsNonNumericTemperature(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.temperature" must be a number or null, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'temperature' => 'hot']);
    }

    public function testFromArrayRejectsNonListTools(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.tools" must be a list, non-list array given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'tools' => ['x' => 1]]);
    }

    public function testFromArrayRejectsNonObjectMetadata(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.metadata" must be an object, string given.');

        CreateMessageRequestParams::fromArray(['maxTokens' => 1, 'messages' => [], 'metadata' => 'oops']);
    }
}
