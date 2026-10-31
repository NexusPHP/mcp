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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\TaskOwnership;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TaskOwnership::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskOwnershipTest extends AbstractMcpTestCase
{
    public function testAnUnprotectedCallerCreatesAnUnownedTask(): void
    {
        $record = TaskOwnership::createTask(new InMemoryTaskStore(), $this->buildContext(), 'slow_compute', null, null, 1_000);

        self::assertNull($record->owner);
    }

    public function testAnUnprotectedCallerMayUseAStoreThatDoesNotRecordOwners(): void
    {
        $record = (new InMemoryTaskStore())->createTask('slow_compute', null, null, 1_000);
        $store = self::createStub(TaskStoreInterface::class);
        $store->method('createTask')->willReturn($record);

        self::assertSame($record, TaskOwnership::createTask($store, $this->buildContext(), 'slow_compute', null, null, 1_000));
    }

    public function testAnAuthenticatedCallerCreatesATaskThatItOwns(): void
    {
        $record = TaskOwnership::createTask(
            new InMemoryTaskStore(),
            $this->buildContext($this->buildToken('alice', 'client-1')),
            'slow_compute',
            null,
            null,
            1_000,
        );

        self::assertSame('["alice","client-1"]', $record->owner);
    }

    public function testACallerWithoutAnIdentityCannotCreateATask(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('A task request needs an access token with a "sub" or "client_id" claim.');

        TaskOwnership::createTask(
            new InMemoryTaskStore(),
            $this->buildContext($this->buildToken(null, null)),
            'slow_compute',
            null,
            null,
            1_000,
        );
    }

    public function testACallerWithoutAnIdentityCannotFindATask(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('A task request needs an access token with a "sub" or "client_id" claim.');

        TaskOwnership::findTask($store, $this->buildContext($this->buildToken(null, null)), $taskId);
    }

    public function testACallerWithOnlyAClientOwnsItsTask(): void
    {
        $record = TaskOwnership::createTask(
            new InMemoryTaskStore(),
            $this->buildContext($this->buildToken(null, 'client-1')),
            'slow_compute',
            null,
            null,
            1_000,
        );

        self::assertSame('[null,"client-1"]', $record->owner);
    }

    public function testAnAuthenticatedCallerIsRefusedByAStoreThatDoesNotRecordOwners(): void
    {
        $store = self::createStub(TaskStoreInterface::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches(
            '/^.+ must implement Nexus\\\\Mcp\\\\Extension\\\\Tasks\\\\Server\\\\Store\\\\OwnedTaskStoreInterface to serve authenticated requests\.$/',
        );

        TaskOwnership::createTask($store, $this->buildContext($this->buildToken('alice', 'client-1')), 'slow_compute', null, null, 1_000);
    }

    public function testTheOwnerFindsItsTask(): void
    {
        $store = new InMemoryTaskStore();
        $alice = $this->buildContext($this->buildToken('alice', 'client-1'));
        $taskId = TaskOwnership::createTask($store, $alice, 'slow_compute', null, null, 1_000)->taskId;

        self::assertSame($taskId, TaskOwnership::findTask($store, $alice, $taskId)->taskId);
    }

    public function testAnUnprotectedCallerFindsAnUnownedTask(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        self::assertSame($taskId, TaskOwnership::findTask($store, $this->buildContext(), $taskId)->taskId);
    }

    #[DataProvider('provideAnotherCallerCannotFindTheTaskCases')]
    public function testAnotherCallerCannotFindTheTask(?VerifiedAccessToken $creator, ?VerifiedAccessToken $reader): void
    {
        $store = new InMemoryTaskStore();
        $taskId = TaskOwnership::createTask($store, $this->buildContext($creator), 'slow_compute', null, null, 1_000)->taskId;

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"params.taskId" does not name a known task.');

        TaskOwnership::findTask($store, $this->buildContext($reader), $taskId);
    }

    /**
     * @return iterable<string, array{null|VerifiedAccessToken, null|VerifiedAccessToken}>
     */
    public static function provideAnotherCallerCannotFindTheTaskCases(): iterable
    {
        yield 'another subject' => [new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'alice', clientId: 'c'), new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'bob', clientId: 'c')];

        yield 'another client' => [new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'alice', clientId: 'c1'), new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'alice', clientId: 'c2')];

        yield 'colliding when joined' => [new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'a:b', clientId: 'c'), new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'a', clientId: 'b:c')];

        yield 'unprotected reading an owned task' => [new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'alice', clientId: 'c'), null];

        yield 'authenticated reading an unowned task' => [null, new VerifiedAccessToken(['a'], 1_800_000_000, subject: 'alice', clientId: 'c')];
    }

    public function testAnUnknownTaskIsNotFound(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"params.taskId" does not name a known task.');

        TaskOwnership::findTask(new InMemoryTaskStore(), $this->buildContext(), 'missing');
    }

    /**
     * @param null|non-empty-string $subject
     * @param null|non-empty-string $clientId
     */
    private function buildToken(?string $subject, ?string $clientId): VerifiedAccessToken
    {
        return new VerifiedAccessToken(['https://mcp.test/mcp'], 1_800_000_000, subject: $subject, clientId: $clientId);
    }

    private function buildContext(?VerifiedAccessToken $token = null): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
            new ReceiveContext(authInfo: $token),
        );
    }
}
