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

namespace Nexus\Mcp\Tests\AutoReview;

use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keeps each component's `composer.json` and mirror scaffolding in lockstep with the umbrella.
 *
 * @internal
 *
 * @phpstan-type Manifest array{
 *   name: string,
 *   license: string,
 *   type: string,
 *   keywords: list<string>,
 *   authors: list<array<string, string>>,
 *   support: array<string, string>,
 *   funding: list<array<string, string>>,
 *   require: array<string, string>,
 *   replace?: array<string, string>,
 *   suggest?: array<string, string>,
 *   autoload: array{psr-4: array<string, string>},
 * }
 */
#[CoversNothing]
#[Group('auto-review')]
final class ComponentManifestTest extends AbstractMcpTestCase
{
    private const string ROOT = __DIR__.'/../..';
    private const string LEGACY_REPLACE_ALIAS = 'nexusphp/mcp-sdk';
    private const array SIBLINGS = [
        'Core' => [],
        'Server' => ['nexusphp/mcp-core'],
        'Client' => ['nexusphp/mcp-core'],
        'Extension' => ['nexusphp/mcp-client', 'nexusphp/mcp-core', 'nexusphp/mcp-server'],
    ];
    private const array SHARED_KEYS = ['license', 'type', 'keywords', 'authors', 'support', 'funding'];
    private const array MIRROR_FILES = ['.gitattributes', '.github/workflows/redirect.yml'];

    public function testEveryReplacedPackageIsAComponent(): void
    {
        $replaced = array_keys(self::readManifest(self::ROOT.'/composer.json')['replace'] ?? []);
        $components = [];

        foreach (array_keys(self::SIBLINGS) as $component) {
            $components[] = self::readManifest(self::componentPath($component, 'composer.json'))['name'];
        }

        $components[] = self::LEGACY_REPLACE_ALIAS;
        sort($replaced);
        sort($components);

        self::assertSame($components, $replaced, 'The umbrella must replace exactly the component packages plus the legacy alias.');
    }

    /**
     * @param key-of<self::SIBLINGS> $component
     */
    #[DataProvider('provideComponentCases')]
    public function testManifestMirrorsTheUmbrella(string $component): void
    {
        $root = self::readManifest(self::ROOT.'/composer.json');
        $manifest = self::readManifest(self::componentPath($component, 'composer.json'));

        self::assertSame(\sprintf('nexusphp/mcp-%s', strtolower($component)).('Extension' === $component ? 's' : ''), $manifest['name']);
        self::assertSame([\sprintf('Nexus\\Mcp\\%s\\', $component) => ''], $manifest['autoload']['psr-4']);

        foreach (self::SHARED_KEYS as $key) {
            self::assertSame($root[$key], $manifest[$key], \sprintf('"%s" must match the umbrella.', $key));
        }

        $siblings = [];

        foreach ($manifest['require'] as $package => $constraint) {
            if (str_starts_with($package, 'nexusphp/mcp-')) {
                self::assertSame('self.version', $constraint, \sprintf('Sibling "%s" must be pinned to self.version.', $package));
                $siblings[] = $package;

                continue;
            }

            self::assertArrayHasKey($package, $root['require'], \sprintf('"%s" is required by %s but not by the umbrella.', $package, $component));
            self::assertSame($root['require'][$package], $constraint, \sprintf('The "%s" constraint must match the umbrella.', $package));
        }

        self::assertSame(self::SIBLINGS[$component], $siblings, 'The sibling requires must match the architecture ruleset.');

        foreach (array_keys($manifest['suggest'] ?? []) as $package) {
            self::assertArrayHasKey($package, $root['suggest'] ?? [], \sprintf('"%s" is suggested by %s but not by the umbrella.', $package, $component));
        }
    }

    #[DataProvider('provideComponentCases')]
    public function testMirrorScaffoldingIsPresent(string $component): void
    {
        self::assertFileExists(self::componentPath($component, 'README.md'));
        self::assertFileEquals(self::ROOT.'/LICENSE', self::componentPath($component, 'LICENSE'));

        foreach (self::MIRROR_FILES as $file) {
            self::assertFileEquals(self::componentPath('Core', $file), self::componentPath($component, $file));
        }
    }

    /**
     * @return iterable<string, array{key-of<self::SIBLINGS>}>
     */
    public static function provideComponentCases(): iterable
    {
        foreach (array_keys(self::SIBLINGS) as $component) {
            yield $component => [$component];
        }
    }

    private static function componentPath(string $component, string $file): string
    {
        return \sprintf('%s/src/%s/%s', self::ROOT, $component, $file);
    }

    /**
     * @return Manifest
     */
    private static function readManifest(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        /** @var Manifest $manifest */
        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return $manifest;
    }
}
