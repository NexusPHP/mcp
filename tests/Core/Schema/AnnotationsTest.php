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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Enum\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Annotations::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class AnnotationsTest extends TestCase
{
    public function testAnnotationsDefaultsToNullValues(): void
    {
        $annotations = new Annotations();

        self::assertNull($annotations->audience);
        self::assertNull($annotations->priority);
        self::assertNull($annotations->lastModified);
    }

    public function testAnnotationsValidatesAudienceRoleInstances(): void
    {
        $this->expectException(ExpectationFailedException::class);

        new Annotations(audience: ['user']); // @phpstan-ignore argument.type
    }

    public function testAnnotationsAcceptsPriorityBoundaries(): void
    {
        $zero = new Annotations(priority: 0.0);
        $one = new Annotations(priority: 1.0);

        self::assertSame(0.0, $zero->priority);
        self::assertSame(1.0, $one->priority);
    }

    #[DataProvider('provideAnnotationsRejectsOutOfRangePriorityCases')]
    public function testAnnotationsRejectsOutOfRangePriority(float $priority): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be between 0.0 and 1.0.');

        new Annotations(priority: $priority);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideAnnotationsRejectsOutOfRangePriorityCases(): iterable
    {
        yield 'below lower boundary' => [-0.00001];

        yield 'above upper boundary' => [1.00001];
    }

    #[DataProvider('provideAnnotationsAcceptsIso8601LastModifiedCases')]
    public function testAnnotationsAcceptsIso8601LastModified(string $lastModified): void
    {
        $annotations = new Annotations(lastModified: $lastModified);

        self::assertInstanceOf(\DateTimeImmutable::class, $annotations->lastModified);
        self::assertSame(new \DateTimeImmutable($lastModified)->getTimestamp(), $annotations->lastModified->getTimestamp());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnnotationsAcceptsIso8601LastModifiedCases(): iterable
    {
        yield 'offset timezone' => ['2026-03-09T12:00:00.000+00:00'];

        yield 'z timezone' => ['2026-03-09T12:00:00.000Z'];

        yield 'without milliseconds' => ['2026-03-09T12:00:00+05:30'];

        yield 'negative timezone offset' => ['2026-03-09T12:00:00-07:00'];
    }

    #[DataProvider('provideAnnotationsRejectsInvalidLastModifiedCases')]
    public function testAnnotationsRejectsInvalidLastModified(string $lastModified, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new Annotations(lastModified: $lastModified);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAnnotationsRejectsInvalidLastModifiedCases(): iterable
    {
        yield 'invalid text' => ['not-a-date', 'Last modified must be a valid ISO 8601 datetime.'];

        yield 'missing timezone' => ['2026-03-09T12:00:00', 'Last modified must be a valid ISO 8601 datetime.'];

        yield 'overflowed date and time' => ['2026-13-45T25:99:99+00:00', 'The parsed date was invalid.'];

        yield 'space separator' => ['2026-03-09 12:00:00+00:00', 'Last modified must be a valid ISO 8601 datetime.'];

        yield 'prefixed garbage' => ['foo2026-03-09T12:00:00+00:00', 'Last modified must be a valid ISO 8601 datetime.'];

        yield 'suffixed garbage' => ['2026-03-09T12:00:00+00:00bar', 'Last modified must be a valid ISO 8601 datetime.'];

        yield 'null byte in value' => ["2026-03-09T12:00:00+00:00\0", 'Last modified must not contain NULL bytes.'];
    }

    public function testAnnotationsFromArrayParsesLastModified(): void
    {
        $annotations = Annotations::fromArray([
            'audience' => ['user'],
            'priority' => 0.5,
            'lastModified' => '2026-03-09T12:00:00+00:00',
        ]);

        self::assertSame([Role::User], $annotations->audience);
        self::assertSame(0.5, $annotations->priority);
        self::assertSame('2026-03-09T12:00:00+00:00', $annotations->lastModified?->format(\DateTimeInterface::ATOM));
    }

    public function testAnnotationsFromArrayDefaultsMissingFieldsToNull(): void
    {
        $annotations = Annotations::fromArray([]);

        self::assertNull($annotations->audience);
        self::assertNull($annotations->priority);
        self::assertNull($annotations->lastModified);
    }

    public function testAnnotationsFromArrayRejectsInvalidAudienceValue(): void
    {
        $this->expectException(\ValueError::class);

        Annotations::fromArray(['audience' => ['invalid-role']]); // @phpstan-ignore argument.type
    }

    public function testAnnotationsToArrayOmitsNullProperties(): void
    {
        $annotations = new Annotations();

        self::assertSame([], $annotations->toArray());
    }

    public function testAnnotationsToArrayNormalizesLastModifiedToAtomFormat(): void
    {
        $annotations = new Annotations(lastModified: '2026-03-09T12:00:00.000Z');

        self::assertSame(
            ['lastModified' => '2026-03-09T12:00:00+00:00'],
            $annotations->toArray(),
        );
    }

    public function testAnnotationsToArrayContainsAllSetProperties(): void
    {
        $annotations = new Annotations(
            audience: [Role::Assistant],
            priority: 0.25,
            lastModified: '2026-03-09T12:00:00+00:00',
        );

        self::assertSame([
            'audience' => ['assistant'],
            'priority' => 0.25,
            'lastModified' => '2026-03-09T12:00:00+00:00',
        ], $annotations->toArray());
    }

    public function testAnnotationsToArraySerializesAudienceToStringValues(): void
    {
        $annotations = new Annotations(audience: [Role::Assistant, Role::User]);

        self::assertSame(
            ['audience' => ['assistant', 'user']],
            $annotations->toArray(),
        );
    }

    public function testAnnotationsJsonSerializeMatchesToArray(): void
    {
        $annotations = new Annotations(
            audience: [Role::User],
            priority: 0.4,
            lastModified: '2026-03-09T12:00:00+00:00',
        );

        self::assertSame($annotations->toArray(), $annotations->jsonSerialize());
    }
}
