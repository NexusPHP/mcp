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
use Nexus\Mcp\Server\Completion\CompletionStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CompletionStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CompletionStoreTest extends TestCase
{
    public function testReturnsEmptyResultForUnknownPromptRef(): void
    {
        $store = new CompletionStore();

        $result = $store->complete(
            new PromptReference(name: 'missing'),
            'arg',
            'partial',
            null,
            self::makeContext(),
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
            self::makeContext(),
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
            self::makeContext(),
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
            self::makeContext(),
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
            self::makeContext(),
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
            self::makeContext(),
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

        $promptResult = $store->complete(new PromptReference(name: 'p'), 'a', '', null, self::makeContext());
        $templateResult = $store->complete(new ResourceTemplateReference(uri: 'file:///{x}'), 'x', '', null, self::makeContext());

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

    public function testConstructorRejectsIntegerPromptKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Completion store prompt key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new CompletionStore(promptCompletions: [1 => []]);
    }

    public function testConstructorRejectsEmptyStringTemplateKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Completion store template key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new CompletionStore(templateCompletions: ['' => []]);
    }

    public function testConstructorRejectsIntegerTemplateKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Completion store template key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new CompletionStore(templateCompletions: [1 => []]);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 99),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }
}
