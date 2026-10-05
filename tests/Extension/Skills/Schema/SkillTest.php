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

use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Schema\SkillResource;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Skill::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillTest extends AbstractMcpTestCase
{
    private const string URI = 'skill://acme/billing/refunds/SKILL.md';
    private const string DIGEST = 'sha256:2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824';
    private const array FRONTMATTER = ['name' => 'refunds', 'description' => 'Process refunds.', 'license' => 'MIT'];

    public function testConstruction(): void
    {
        $manifest = new SkillResource(self::URI, self::DIGEST, 5);
        $supporting = new SkillResource('skill://acme/billing/refunds/examples/email.md', self::DIGEST, 5);
        $entry = new Skill(self::URI, self::FRONTMATTER, [$manifest, $supporting]);

        self::assertSame(self::URI, $entry->uri);
        self::assertSame(self::FRONTMATTER, $entry->frontmatter);
        self::assertSame([$manifest, $supporting], $entry->resources);
    }

    public function testASkillAtTheTopOfTheNamespaceTakesItsNameFromTheAuthority(): void
    {
        $entry = new Skill('skill://git-workflow/SKILL.md', ['name' => 'git-workflow', 'description' => 'd'], 'dynamic');

        self::assertSame('skill://git-workflow/SKILL.md', $entry->uri);
    }

    public function testADynamicSkillHoldsNoManifest(): void
    {
        $entry = new Skill(self::URI, self::FRONTMATTER, 'dynamic');

        self::assertSame('dynamic', $entry->resources);
        self::assertSame(['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => 'dynamic'], $entry->toArray());
    }

    public function testToArrayAndJsonSerializeAgree(): void
    {
        $entry = new Skill(self::URI, self::FRONTMATTER, [new SkillResource(self::URI, self::DIGEST, 5)]);

        self::assertSame([
            'uri' => self::URI,
            'frontmatter' => self::FRONTMATTER,
            'resources' => [['uri' => self::URI, 'digest' => self::DIGEST, 'size' => 5]],
        ], $entry->toArray());
        self::assertSame($entry->toArray(), $entry->jsonSerialize());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRoundTripCases')]
    public function testFromArrayRoundTrip(array $payload): void
    {
        self::assertSame($payload, Skill::fromArray($payload)->toArray());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideFromArrayRoundTripCases(): iterable
    {
        yield 'a manifest' => [[
            'uri' => self::URI,
            'frontmatter' => self::FRONTMATTER,
            'resources' => [
                ['uri' => self::URI, 'digest' => self::DIGEST, 'size' => 5],
                ['uri' => 'skill://acme/billing/refunds/examples/email.md', 'digest' => self::DIGEST, 'size' => 0],
            ],
        ]];

        yield 'dynamic resources' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => 'dynamic']];
    }

    /**
     * @param array<array-key, mixed>    $frontmatter
     * @param array<mixed, mixed>|string $resources
     */
    #[DataProvider('provideConstructionRejectsAnInvalidEntryCases')]
    public function testConstructionRejectsAnInvalidEntry(string $uri, array $frontmatter, array|string $resources, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type, argument.type, argument.type (deliberately malformed to exercise the runtime guards)
        new Skill($uri, $frontmatter, $resources);
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>, array<mixed, mixed>|string, string}>
     */
    public static function provideConstructionRejectsAnInvalidEntryCases(): iterable
    {
        $manifest = new SkillResource(self::URI, self::DIGEST, 5);

        yield 'an empty uri' => ['', self::FRONTMATTER, 'dynamic', 'skill "uri" must be a non-empty string.'];

        yield 'a uri naming another file' => [
            'skill://acme/billing/refunds/README.md', self::FRONTMATTER, 'dynamic',
            'skill "uri" must name a "SKILL.md", \'skill://acme/billing/refunds/README.md\' given.',
        ];

        yield 'a uri naming a file merely ending in the manifest name' => [
            'skill://acme/billing/refunds/NOT-SKILL.md', self::FRONTMATTER, 'dynamic',
            'skill "uri" must name a "SKILL.md", \'skill://acme/billing/refunds/NOT-SKILL.md\' given.',
        ];

        yield 'a uri that is only the manifest name' => ['/SKILL.md', self::FRONTMATTER, 'dynamic', 'skill "uri" must name a skill directory before "SKILL.md".'];

        yield 'frontmatter that is a list' => [self::URI, ['refunds', 'Process refunds.'], 'dynamic', 'skill "frontmatter" must be a string-keyed object.'];

        yield 'frontmatter without a name' => [self::URI, ['description' => 'd'], 'dynamic', 'skill "frontmatter" must carry a "name".'];

        yield 'frontmatter with an empty name' => [self::URI, ['name' => '', 'description' => 'd'], 'dynamic', 'skill "frontmatter.name" must be a non-empty string.'];

        yield 'frontmatter without a description' => [self::URI, ['name' => 'refunds'], 'dynamic', 'skill "frontmatter" must carry a "description".'];

        yield 'frontmatter with a description that is not text' => [self::URI, ['name' => 'refunds', 'description' => 7], 'dynamic', 'skill "frontmatter.description" must be a non-empty string.'];

        yield 'a name that is not the final segment' => [
            self::URI, ['name' => 'billing', 'description' => 'd'], 'dynamic',
            'skill "uri" must end its skill path in the "frontmatter.name" \'billing\', \'refunds\' given.',
        ];

        yield 'a name that only ends the final segment' => [
            self::URI, ['name' => 'funds', 'description' => 'd'], 'dynamic',
            'skill "uri" must end its skill path in the "frontmatter.name" \'funds\', \'refunds\' given.',
        ];

        yield 'resources keyed like a map' => [self::URI, self::FRONTMATTER, ['manifest' => $manifest], 'skill "resources" must be a list or "dynamic".'];

        yield 'resources another word' => [self::URI, self::FRONTMATTER, 'static', 'skill "resources" must be a list or "dynamic".'];

        yield 'an empty manifest' => [self::URI, self::FRONTMATTER, [], 'skill "resources" must list the "SKILL.md" named by the "uri".'];

        yield 'a manifest without the SKILL.md' => [
            self::URI, self::FRONTMATTER, [new SkillResource('skill://acme/billing/refunds/examples/email.md', self::DIGEST, 5)],
            'skill "resources" must list the "SKILL.md" named by the "uri".',
        ];

        yield 'a file outside the skill directory' => [
            self::URI, self::FRONTMATTER, [$manifest, new SkillResource('skill://acme/billing/other/a.md', self::DIGEST, 5)],
            'each skill "resources" entry must name a file under "skill://acme/billing/refunds".',
        ];

        yield 'a file in a directory merely starting with the skill directory' => [
            self::URI, self::FRONTMATTER, [$manifest, new SkillResource('skill://acme/billing/refunds-old/a.md', self::DIGEST, 5)],
            'each skill "resources" entry must name a file under "skill://acme/billing/refunds".',
        ];

        yield 'the skill directory itself' => [
            self::URI, self::FRONTMATTER, [$manifest, new SkillResource('skill://acme/billing/refunds', self::DIGEST, 5)],
            'each skill "resources" entry must name a file under "skill://acme/billing/refunds".',
        ];

        yield 'a file listed twice' => [self::URI, self::FRONTMATTER, [$manifest, $manifest], 'each skill "resources" entry must name its file once.'];
    }

    public function testResourcesMustBeSkillResources(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new Skill(self::URI, self::FRONTMATTER, [['uri' => self::URI]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        Skill::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [['frontmatter' => self::FRONTMATTER, 'resources' => 'dynamic'], 'skill is missing the required "uri" key.'];

        yield 'uri not a string' => [['uri' => 7, 'frontmatter' => self::FRONTMATTER, 'resources' => 'dynamic'], 'skill "uri" must be a non-empty string, int given.'];

        yield 'missing frontmatter' => [['uri' => self::URI, 'resources' => 'dynamic'], 'skill is missing the required "frontmatter" key.'];

        yield 'frontmatter not an object' => [['uri' => self::URI, 'frontmatter' => 'refunds', 'resources' => 'dynamic'], 'skill "frontmatter" must be an object, string given.'];

        yield 'frontmatter a list' => [['uri' => self::URI, 'frontmatter' => ['refunds'], 'resources' => 'dynamic'], 'skill "frontmatter" must be a string-keyed object.'];

        yield 'frontmatter without a name' => [
            ['uri' => self::URI, 'frontmatter' => ['description' => 'd'], 'resources' => 'dynamic'],
            'skill "frontmatter" is missing the required "name" key.',
        ];

        yield 'frontmatter with a name that is not text' => [
            ['uri' => self::URI, 'frontmatter' => ['name' => 7, 'description' => 'd'], 'resources' => 'dynamic'],
            'skill "frontmatter.name" must be a non-empty string, int given.',
        ];

        yield 'frontmatter without a description' => [
            ['uri' => self::URI, 'frontmatter' => ['name' => 'refunds'], 'resources' => 'dynamic'],
            'skill "frontmatter" is missing the required "description" key.',
        ];

        yield 'frontmatter with an empty description' => [
            ['uri' => self::URI, 'frontmatter' => ['name' => 'refunds', 'description' => ''], 'resources' => 'dynamic'],
            'skill "frontmatter.description" must be a non-empty string, string given.',
        ];

        yield 'missing resources' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER], 'skill is missing the required "resources" key.'];

        yield 'resources another word' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => 'static'], 'skill "resources" must be a list or "dynamic", string given.'];

        yield 'resources null' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => null], 'skill "resources" must be a list or "dynamic", null given.'];

        yield 'resources a map' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => ['a' => []]], 'skill "resources" must be a list or "dynamic", array given.'];

        yield 'a resource that is not an object' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => ['x']], 'each skill "resources" entry must be an object, string given.'];

        yield 'a resource that is a list' => [['uri' => self::URI, 'frontmatter' => self::FRONTMATTER, 'resources' => [['x']]], 'each skill "resources" entry must be a string-keyed object.'];
    }
}
