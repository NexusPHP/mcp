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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema\Result;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Extension\Skills\Schema\Result\ReadResourceDirectoryResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReadResourceDirectoryResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ReadResourceDirectoryResultTest extends AbstractMcpTestCase
{
    private const array CHILD = ['name' => 'references', 'uri' => 'skill://refunds/references', 'mimeType' => 'inode/directory'];

    public function testConstructionDefaults(): void
    {
        $result = new ReadResourceDirectoryResult(resources: []);

        self::assertSame([], $result->resources);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $result = new ReadResourceDirectoryResult(resources: []);

        self::assertSame(['resultType' => 'complete', 'resources' => []], $result->toArray());
        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testToArrayWithAllFields(): void
    {
        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'resources' => [self::CHILD],
                'nextCursor' => 'cursor-1',
            ],
            $this->createFull()->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = $this->createFull();
        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(['_meta' => ['replaced' => true]] + $result->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = $this->createFull();
        $rebuilt = ReadResourceDirectoryResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
        self::assertArrayHasKey(0, $rebuilt->resources);
        self::assertSame('references', $rebuilt->resources[0]->name);
        self::assertSame('cursor-1', $rebuilt->nextCursor?->cursor);
    }

    public function testFromArrayAcceptsAnEmptyMeta(): void
    {
        self::assertSame([], ReadResourceDirectoryResult::fromArray(['resources' => [], '_meta' => []])->meta->toArray());
    }

    public function testConstructorRejectsNonListResources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result.resources" must be a list, non-list array given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ReadResourceDirectoryResult(resources: [5 => Resource::fromArray(self::CHILD)]);
    }

    public function testConstructorRejectsANonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ReadResourceDirectoryResult(resources: [42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ReadResourceDirectoryResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing resources' => [[], '"result" is missing the required "resources" key.'];

        yield 'resources not a list' => [['resources' => 'oops'], '"result.resources" must be a list, string given.'];

        yield 'resource entry not an object' => [['resources' => ['oops']], 'each "result.resources" entry must be an object, string given.'];

        yield 'resource entry list-keyed' => [['resources' => [['x']]], 'each "result.resources" entry must be a string-keyed object.'];

        yield 'nextCursor not a string' => [['resources' => [], 'nextCursor' => 1], '"result.nextCursor" must be a non-empty string, int given.'];

        yield '_meta not an object' => [['resources' => [], '_meta' => 'oops'], '"result._meta" must be an object, string given.'];

        yield '_meta list-keyed' => [['resources' => [], '_meta' => ['x']], '"result._meta" must be a string-keyed object.'];
    }

    private function createFull(): ReadResourceDirectoryResult
    {
        return new ReadResourceDirectoryResult(
            resources: [Resource::fromArray(self::CHILD)],
            nextCursor: new Cursor(cursor: 'cursor-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );
    }
}
