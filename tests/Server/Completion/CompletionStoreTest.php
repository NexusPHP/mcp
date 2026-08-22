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

namespace Nexus\Mcp\Tests\Server\Completion;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Completion\CompletionProviderInterface;
use Nexus\Mcp\Server\Completion\CompletionStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CompletionStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CompletionStoreTest extends AbstractMcpTestCase
{
    public function testReturnsEmptyResultForUnknownPromptRef(): void
    {
        $store = new CompletionStore();

        $result = $store->complete(
            new PromptReference(name: 'missing'),
            'arg',
            'partial',
            null,
            $this->makeContext(),
        );

        self::assertSame([], $result->completion['values']);
    }

    public function testReturnsEmptyResultForUnknownTemplateRef(): void
    {
        $store = new CompletionStore();

        $result = $store->complete(
            new ResourceTemplateReference(uri: 'file:///{missing}'),
            'arg',
            'partial',
            null,
            $this->makeContext(),
        );

        self::assertSame([], $result->completion['values']);
    }

    public function testReturnsEmptyResultForKnownPromptRefWithUnknownArgument(): void
    {
        $store = new CompletionStore(promptCompletions: [
            'my-prompt' => [
                'arg' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['x']]),
            ],
        ]);

        $result = $store->complete(
            new PromptReference(name: 'my-prompt'),
            'other-arg',
            'partial',
            null,
            $this->makeContext(),
        );

        self::assertSame([], $result->completion['values']);
    }

    public function testReturnsEmptyResultForKnownTemplateRefWithUnknownArgument(): void
    {
        $store = new CompletionStore(templateCompletions: [
            'file:///{folder}/{filename}' => [
                'folder' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['x']]),
            ],
        ]);

        $result = $store->complete(
            new ResourceTemplateReference(uri: 'file:///{folder}/{filename}'),
            'filename',
            'partial',
            null,
            $this->makeContext(),
        );

        self::assertSame([], $result->completion['values']);
    }

    public function testInvokesPromptProviderWithDeconstructedArgs(): void
    {
        $captured = [];
        $store = new CompletionStore(promptCompletions: [
            'my-prompt' => [
                'arg' => static function (string $value, ?array $contextArguments, ServerContext $context) use (&$captured): CompleteResult {
                    $captured = ['value' => $value, 'contextArguments' => $contextArguments, 'requestId' => $context->requestId->id];

                    return new CompleteResult(completion: ['values' => ['suggestion-a', 'suggestion-b']]);
                },
            ],
        ]);

        $result = $store->complete(
            new PromptReference(name: 'my-prompt'),
            'arg',
            'partial-value',
            ['other' => 'context-value'],
            $this->makeContext(),
        );

        self::assertSame(['suggestion-a', 'suggestion-b'], $result->completion['values']);
        self::assertSame(['value' => 'partial-value', 'contextArguments' => ['other' => 'context-value'], 'requestId' => 99], $captured);
    }

    public function testInvokesTemplateProviderWithDeconstructedArgs(): void
    {
        $captured = [];
        $store = new CompletionStore(templateCompletions: [
            'file:///{folder}/{filename}' => [
                'filename' => static function (string $value, ?array $contextArguments, ServerContext $context) use (&$captured): CompleteResult {
                    $captured = ['value' => $value, 'contextArguments' => $contextArguments, 'requestId' => $context->requestId->id];

                    return new CompleteResult(completion: ['values' => ['report.pdf', 'report.csv']]);
                },
            ],
        ]);

        $result = $store->complete(
            new ResourceTemplateReference(uri: 'file:///{folder}/{filename}'),
            'filename',
            'rep',
            ['folder' => 'docs'],
            $this->makeContext(),
        );

        self::assertSame(['report.pdf', 'report.csv'], $result->completion['values']);
        self::assertSame(['value' => 'rep', 'contextArguments' => ['folder' => 'docs'], 'requestId' => 99], $captured);
    }

    public function testRoutesAcrossPromptAndTemplateMaps(): void
    {
        $store = new CompletionStore(
            promptCompletions: [
                'p' => ['a' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['from-prompt']])],
            ],
            templateCompletions: [
                'file:///{x}' => ['x' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['from-template']])],
            ],
        );

        $promptResult = $store->complete(new PromptReference(name: 'p'), 'a', '', null, $this->makeContext());
        $templateResult = $store->complete(new ResourceTemplateReference(uri: 'file:///{x}'), 'x', '', null, $this->makeContext());

        self::assertSame(['from-prompt'], $promptResult->completion['values']);
        self::assertSame(['from-template'], $templateResult->completion['values']);
    }

    public function testConstructorRejectsEmptyStringPromptKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Completion store prompt key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new CompletionStore(promptCompletions: ['' => []]);
    }

    public function testAnAllDigitPromptNameSurvivesPhpKeyCoercion(): void
    {
        $store = new CompletionStore(promptCompletions: [
            '0' => [
                'who' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['zero']]),
            ],
        ]);

        $result = $store->complete(
            new PromptReference(name: '0'),
            'who',
            'z',
            null,
            $this->makeContext(),
        );

        self::assertSame(['zero'], $result->completion['values']);
    }

    public function testAnAllDigitArgumentNameSurvivesPhpKeyCoercion(): void
    {
        $store = new CompletionStore(promptCompletions: [
            'my-prompt' => [
                '7' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['seven']]),
            ],
        ]);

        $result = $store->complete(
            new PromptReference(name: 'my-prompt'),
            '7',
            's',
            null,
            $this->makeContext(),
        );

        self::assertSame(['seven'], $result->completion['values']);
    }

    public function testConstructorRejectsEmptyStringTemplateKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Completion store template key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new CompletionStore(templateCompletions: ['' => []]);
    }

    public function testAnIntegerTemplateKeyIsAcceptedAsItsStringForm(): void
    {
        new CompletionStore(templateCompletions: [
            1 => [
                'path' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['one']]),
            ],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function testConstructorRejectsAnEmptyArgumentKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Completion store argument key must be a non-empty string.');

        // @phpstan-ignore argument.type
        new CompletionStore(promptCompletions: ['p' => ['' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => []])]]);
    }

    public function testConstructorRejectsANonClosureProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Completion provider must be a closure or implement CompletionProviderInterface, string given.');

        // @phpstan-ignore argument.type
        new CompletionStore(promptCompletions: ['p' => ['arg' => 'strtoupper']]);
    }

    public function testNormalizationKeepsEveryRegisteredPrompt(): void
    {
        $store = new CompletionStore(promptCompletions: [
            'first' => ['arg' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['a']])],
            'second' => ['arg' => static fn(): CompleteResult => new CompleteResult(completion: ['values' => ['b']])],
        ]);

        $result = $store->complete(
            new PromptReference(name: 'second'),
            'arg',
            'partial',
            null,
            $this->makeContext(),
        );

        self::assertSame(['b'], $result->completion['values']);
    }

    public function testAcceptsACompletionProviderInstanceInPlaceOfAClosure(): void
    {
        $provider = new class implements CompletionProviderInterface {
            /**
             * @param null|array<array-key, string> $contextArguments
             */
            #[\Override]
            public function complete(string $argumentValue, ?array $contextArguments, ServerContext $context): CompleteResult
            {
                return new CompleteResult(completion: ['values' => ['provided-'.$argumentValue]]);
            }
        };

        $store = new CompletionStore(promptCompletions: ['my-prompt' => ['arg' => $provider]]);

        $result = $store->complete(
            new PromptReference(name: 'my-prompt'),
            'arg',
            'x',
            null,
            $this->makeContext(),
        );

        self::assertSame(['provided-x'], $result->completion['values']);
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 99),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
