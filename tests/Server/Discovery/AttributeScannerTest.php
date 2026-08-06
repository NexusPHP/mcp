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

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptArgument;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Attribute\AsCompletion;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsResource;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\Completion\PromptCompletionEntry;
use Nexus\Mcp\Server\Completion\ResourceTemplateCompletionEntry;
use Nexus\Mcp\Server\Discovery\AttributeScanner;
use Nexus\Mcp\Server\Exception\InvalidCompletionAttributeException;
use Nexus\Mcp\Server\Exception\UnsupportedParameterTypeException;
use Nexus\Mcp\Server\Exception\UnsupportedVariadicParameterException;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\ReflectedPromptRenderer;
use Nexus\Mcp\Server\Resource\ReflectedResourceReader;
use Nexus\Mcp\Server\Resource\ReflectedTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ReflectedToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\CompletionHandlers;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\DiscoverableServer;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AttributeScanner::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AttributeScannerTest extends AbstractMcpTestCase
{
    private const string REJECTION_PATTERN = '/^\S+\:\:rank\(\) declares parameter "\$level" of unsupported type "%s"\. It is bound from a string value\./';

    public function testToolFallsBackToTheMethodName(): void
    {
        $tool = self::toolEntry('add')->tool;

        self::assertSame('Adds two integers.', $tool->description);

        $properties = $tool->inputSchema['properties'] ?? [];
        self::assertIsArray($properties);
        self::assertSame(['a', 'b'], array_keys($properties));
        self::assertSame(['a', 'b'], $tool->inputSchema['required'] ?? []);
    }

    public function testToolUsesAttributeMetadata(): void
    {
        $tool = self::toolEntry('greet_user')->tool;

        self::assertSame('Greeter', $tool->title);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertSame(['category' => 'social'], $tool->meta->extras);
        self::assertNotNull($tool->outputSchema);
    }

    public function testToolInputSchemaExcludesTheInjectedContext(): void
    {
        $tool = self::toolEntry('greet_user')->tool;

        $properties = $tool->inputSchema['properties'] ?? [];
        self::assertIsArray($properties);
        self::assertSame(['name'], array_keys($properties));
        self::assertSame(['name'], $tool->inputSchema['required'] ?? []);
    }

    public function testToolWithoutMetadataUsesDefaults(): void
    {
        $tool = self::toolEntry('add')->tool;

        self::assertNull($tool->annotations->readOnlyHint);
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
        self::assertSame(128, $resource->size);
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
        $entries = iterator_to_array((new AttributeScanner())->scan($source), false);

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
        iterator_to_array((new AttributeScanner())->scan($source), false);
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
        iterator_to_array((new AttributeScanner())->scan($source), false);
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
        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    #[DataProvider('provideSupportedPromptParameterTypeIsAcceptedCases')]
    public function testSupportedPromptParameterTypeIsAccepted(object $source): void
    {
        $entries = iterator_to_array((new AttributeScanner())->scan($source), false);

        self::assertCount(1, $entries);
        self::assertInstanceOf(PromptEntry::class, $entries[0]);
        self::assertSame('level', $entries[0]->prompt->arguments[0]->name ?? null);
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function provideSupportedPromptParameterTypeIsAcceptedCases(): iterable
    {
        yield 'string' => [new class {
            #[AsPrompt(description: 'A prompt.')]
            public function rank(string $level): string
            {
                return $level;
            }
        }];

        yield 'mixed' => [new class {
            #[AsPrompt(description: 'A prompt.')]
            public function rank(mixed $level): string
            {
                return \is_string($level) ? $level : '';
            }
        }];

        yield 'pure enum' => [new class {
            #[AsPrompt(description: 'A prompt.')]
            public function rank(PureEnum $level): string
            {
                return $level->name;
            }
        }];

        yield 'string-backed enum' => [new class {
            #[AsPrompt(description: 'A prompt.')]
            public function rank(BackedStringEnum $level): string
            {
                return $level->value;
            }
        }];

        yield 'union with string' => [new class {
            #[AsPrompt(description: 'A prompt.')]
            public function rank(int|string $level): string
            {
                return (string) $level;
            }
        }];
    }

    #[DataProvider('provideUnsupportedPromptParameterTypeIsRejectedCases')]
    public function testUnsupportedPromptParameterTypeIsRejected(object $source, string $pattern): void
    {
        $this->expectException(UnsupportedParameterTypeException::class);
        $this->expectExceptionMessageMatches($pattern);

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    /**
     * @return iterable<string, array{object, string}>
     */
    public static function provideUnsupportedPromptParameterTypeIsRejectedCases(): iterable
    {
        yield 'int scalar' => [
            new class {
                #[AsPrompt(description: 'A prompt.')]
                public function rank(int $level): string
                {
                    return (string) $level;
                }
            },
            \sprintf(self::REJECTION_PATTERN, preg_quote('int', '/')),
        ];

        yield 'int-backed enum' => [
            new class {
                #[AsPrompt(description: 'A prompt.')]
                public function rank(BackedIntEnum $level): string
                {
                    return (string) $level->value;
                }
            },
            \sprintf(self::REJECTION_PATTERN, preg_quote(BackedIntEnum::class, '/')),
        ];

        yield 'non-enum class' => [
            new class {
                #[AsPrompt(description: 'A prompt.')]
                public function rank(\DateTimeImmutable $level): string
                {
                    return $level->format('c');
                }
            },
            \sprintf(self::REJECTION_PATTERN, preg_quote(\DateTimeImmutable::class, '/')),
        ];

        yield 'intersection' => [
            new class {
                #[AsPrompt(description: 'A prompt.')]
                public function rank(\Countable&\Stringable $level): string
                {
                    return (string) $level;
                }
            },
            \sprintf(self::REJECTION_PATTERN, preg_quote(\Countable::class.'&'.\Stringable::class, '/')),
        ];

        yield 'union without string' => [
            new class {
                #[AsPrompt(description: 'A prompt.')]
                public function rank(float|int $level): string
                {
                    return (string) $level;
                }
            },
            \sprintf(self::REJECTION_PATTERN, preg_quote('int|float', '/')),
        ];
    }

    public function testUnsupportedResourceParameterTypeIsRejected(): void
    {
        $this->expectException(UnsupportedParameterTypeException::class);
        $this->expectExceptionMessageMatches('/^\S+\:\:config\(\) declares parameter "\$uri" of unsupported type "int"\. It is bound from a string value\./');

        $source = new class {
            #[AsResource(uri: 'mem://config')]
            public function config(int $uri): string
            {
                return (string) $uri;
            }
        };
        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testUnsupportedResourceTemplateParameterTypeIsRejected(): void
    {
        $this->expectException(UnsupportedParameterTypeException::class);
        $this->expectExceptionMessageMatches('/^\S+\:\:profile\(\) declares parameter "\$id" of unsupported type "int"\. It is bound from a string value\./');

        $source = new class {
            #[AsResourceTemplate(uriTemplate: 'users://{id}')]
            public function profile(string $uri, int $id): string
            {
                return $uri.$id;
            }
        };
        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testCompletionForAPromptArgumentIsDiscovered(): void
    {
        $entry = self::promptCompletionEntry('compose', 'tone');

        $result = $entry->provider->complete('f', null, self::makeContext());

        self::assertSame(['formal', 'friendly'], $result->completion['values']);
    }

    public function testCompletionForATemplateVariableIsDiscovered(): void
    {
        $entries = iterator_to_array((new AttributeScanner())->scan(new CompletionHandlers()), false);

        $templateCompletions = array_values(array_filter(
            $entries,
            static fn(object $entry): bool => $entry instanceof ResourceTemplateCompletionEntry,
        ));

        self::assertCount(1, $templateCompletions);
        self::assertSame('file:///{path}', $templateCompletions[0]->uriTemplate);
        self::assertSame('path', $templateCompletions[0]->argument);

        $result = $templateCompletions[0]->provider->complete('report.csv', null, self::makeContext());

        self::assertSame(['report.csv'], $result->completion['values']);
        self::assertFalse($result->completion['hasMore'] ?? null);
    }

    public function testRepeatedCompletionAttributesEachYieldAnEntry(): void
    {
        $entry = self::promptCompletionEntry('compose', 'topic');

        $result = $entry->provider->complete('anything', null, self::makeContext());

        self::assertSame(['anything'], $result->completion['values']);
    }

    public function testCompletionParametersMayBeUntypedMixedOrAStringBearingUnion(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            // @phpstan-ignore missingType.parameter (deliberately untyped to exercise the null-ReflectionType branch)
            #[AsCompletion(argument: 'a', prompt: 'p')]
            public function untyped($value): array
            {
                return [\is_string($value) ? 'untyped-ok' : 'untyped-bad'];
            }

            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'b', prompt: 'p')]
            public function union(int|string $value): array
            {
                return [\is_string($value) ? 'union-ok' : 'union-bad'];
            }

            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'c', prompt: 'p')]
            public function mixedParam(mixed $value): array
            {
                return [\is_string($value) ? 'mixed-ok' : 'mixed-bad'];
            }
        };

        $values = [];

        foreach ((new AttributeScanner())->scan($source) as $entry) {
            if ($entry instanceof PromptCompletionEntry) {
                $values[] = $entry->provider->complete('x', null, self::makeContext())->completion['values'][0] ?? null;
            }
        }

        self::assertSame(['untyped-ok', 'union-ok', 'mixed-ok'], $values);
    }

    public function testCompletionMethodWithANonStringParameterIsRejected(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x', prompt: 'p')]
            public function complete(string $value, int $limit): array
            {
                return [$value, (string) $limit];
            }
        };

        $this->expectException(UnsupportedParameterTypeException::class);
        $this->expectExceptionMessageMatches(
            '/^\S+::complete\(\) declares parameter "\$limit" of unsupported type "int"\. It is bound from a string value\.$/',
        );

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testCompletionMethodWithAStringlessUnionParameterIsRejected(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x', prompt: 'p')]
            public function complete(float|int $value): array
            {
                return [(string) $value];
            }
        };

        $this->expectException(UnsupportedParameterTypeException::class);

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testCompletionMethodWithAnEnumParameterIsRejected(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x', prompt: 'p')]
            public function complete(BackedStringEnum $color): array
            {
                return [$color->value];
            }
        };

        $this->expectException(UnsupportedParameterTypeException::class);

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testVariadicCompletionParameterIsRejected(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x', prompt: 'p')]
            public function complete(string ...$values): array
            {
                return array_values($values);
            }
        };

        $this->expectException(UnsupportedVariadicParameterException::class);

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testACompletionAttributeNamingNoTargetIsRejectedNamingTheMethod(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x')]
            public function complete(string $value): array
            {
                return [$value];
            }
        };

        $this->expectException(InvalidCompletionAttributeException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) declares an invalid #\[AsCompletion\] attribute: it must name the completed "prompt" or "uriTemplate"\.$/',
        );

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testACompletionAttributeNamingBothTargetsIsRejectedNamingTheMethod(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x', prompt: 'p', uriTemplate: 'file:///{path}')]
            public function complete(string $value): array
            {
                return [$value];
            }
        };

        $this->expectException(InvalidCompletionAttributeException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) declares an invalid #\[AsCompletion\] attribute: it must name either a "prompt" or a "uriTemplate", not both\.$/',
        );

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testACompletionAttributeWithAnEmptyArgumentIsRejectedNamingTheMethod(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: '', prompt: 'p')]
            public function complete(string $value): array
            {
                return [$value];
            }
        };

        $this->expectException(InvalidCompletionAttributeException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) declares an invalid #\[AsCompletion\] attribute: its "argument" must be a non-empty string\.$/',
        );

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    public function testACompletionAttributeWithAnEmptyPromptIsRejectedNamingTheMethod(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            #[AsCompletion(argument: 'x', prompt: '')]
            public function complete(string $value): array
            {
                return [$value];
            }
        };

        $this->expectException(InvalidCompletionAttributeException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) declares an invalid #\[AsCompletion\] attribute: it must name the completed "prompt" or "uriTemplate"\.$/',
        );

        iterator_to_array((new AttributeScanner())->scan($source), false);
    }

    private static function promptCompletionEntry(string $prompt, string $argument): PromptCompletionEntry
    {
        foreach ((new AttributeScanner())->scan(new CompletionHandlers()) as $entry) {
            if ($entry instanceof PromptCompletionEntry && $entry->prompt === $prompt && $entry->argument === $argument) {
                return $entry;
            }
        }

        self::fail(\sprintf('No completion for prompt "%s" argument "%s" discovered.', $prompt, $argument));
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 11),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }

    /**
     * @return list<PromptCompletionEntry|PromptEntry|ResourceEntry|ResourceTemplateCompletionEntry|ResourceTemplateEntry|ToolEntry>
     */
    private static function entries(): array
    {
        return iterator_to_array((new AttributeScanner())->scan(new DiscoverableServer()), false);
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
