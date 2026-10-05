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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema;

use Nexus\Mcp\Extension\Skills\Schema\SkillResource;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillResource::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillResourceTest extends AbstractMcpTestCase
{
    private const string DIGEST = 'sha256:2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824';

    public function testConstruction(): void
    {
        $resource = new SkillResource('skill://git-workflow/SKILL.md', self::DIGEST, 5);

        self::assertSame('skill://git-workflow/SKILL.md', $resource->uri);
        self::assertSame(self::DIGEST, $resource->digest);
        self::assertSame(5, $resource->size);
    }

    public function testAnEmptyFileIsAResource(): void
    {
        self::assertSame(0, (new SkillResource('skill://a/empty.txt', SkillResource::computeDigest(''), 0))->size);
    }

    public function testDigestOfHashesTheRawBytes(): void
    {
        self::assertSame(self::DIGEST, SkillResource::computeDigest('hello'));
    }

    public function testToArrayAndJsonSerializeAgree(): void
    {
        $resource = new SkillResource('skill://git-workflow/SKILL.md', self::DIGEST, 5);

        self::assertSame(['uri' => 'skill://git-workflow/SKILL.md', 'digest' => self::DIGEST, 'size' => 5], $resource->toArray());
        self::assertSame($resource->toArray(), $resource->jsonSerialize());
    }

    public function testFromArrayRoundTrip(): void
    {
        $payload = ['uri' => 'skill://git-workflow/SKILL.md', 'digest' => self::DIGEST, 'size' => 5];

        self::assertSame($payload, SkillResource::fromArray($payload)->toArray());
    }

    #[DataProvider('provideConstructionRejectsInvalidValuesCases')]
    public function testConstructionRejectsInvalidValues(string $uri, string $digest, int $size, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type, argument.type, argument.type (deliberately malformed to exercise the runtime guards)
        new SkillResource($uri, $digest, $size);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function provideConstructionRejectsInvalidValuesCases(): iterable
    {
        $digestMessage = 'skill resource "digest" must be "sha256:" followed by 64 lowercase hexadecimal characters, %s given.';

        yield 'an empty uri' => ['', self::DIGEST, 5, 'skill resource "uri" must be a non-empty string.'];

        yield 'a digest without its prefix' => ['skill://a/b', substr(self::DIGEST, 7), 5, \sprintf($digestMessage, var_export(substr(self::DIGEST, 7), true))];

        yield 'a digest under another algorithm' => ['skill://a/b', 'sha512:'.substr(self::DIGEST, 7), 5, \sprintf($digestMessage, var_export('sha512:'.substr(self::DIGEST, 7), true))];

        yield 'an uppercase digest' => ['skill://a/b', strtoupper(self::DIGEST), 5, \sprintf($digestMessage, var_export(strtoupper(self::DIGEST), true))];

        yield 'a short digest' => ['skill://a/b', substr(self::DIGEST, 0, -1), 5, \sprintf($digestMessage, var_export(substr(self::DIGEST, 0, -1), true))];

        yield 'a long digest' => ['skill://a/b', self::DIGEST.'0', 5, \sprintf($digestMessage, var_export(self::DIGEST.'0', true))];

        yield 'a digest followed by a line feed' => ['skill://a/b', self::DIGEST."\n", 5, \sprintf($digestMessage, var_export(self::DIGEST."\n", true))];

        yield 'a digest after other text' => ['skill://a/b', 'x'.self::DIGEST, 5, \sprintf($digestMessage, var_export('x'.self::DIGEST, true))];

        yield 'a negative size' => ['skill://a/b', self::DIGEST, -1, 'skill resource "size" must be a non-negative integer, -1 given.'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        SkillResource::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [['digest' => self::DIGEST, 'size' => 5], 'skill resource is missing the required "uri" key.'];

        yield 'uri not a string' => [['uri' => 7, 'digest' => self::DIGEST, 'size' => 5], 'skill resource "uri" must be a non-empty string, int given.'];

        yield 'missing digest' => [['uri' => 'skill://a/b', 'size' => 5], 'skill resource is missing the required "digest" key.'];

        yield 'digest not a string' => [['uri' => 'skill://a/b', 'digest' => null, 'size' => 5], 'skill resource "digest" must be a non-empty string, null given.'];

        yield 'missing size' => [['uri' => 'skill://a/b', 'digest' => self::DIGEST], 'skill resource is missing the required "size" key.'];

        yield 'size not an integer' => [['uri' => 'skill://a/b', 'digest' => self::DIGEST, 'size' => '5'], 'skill resource "size" must be a non-negative integer, string given.'];

        yield 'size negative' => [['uri' => 'skill://a/b', 'digest' => self::DIGEST, 'size' => -1], 'skill resource "size" must be a non-negative integer, int given.'];
    }
}
