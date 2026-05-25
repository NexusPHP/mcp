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

namespace Nexus\Mcp\Tests\Server\Discovery;

use Nexus\Mcp\Core\Schema\Enum\TaskSupport;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Discovery\AttributeScanner;
use Nexus\Mcp\Server\Exception\UnsupportedVariadicParameterException;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\ReflectedPromptRenderer;
use Nexus\Mcp\Server\Resource\ReflectedResourceReader;
use Nexus\Mcp\Server\Resource\ReflectedTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Tool\ReflectedToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\DiscoverableServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AttributeScanner::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AttributeScannerTest extends TestCase
{
    public function testToolFallsBackToTheMethodName(): void
    {
        $tool = self::toolEntry('add')->tool;

        self::assertSame('Adds two integers.', $tool->description);
        self::assertSame(['a', 'b'], array_keys($tool->inputSchema['properties'] ?? []));
        self::assertSame(['a', 'b'], $tool->inputSchema['required'] ?? []);
    }

    public function testToolUsesAttributeMetadata(): void
    {
        $tool = self::toolEntry('greet_user')->tool;

        self::assertSame('Greeter', $tool->title);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertSame(TaskSupport::Optional, $tool->execution->taskSupport);
        self::assertSame(['category' => 'social'], $tool->meta->extras);
        self::assertNotNull($tool->outputSchema);
    }

    public function testToolInputSchemaExcludesTheInjectedContext(): void
    {
        $tool = self::toolEntry('greet_user')->tool;

        self::assertSame(['name'], array_keys($tool->inputSchema['properties'] ?? []));
        self::assertSame(['name'], $tool->inputSchema['required'] ?? []);
    }

    public function testToolWithoutMetadataUsesDefaults(): void
    {
        $tool = self::toolEntry('add')->tool;

        self::assertNull($tool->annotations->readOnlyHint);
        self::assertNull($tool->execution->taskSupport);
        self::assertSame([], $tool->meta->extras);
        self::assertNull($tool->outputSchema);
    }

    public function testPromptDerivesArgumentsFromParameters(): void
    {
        $prompt = self::promptEntry('compose')->prompt;

        $topic = self::argument($prompt, 0);
        self::assertSame('topic', $topic->name);
        self::assertTrue($topic->required);
        self::assertSame('The subject to write about.', $topic->description);

        $tone = self::argument($prompt, 1);
        self::assertSame('tone', $tone->name);
        self::assertFalse($tone->required);
        self::assertSame('The desired tone.', $tone->description);
    }

    public function testPromptFallsBackToMethodNameAndOmitsContextAndBlankDescriptions(): void
    {
        $prompt = self::promptEntry('outline')->prompt;

        self::assertNotNull($prompt->arguments);
        self::assertCount(1, $prompt->arguments);

        $subject = self::argument($prompt, 0);
        self::assertSame('subject', $subject->name);
        self::assertTrue($subject->required);
        self::assertNull($subject->description);
    }

    public function testPromptWithOnlyInjectedParametersHasNoArguments(): void
    {
        self::assertNull(self::promptEntry('ping_prompt')->prompt->arguments);
    }

    public function testPromptArgumentsFollowingAnInjectedParameterAreKept(): void
    {
        $prompt = self::promptEntry('labelled')->prompt;

        self::assertNotNull($prompt->arguments);
        self::assertCount(1, $prompt->arguments);
        self::assertSame('label', self::argument($prompt, 0)->name);
    }

    public function testResourceUsesAttributeMetadata(): void
    {
        $resource = self::resourceEntry('app_config')->resource;

        self::assertSame('config://app', $resource->uri);
        self::assertSame('App Config', $resource->title);
        self::assertSame('Application configuration.', $resource->description);
        self::assertSame('application/json', $resource->mimeType);
        self::assertSame(0.5, $resource->annotations->priority);
        self::assertSame(128.0, $resource->size);
        self::assertSame(['cacheable' => true], $resource->meta->extras);
    }

    public function testResourceFallsBackToTheMethodName(): void
    {
        $resource = self::resourceEntry('defaults')->resource;

        self::assertSame('config://defaults', $resource->uri);
        self::assertNull($resource->mimeType);
        self::assertNull($resource->annotations->priority);
    }

    public function testResourceTemplateUsesAttributeMetadata(): void
    {
        $template = self::templateEntry('user_profile')->template;

        self::assertSame('users://{id}', $template->uriTemplate);
        self::assertSame('A user profile.', $template->description);
        self::assertSame(0.7, $template->annotations->priority);
    }

    public function testResourceTemplateFallsBackToTheMethodName(): void
    {
        $template = self::templateEntry('fileTemplate')->template;

        self::assertSame('files://{path}', $template->uriTemplate);
        self::assertNull($template->annotations->priority);
    }

    public function testEntriesCarryTheirReflectedAdapters(): void
    {
        self::assertInstanceOf(ReflectedToolExecutor::class, self::toolEntry('add')->executor);
        self::assertInstanceOf(ReflectedPromptRenderer::class, self::promptEntry('compose')->renderer);
        self::assertInstanceOf(ReflectedResourceReader::class, self::resourceEntry('app_config')->reader);
        self::assertInstanceOf(ReflectedTemplatedResourceReader::class, self::templateEntry('user_profile')->reader);
    }

    public function testMethodsWithoutAttributesAreSkipped(): void
    {
        $kinds = ['tools' => 0, 'prompts' => 0, 'resources' => 0, 'templates' => 0];

        foreach (self::entries() as $entry) {
            ++$kinds[match (true) {
                $entry instanceof ToolEntry => 'tools',
                $entry instanceof PromptEntry => 'prompts',
                $entry instanceof ResourceEntry => 'resources',
                default => 'templates',
            }];
        }

        self::assertSame(['tools' => 2, 'prompts' => 4, 'resources' => 2, 'templates' => 2], $kinds);
    }

    public function testNonPublicMethodsAreSkipped(): void
    {
        $toolNames = [];

        foreach (self::entries() as $entry) {
            if ($entry instanceof ToolEntry) {
                $toolNames[] = $entry->tool->name;
            }
        }

        self::assertNotContains('hidden', $toolNames);
    }

    public function testVariadicToolParameterIsAccepted(): void
    {
        $source = new class {
            #[AsTool(description: 'Joins parts.')]
            public function join(string ...$parts): string
            {
                return implode('', $parts);
            }
        };
        $entries = iterator_to_array(new AttributeScanner()->scan($source), false);

        self::assertCount(1, $entries);
        self::assertInstanceOf(ToolEntry::class, $entries[0]);
    }

    public function testVariadicPromptParameterIsRejected(): void
    {
        $this->expectException(UnsupportedVariadicParameterException::class);
        $this->expectExceptionMessageMatches('/^\S+\:\:brief\(\) declares a variadic parameter "\$topics"\./');

        $source = new class {
            #[AsPrompt(description: 'A prompt.')]
            public function brief(string ...$topics): string
            {
                return implode(', ', $topics);
            }
        };
        iterator_to_array(new AttributeScanner()->scan($source), false);
    }

    public function testVariadicResourceParameterIsRejected(): void
    {
        $this->expectException(UnsupportedVariadicParameterException::class);
        $this->expectExceptionMessageMatches('/^\S+\:\:notes\(\) declares a variadic parameter "\$ids"\./');

        $source = new class {
            #[AsResource(uri: 'mem://notes')]
            public function notes(string ...$ids): string
            {
                return implode(',', $ids);
            }
        };
        iterator_to_array(new AttributeScanner()->scan($source), false);
    }

    public function testVariadicResourceTemplateParameterIsRejected(): void
    {
        $this->expectException(UnsupportedVariadicParameterException::class);
        $this->expectExceptionMessageMatches('/^\S+\:\:note\(\) declares a variadic parameter "\$ids"\./');

        $source = new class {
            #[AsResourceTemplate(uriTemplate: 'mem://notes/{id}')]
            public function note(string ...$ids): string
            {
                return implode(',', $ids);
            }
        };
        iterator_to_array(new AttributeScanner()->scan($source), false);
    }

    /**
     * @return list<PromptEntry|ResourceEntry|ResourceTemplateEntry|ToolEntry>
     */
    private static function entries(): array
    {
        return iterator_to_array(new AttributeScanner()->scan(new DiscoverableServer()), false);
    }

    private static function toolEntry(string $name): ToolEntry
    {
        foreach (self::entries() as $entry) {
            if ($entry instanceof ToolEntry && $entry->tool->name === $name) {
                return $entry;
            }
        }

        self::fail(\sprintf('No tool "%s" discovered.', $name));
    }

    private static function promptEntry(string $name): PromptEntry
    {
        foreach (self::entries() as $entry) {
            if ($entry instanceof PromptEntry && $entry->prompt->name === $name) {
                return $entry;
            }
        }

        self::fail(\sprintf('No prompt "%s" discovered.', $name));
    }

    private static function resourceEntry(string $name): ResourceEntry
    {
        foreach (self::entries() as $entry) {
            if ($entry instanceof ResourceEntry && $entry->resource->name === $name) {
                return $entry;
            }
        }

        self::fail(\sprintf('No resource "%s" discovered.', $name));
    }

    private static function templateEntry(string $name): ResourceTemplateEntry
    {
        foreach (self::entries() as $entry) {
            if ($entry instanceof ResourceTemplateEntry && $entry->template->name === $name) {
                return $entry;
            }
        }

        self::fail(\sprintf('No resource template "%s" discovered.', $name));
    }

    private static function argument(Prompt $prompt, int $index): PromptArgument
    {
        $argument = ($prompt->arguments ?? [])[$index] ?? null;

        if (! $argument instanceof PromptArgument) {
            self::fail(\sprintf('Prompt "%s" has no argument at index %d.', $prompt->name, $index));
        }

        return $argument;
    }
}
