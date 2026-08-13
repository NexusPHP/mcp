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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Annotations::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class AnnotationsTest extends AbstractMcpTestCase
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
        $this->expectExceptionMessageIs('"annotations.priority" must be between 0.0 and 1.0.');

        new Annotations(priority: $priority);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideAnnotationsRejectsOutOfRangePriorityCases(): iterable
    {
        yield 'below lower boundary' => [-0.000_01];

        yield 'above upper boundary' => [1.000_01];
    }

    #[DataProvider('provideAnnotationsAcceptsIso8601LastModifiedCases')]
    public function testAnnotationsAcceptsIso8601LastModified(string $lastModified): void
    {
        $annotations = new Annotations(lastModified: $lastModified);

        self::assertInstanceOf(\DateTimeImmutable::class, $annotations->lastModified);
        self::assertSame((new \DateTimeImmutable($lastModified))->getTimestamp(), $annotations->lastModified->getTimestamp());
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
        $this->expectExceptionMessageIs($expectedMessage);

        new Annotations(lastModified: $lastModified);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAnnotationsRejectsInvalidLastModifiedCases(): iterable
    {
        $shapeMessage = '"annotations.lastModified" must be an RFC 3339 datetime: "YYYY-MM-DDThh:mm:ss", an optional "." fraction, then "Z" or "+hh:mm"/"-hh:mm".';

        yield 'invalid text' => ['not-a-date', $shapeMessage];

        yield 'missing timezone' => ['2026-03-09T12:00:00', $shapeMessage];

        yield 'overflowed date and time' => ['2026-13-45T25:99:99+00:00', '"annotations.lastModified" must be a valid ISO 8601 datetime: The parsed date was invalid.'];

        yield 'space separator' => ['2026-03-09 12:00:00+00:00', $shapeMessage];

        yield 'prefixed garbage' => ['foo2026-03-09T12:00:00+00:00', $shapeMessage];

        yield 'suffixed garbage' => ['2026-03-09T12:00:00+00:00bar', $shapeMessage];

        yield 'timezone name instead of an offset' => ['2026-03-09T12:00:00UTC', $shapeMessage];

        yield 'null byte in value' => ["2026-03-09T12:00:00+00:00\0", $shapeMessage];
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
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "annotations.audience" must be one of [\'user\', \'assistant\'], \'invalid-role\' given.');

        Annotations::fromArray(['audience' => ['invalid-role']]);
    }

    public function testAnnotationsFromArrayRejectsNonStringAudienceEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "annotations.audience" must be one of [\'user\', \'assistant\'], 42 given.');

        Annotations::fromArray(['audience' => [42]]);
    }

    public function testAnnotationsFromArrayCoercesIntPriority(): void
    {
        $annotations = Annotations::fromArray(['priority' => 1]);

        self::assertSame(1.0, $annotations->priority);
    }

    public function testAnnotationsToArrayOmitsNullProperties(): void
    {
        $annotations = new Annotations();

        self::assertSame([], $annotations->toArray());
    }

    public function testAnnotationsToArrayPreservesLastModifiedSubsecondPrecision(): void
    {
        $annotations = new Annotations(lastModified: '2026-03-09T12:00:00.500Z');

        self::assertSame(
            ['lastModified' => '2026-03-09T12:00:00.500000+00:00'],
            $annotations->toArray(),
        );
    }

    public function testAnnotationsToArrayPreservesLastModifiedMicroseconds(): void
    {
        $annotations = new Annotations(lastModified: '2026-03-09T12:00:00.000456Z');

        self::assertSame(
            ['lastModified' => '2026-03-09T12:00:00.000456+00:00'],
            $annotations->toArray(),
        );
    }

    public function testAnnotationsToArrayOmitsSubsecondsWhenZero(): void
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
