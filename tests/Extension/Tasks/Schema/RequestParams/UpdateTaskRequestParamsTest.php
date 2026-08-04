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

namespace Nexus\Mcp\Tests\Extension\Tasks\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\UpdateTaskRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UpdateTaskRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UpdateTaskRequestParamsTest extends TestCase
{
    public function testConstruction(): void
    {
        $response = new ElicitResult(action: ElicitAction::Accept);
        $params = new UpdateTaskRequestParams(
            taskId: 'task-1',
            inputResponses: ['github_login' => $response],
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertSame('task-1', $params->taskId);
        self::assertSame(['github_login' => $response], $params->inputResponses);
    }

    public function testToArray(): void
    {
        $params = new UpdateTaskRequestParams(
            taskId: 'task-1',
            inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(),
                'taskId' => 'task-1',
                'inputResponses' => ['github_login' => ['action' => 'accept']],
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenResponsesPresent(): void
    {
        $params = new UpdateTaskRequestParams(
            taskId: 'task-1',
            inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testJsonSerializeEncodesEmptyResponsesAsObject(): void
    {
        $params = new UpdateTaskRequestParams(
            taskId: 'task-1',
            inputResponses: [],
            meta: RequestMetaObjectFactory::create(),
        );

        $encoded = json_encode($params, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"inputResponses":{}', $encoded);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new UpdateTaskRequestParams(
            taskId: 'task-1',
            inputResponses: ['github_login' => new ElicitResult(action: ElicitAction::Accept)],
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertSame($original->toArray(), UpdateTaskRequestParams::fromArray($original->toArray())->toArray());
    }

    public function testFromArrayParsesResponses(): void
    {
        $params = UpdateTaskRequestParams::fromArray([
            'taskId' => 'task-1',
            'inputResponses' => ['github_login' => ['action' => 'accept']],
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertArrayHasKey('github_login', $params->inputResponses);
        $response = $params->inputResponses['github_login'];

        if (! $response instanceof ElicitResult) {
            self::fail('The parsable entry was not decoded to an ElicitResult.');
        }

        self::assertSame(['action' => 'accept'], $response->toArray());
    }

    public function testFromArrayKeepsAnUnparsableEntryRaw(): void
    {
        $payload = [
            '_meta' => RequestMetaObjectFactory::shape(),
            'taskId' => 'task-1',
            'inputResponses' => ['unknown-key' => ['ignored' => true]],
        ];

        $params = UpdateTaskRequestParams::fromArray($payload);

        self::assertArrayHasKey('unknown-key', $params->inputResponses);
        self::assertSame(['ignored' => true], $params->inputResponses['unknown-key']);
        self::assertSame($payload, $params->toArray());
    }

    public function testConstructorAcceptsARawMapEntry(): void
    {
        $params = new UpdateTaskRequestParams(
            taskId: 'task-1',
            inputResponses: ['unknown-key' => ['ignored' => true]],
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertSame(['unknown-key' => ['ignored' => true]], $params->inputResponses);
    }

    public function testConstructorRejectsListKeyedResponses(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.inputResponses" must be a string-keyed object.');

        // @phpstan-ignore argument.type
        new UpdateTaskRequestParams(taskId: 'task-1', inputResponses: [new ElicitResult(action: ElicitAction::Accept)], meta: RequestMetaObjectFactory::create());
    }

    public function testConstructorRejectsNonResponseEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse or an object, string given.');

        // @phpstan-ignore argument.type
        new UpdateTaskRequestParams(taskId: 'task-1', inputResponses: ['github_login' => 'oops'], meta: RequestMetaObjectFactory::create());
    }

    public function testConstructorValidatesEntriesAfterAnInputResponse(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse or an object, string given.');

        new UpdateTaskRequestParams(
            taskId: 'task-1',
            // @phpstan-ignore argument.type
            inputResponses: ['first' => new ElicitResult(action: ElicitAction::Accept), 'second' => 'oops'],
            meta: RequestMetaObjectFactory::create(),
        );
    }

    public function testConstructorRejectsAListKeyedRawEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.inputResponses" entry must be an InputResponse or a string-keyed object.');

        // @phpstan-ignore argument.type
        new UpdateTaskRequestParams(taskId: 'task-1', inputResponses: ['github_login' => ['oops']], meta: RequestMetaObjectFactory::create());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UpdateTaskRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing taskId' => [
            [],
            '"params" is missing the required "taskId" key.',
        ];

        yield 'taskId not a string' => [
            ['taskId' => 1],
            '"params.taskId" must be a non-empty string, int given.',
        ];

        yield 'missing inputResponses' => [
            ['taskId' => 'task-1'],
            '"params" is missing the required "inputResponses" key.',
        ];

        yield 'inputResponses not an object' => [
            ['taskId' => 'task-1', 'inputResponses' => 'oops'],
            '"params.inputResponses" must be an object, string given.',
        ];

        yield 'inputResponses list-keyed' => [
            ['taskId' => 'task-1', 'inputResponses' => [['action' => 'accept']]],
            '"params.inputResponses" must be a string-keyed object.',
        ];

        yield 'inputResponses entry not an object' => [
            ['taskId' => 'task-1', 'inputResponses' => ['github_login' => 'oops']],
            'each "params.inputResponses" entry must be an object, string given.',
        ];

        yield 'inputResponses entry list-keyed' => [
            ['taskId' => 'task-1', 'inputResponses' => ['github_login' => ['accept']]],
            'each "params.inputResponses" entry must be a string-keyed object.',
        ];

        yield 'missing _meta' => [
            ['taskId' => 'task-1', 'inputResponses' => ['github_login' => ['action' => 'accept']]],
            '"params" is missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['taskId' => 'task-1', 'inputResponses' => ['github_login' => ['action' => 'accept']], '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];
    }
}
