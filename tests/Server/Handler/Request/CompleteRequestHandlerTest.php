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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CompleteRequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Handler\Request\CompleteRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Completion\RecordingCompletionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CompleteRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CompleteRequestHandlerTest extends AbstractMcpTestCase
{
    public function testDelegatesPromptRefToStoreWithDeconstructedParams(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => ['suggestion']]));

        $handler = new CompleteRequestHandler($store);
        $context = $this->makeContext();
        $request = new CompleteRequest(
            id: new RequestId(id: 7),
            params: new CompleteRequestParams(
                ref: new PromptReference(name: 'my-prompt'),
                argument: ['name' => 'arg', 'value' => 'partial'],
                meta: RequestMetaObjectFactory::create(),
                context: ['arguments' => ['other' => 'context-value']],
            ),
        );

        $result = $handler->handle($request, $context);

        self::assertSame(['suggestion'], $result->completion['values']);

        $call = $this->readFirstCall($store);
        $ref = $call['ref'];

        self::assertInstanceOf(PromptReference::class, $ref);

        self::assertSame('my-prompt', $ref->name);
        self::assertSame('arg', $call['argumentName']);
        self::assertSame('partial', $call['argumentValue']);
        self::assertSame(['other' => 'context-value'], $call['contextArguments']);
        self::assertSame($context, $call['context']);
    }

    public function testDelegatesTemplateRefToStoreWithDeconstructedParams(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => ['report.pdf']]));

        $handler = new CompleteRequestHandler($store);
        $request = new CompleteRequest(
            id: new RequestId(id: 8),
            params: new CompleteRequestParams(
                ref: new ResourceTemplateReference(uri: 'file:///{folder}/{filename}'),
                argument: ['name' => 'filename', 'value' => 'rep'],
                meta: RequestMetaObjectFactory::create(),
                context: ['arguments' => ['folder' => 'docs']],
            ),
        );

        $result = $handler->handle($request, $this->makeContext());

        self::assertSame(['report.pdf'], $result->completion['values']);

        $call = $this->readFirstCall($store);
        $ref = $call['ref'];

        self::assertInstanceOf(ResourceTemplateReference::class, $ref);

        self::assertSame('file:///{folder}/{filename}', $ref->uri);
        self::assertSame('filename', $call['argumentName']);
        self::assertSame(['folder' => 'docs'], $call['contextArguments']);
    }

    #[DataProvider('provideRefusesAnUnregisteredRefCases')]
    public function testRefusesAnUnregisteredRef(PromptReference|ResourceTemplateReference $ref, string $message): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => ['x']]));
        $seen = null;
        $handler = new CompleteRequestHandler($store, static function (
            PromptReference|ResourceTemplateReference $candidate,
        ) use (&$seen): bool {
            $seen = $candidate;

            return false;
        });

        try {
            $handler->handle($this->makeRequest($ref), $this->makeContext());
            self::fail('The request should have been refused.');
        } catch (InvalidParamsException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame(9, $e->requestId?->id);
        }

        self::assertSame($ref, $seen);
        self::assertSame([], $store->calls);
    }

    /**
     * @return iterable<string, array{PromptReference|ResourceTemplateReference, string}>
     */
    public static function provideRefusesAnUnregisteredRefCases(): iterable
    {
        yield 'a prompt' => [new PromptReference(name: 'missing'), 'Prompt "missing" not found.'];

        yield 'a template' => [
            new ResourceTemplateReference(uri: 'file:///{missing}'),
            'Resource template "file:///{missing}" not found.',
        ];
    }

    public function testCompletesARegisteredRef(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => ['x']]));
        $handler = new CompleteRequestHandler($store, static fn(): bool => true);

        $result = $handler->handle($this->makeRequest(new PromptReference(name: 'known')), $this->makeContext());

        self::assertSame(['x'], $result->completion['values']);
    }

    public function testTruncatesValuesBeyondTheSpecCapAndMarksTheOverflow(): void
    {
        $values = array_map(static fn(int $i): string => \sprintf('v%d', $i), range(1, 150));
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => $values]));
        $handler = new CompleteRequestHandler($store);

        $result = $handler->handle($this->makePromptRequest(), $this->makeContext());

        self::assertCount(100, $result->completion['values']);
        self::assertSame(\array_slice($values, 0, 100), $result->completion['values']);
        self::assertSame(150, $result->completion['total'] ?? null);
        self::assertTrue($result->completion['hasMore'] ?? null);
    }

    public function testTruncationKeepsAProviderSuppliedTotal(): void
    {
        $values = array_map(static fn(int $i): string => \sprintf('v%d', $i), range(1, 101));
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => $values, 'total' => 5_000]));
        $handler = new CompleteRequestHandler($store);

        $result = $handler->handle($this->makePromptRequest(), $this->makeContext());

        self::assertCount(100, $result->completion['values']);
        self::assertSame(5_000, $result->completion['total'] ?? null);
        self::assertTrue($result->completion['hasMore'] ?? null);
    }

    public function testExactlyOneHundredValuesPassThroughUntouched(): void
    {
        $values = array_map(static fn(int $i): string => \sprintf('v%d', $i), range(1, 100));
        $original = new CompleteResult(completion: ['values' => $values, 'hasMore' => false]);
        $store = new RecordingCompletionStore($original);
        $handler = new CompleteRequestHandler($store);

        $result = $handler->handle($this->makePromptRequest(), $this->makeContext());

        self::assertSame($original, $result);
    }

    public function testPassesNullContextArgumentsWhenParamsHaveNoContext(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => []]));

        $handler = new CompleteRequestHandler($store);
        $request = new CompleteRequest(
            id: new RequestId(id: 9),
            params: new CompleteRequestParams(
                ref: new PromptReference(name: 'p'),
                argument: ['name' => 'a', 'value' => ''],
                meta: RequestMetaObjectFactory::create(),
            ),
        );

        $handler->handle($request, $this->makeContext());

        self::assertNull($this->readFirstCall($store)['contextArguments']);
    }

    public function testPassesNullContextArgumentsWhenContextOmitsArguments(): void
    {
        $store = new RecordingCompletionStore(new CompleteResult(completion: ['values' => []]));

        $handler = new CompleteRequestHandler($store);
        $request = new CompleteRequest(
            id: new RequestId(id: 10),
            params: new CompleteRequestParams(
                ref: new PromptReference(name: 'p'),
                argument: ['name' => 'a', 'value' => ''],
                meta: RequestMetaObjectFactory::create(),
                context: [],
            ),
        );

        $handler->handle($request, $this->makeContext());

        self::assertNull($this->readFirstCall($store)['contextArguments']);
    }

    private function makeRequest(PromptReference|ResourceTemplateReference $ref): CompleteRequest
    {
        return new CompleteRequest(
            id: new RequestId(id: 9),
            params: new CompleteRequestParams(
                ref: $ref,
                argument: ['name' => 'arg', 'value' => 'partial'],
                meta: RequestMetaObjectFactory::create(),
            ),
        );
    }

    private function makePromptRequest(): CompleteRequest
    {
        return new CompleteRequest(
            id: new RequestId(id: 7),
            params: new CompleteRequestParams(
                ref: new PromptReference(name: 'my-prompt'),
                argument: ['name' => 'arg', 'value' => 'partial'],
                meta: RequestMetaObjectFactory::create(),
            ),
        );
    }

    /**
     * @return array{
     *     ref: PromptReference|ResourceTemplateReference,
     *     argumentName: string,
     *     argumentValue: string,
     *     contextArguments: null|array<array-key, string>,
     *     context: ServerContext,
     * }
     */
    private function readFirstCall(RecordingCompletionStore $store): array
    {
        if ([] === $store->calls) {
            self::fail('Expected at least one recorded call.');
        }

        return $store->calls[0];
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
