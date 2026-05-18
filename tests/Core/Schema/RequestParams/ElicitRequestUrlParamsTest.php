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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;
use Nexus\Mcp\Core\Schema\RequestParams\TaskAugmentedRequestParams;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitRequestUrlParams::class)]
#[CoversClass(TaskAugmentedRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitRequestUrlParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ElicitRequestUrlParams(
            'elicit-1',
            'Sign in',
            'url',
            'https://auth.example.com',
        );

        self::assertSame('elicit-1', $params->elicitationId);
        self::assertSame('Sign in', $params->message);
        self::assertSame('url', $params->mode);
        self::assertSame('https://auth.example.com', $params->url);
        self::assertNull($params->task);
        self::assertSame([], $params->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $params = new ElicitRequestUrlParams(
            'elicit-1',
            'Sign in',
            'url',
            'https://auth.example.com',
        );

        self::assertSame(
            [
                'elicitationId' => 'elicit-1',
                'message' => 'Sign in',
                'mode' => 'url',
                'url' => 'https://auth.example.com',
            ],
            $params->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $params = new ElicitRequestUrlParams(
            'elicit-1',
            'Sign in',
            'url',
            'https://auth.example.com',
            new TaskMetadata(60000),
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'task' => ['ttl' => 60000],
                'elicitationId' => 'elicit-1',
                'message' => 'Sign in',
                'mode' => 'url',
                'url' => 'https://auth.example.com',
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ElicitRequestUrlParams(
            'elicit-1',
            'Sign in',
            'url',
            'https://auth.example.com',
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitRequestUrlParams(
            'elicit-1',
            'Sign in',
            'url',
            'https://auth.example.com',
            new TaskMetadata(60000),
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        $rebuilt = ElicitRequestUrlParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyElicitationId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitRequestUrlParams elicitationId must be a non-empty string.');

        new ElicitRequestUrlParams('', 'm', 'url', 'https://example.com');
    }

    public function testConstructorRejectsEmptyMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitRequestUrlParams message must be a non-empty string.');

        new ElicitRequestUrlParams('id', '', 'url', 'https://example.com');
    }

    public function testConstructorRejectsEmptyUrl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitRequestUrlParams url must be a non-empty string.');

        new ElicitRequestUrlParams('id', 'm', 'url', '');
    }

    public function testConstructorRejectsInvalidUrl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitRequestUrlParams url must be a valid URL.');

        new ElicitRequestUrlParams('id', 'm', 'url', 'not a url');
    }

    public function testConstructorRejectsWrongMode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitRequestUrlParams mode must be "url", \'form\' given.');

        new ElicitRequestUrlParams('id', 'm', 'form', 'https://example.com');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitRequestUrlParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing elicitationId' => [
            ['message' => 'm', 'mode' => 'url', 'url' => 'https://example.com'],
            'ElicitRequestUrlParams data missing "elicitationId".',
        ];

        yield 'elicitationId not a string' => [
            ['elicitationId' => 1, 'message' => 'm', 'mode' => 'url', 'url' => 'https://example.com'],
            'ElicitRequestUrlParams "elicitationId" must be a string, int given.',
        ];

        yield 'missing message' => [
            ['elicitationId' => 'id', 'mode' => 'url', 'url' => 'https://example.com'],
            'ElicitRequestUrlParams data missing "message".',
        ];

        yield 'message not a string' => [
            ['elicitationId' => 'id', 'message' => 1, 'mode' => 'url', 'url' => 'https://example.com'],
            'ElicitRequestUrlParams "message" must be a string, int given.',
        ];

        yield 'missing mode' => [
            ['elicitationId' => 'id', 'message' => 'm', 'url' => 'https://example.com'],
            'ElicitRequestUrlParams data missing "mode".',
        ];

        yield 'mode not a string' => [
            ['elicitationId' => 'id', 'message' => 'm', 'mode' => 1, 'url' => 'https://example.com'],
            'ElicitRequestUrlParams "mode" must be a string, int given.',
        ];

        yield 'missing url' => [
            ['elicitationId' => 'id', 'message' => 'm', 'mode' => 'url'],
            'ElicitRequestUrlParams data missing "url".',
        ];

        yield 'url not a string' => [
            ['elicitationId' => 'id', 'message' => 'm', 'mode' => 'url', 'url' => 1],
            'ElicitRequestUrlParams "url" must be a string, int given.',
        ];

        yield 'task not an object' => [
            ['elicitationId' => 'id', 'message' => 'm', 'mode' => 'url', 'url' => 'https://example.com', 'task' => 'oops'],
            'ElicitRequestUrlParams "task" must be an object, string given.',
        ];

        yield 'task list-keyed' => [
            ['elicitationId' => 'id', 'message' => 'm', 'mode' => 'url', 'url' => 'https://example.com', 'task' => ['x']],
            'ElicitRequestUrlParams "task" must be a string-keyed object.',
        ];
    }
}
