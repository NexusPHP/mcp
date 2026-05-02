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

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\PromptListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\Notification\RootsListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\ListRootsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\Request\SubscribeRequest;
use Nexus\Mcp\Core\Schema\Request\UnsubscribeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
use Nexus\Mcp\Core\Schema\Result\ListRootsResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact JSON-RPC envelope shape that every concrete request,
 * notification, result response, and error response writes to the wire.
 *
 * Each fixture is a hand-authored, pretty-printed JSON file under
 * `wire-shapes/{Class}/{variant}.json`. The test decodes the fixture,
 * reconstructs the schema instance, re-encodes with `JSON_PRETTY_PRINT`,
 * and asserts the string round-trips byte-for-byte. Companion gates
 * ensure every concrete spec class has at least one fixture and that no
 * orphan fixture directory exists on disk.
 *
 * Variant convention is `N + 2`: `all-props.json`, `none.json`, plus one
 * `no-{paramName}.json` per optional constructor param. With `N <= 1`
 * the redundant variants collapse (`no-{x}` == `none`, `none` == `all-props`),
 * so fewer files are written.
 *
 * @internal
 */
#[CoversNothing]
#[Group('auto-review')]
final class JsonRpcEnvelopeRoundTripTest extends TestCase
{
    private const string FIXTURE_ROOT = __DIR__.'/wire-shapes';
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param class-string              $wrapper
     * @param null|class-string<Result> $inner
     */
    #[DataProvider('provideFixtureRoundTripsToCanonicalWireShapeCases')]
    public function testFixtureRoundTripsToCanonicalWireShape(string $dir, string $wrapper, ?string $inner, string $fixturePath): void
    {
        $jsonString = file_get_contents($fixturePath);
        self::assertIsString($jsonString, \sprintf('Could not read fixture "%s".', $fixturePath));

        $jsonString = rtrim(str_replace("\r\n", "\n", $jsonString), "\n");

        $decoded = json_decode($jsonString, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, \sprintf('Fixture "%s" must decode to a JSON object.', $fixturePath));
        self::assertStringKeyed($decoded, $fixturePath);

        $instance = self::reconstruct($wrapper, $inner, $decoded);

        $reEncoded = json_encode($instance, self::JSON_FLAGS | \JSON_THROW_ON_ERROR);

        self::assertSame($jsonString, $reEncoded, \sprintf(
            'Wire shape for "%s/%s" does not round-trip. Fixture is the source of truth — either the schema class\'s `toArray`/`jsonSerialize` drifted from the spec, or the fixture needs updating.',
            $dir,
            basename($fixturePath, '.json'),
        ));
    }

    /**
     * @return iterable<string, array{string, class-string, null|class-string<Result>, string}>
     */
    public static function provideFixtureRoundTripsToCanonicalWireShapeCases(): iterable
    {
        foreach (self::registry() as $dir => $entry) {
            $directory = self::FIXTURE_ROOT.'/'.$dir;

            if (! is_dir($directory)) {
                continue;
            }

            $files = glob($directory.'/*.json');

            if (false === $files) {
                self::fail(\sprintf('Could not enumerate JSON fixtures in directory "%s".', $directory));
            }

            sort($files);

            foreach ($files as $file) {
                $variant = basename($file, '.json');

                yield \sprintf('%s/%s', $dir, $variant) => [$dir, $entry['wrapper'], $entry['inner'], $file];
            }
        }
    }

    public function testEveryConcreteRequestHasFixtures(): void
    {
        self::assertEveryConcreteSubclassIsRegistered(JsonRpcRequest::class, 'requests');
    }

    public function testEveryConcreteNotificationHasFixtures(): void
    {
        self::assertEveryConcreteSubclassIsRegistered(JsonRpcNotification::class, 'notifications');
    }

    public function testEveryConcreteResultHasResultResponseFixtures(): void
    {
        $missing = [];

        foreach (self::concreteSubclasses(Result::class) as $resultClass) {
            $shortName = new \ReflectionClass($resultClass)->getShortName();
            $expectedDir = 'JsonRpcResultResponse-'.$shortName;

            if (! is_dir(self::FIXTURE_ROOT.'/'.$expectedDir)) {
                $missing[] = $resultClass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete Result subclasses without a JsonRpcResultResponse fixture set: %s. Add fixtures under wire-shapes/JsonRpcResultResponse-{ShortName}/ and register in self::registry().',
            implode(', ', $missing),
        ));
    }

    public function testEveryConcreteErrorHasErrorResponseFixtures(): void
    {
        $missing = [];

        foreach (self::concreteSubclasses(Error::class) as $errorClass) {
            $shortName = new \ReflectionClass($errorClass)->getShortName();
            $expectedDir = 'JsonRpcErrorResponse-'.$shortName;

            if (! is_dir(self::FIXTURE_ROOT.'/'.$expectedDir)) {
                $missing[] = $errorClass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete Error subclasses without a JsonRpcErrorResponse fixture set: %s. Add fixtures under wire-shapes/JsonRpcErrorResponse-{ShortName}/ and register in self::registry().',
            implode(', ', $missing),
        ));
    }

    public function testNoOrphanFixtureDirectoriesExist(): void
    {
        $registered = [];

        foreach (self::registry() as $dir => $_entry) {
            $registered[$dir] = true;
        }

        $onDisk = glob(self::FIXTURE_ROOT.'/*', \GLOB_ONLYDIR);

        if (false === $onDisk) {
            self::fail(\sprintf('Failed to enumerate fixture directories under "%s".', self::FIXTURE_ROOT));
        }

        $orphans = [];

        foreach ($onDisk as $path) {
            $name = basename($path);

            if (! isset($registered[$name])) {
                $orphans[] = $name;
            }
        }

        self::assertSame([], $orphans, \sprintf(
            'Fixture directories without a matching entry in self::registry(): %s. Either register them or remove the directory.',
            implode(', ', $orphans),
        ));
    }

    public function testEveryRegisteredEntryHasFixtures(): void
    {
        $empty = [];

        foreach (self::registry() as $dir => $_entry) {
            $directory = self::FIXTURE_ROOT.'/'.$dir;

            if (! is_dir($directory)) {
                $empty[] = $dir.' (directory missing)';

                continue;
            }

            $files = glob($directory.'/*.json');

            if (! \is_array($files) || [] === $files) {
                $empty[] = $dir.' (no .json fixtures)';
            }
        }

        self::assertSame([], $empty, \sprintf(
            'Registered entries without on-disk fixtures: %s.',
            implode(', ', $empty),
        ));
    }

    /**
     * Wire-shape fixture registry. Each entry binds a fixture directory to
     * a wrapper class and (for parameterized response wrappers) the inner
     * payload class needed to reconstruct the envelope.
     *
     * @return iterable<string, array{wrapper: class-string, inner: null|class-string<Result>}>
     */
    private static function registry(): iterable
    {
        // Concrete requests.
        yield 'PingRequest' => ['wrapper' => PingRequest::class, 'inner' => null];

        yield 'InitializeRequest' => ['wrapper' => InitializeRequest::class, 'inner' => null];

        yield 'ListRootsRequest' => ['wrapper' => ListRootsRequest::class, 'inner' => null];

        yield 'SetLevelRequest' => ['wrapper' => SetLevelRequest::class, 'inner' => null];

        yield 'SubscribeRequest' => ['wrapper' => SubscribeRequest::class, 'inner' => null];

        yield 'UnsubscribeRequest' => ['wrapper' => UnsubscribeRequest::class, 'inner' => null];

        // Concrete notifications.
        yield 'CancelledNotification' => ['wrapper' => CancelledNotification::class, 'inner' => null];

        yield 'InitializedNotification' => ['wrapper' => InitializedNotification::class, 'inner' => null];

        yield 'LoggingMessageNotification' => ['wrapper' => LoggingMessageNotification::class, 'inner' => null];

        yield 'ProgressNotification' => ['wrapper' => ProgressNotification::class, 'inner' => null];

        yield 'PromptListChangedNotification' => ['wrapper' => PromptListChangedNotification::class, 'inner' => null];

        yield 'ResourceListChangedNotification' => ['wrapper' => ResourceListChangedNotification::class, 'inner' => null];

        yield 'ResourceUpdatedNotification' => ['wrapper' => ResourceUpdatedNotification::class, 'inner' => null];

        yield 'RootsListChangedNotification' => ['wrapper' => RootsListChangedNotification::class, 'inner' => null];

        yield 'ToolListChangedNotification' => ['wrapper' => ToolListChangedNotification::class, 'inner' => null];

        // Result responses, parameterized by the inner Result subclass.
        yield 'JsonRpcResultResponse-EmptyResult' => ['wrapper' => JsonRpcResultResponse::class, 'inner' => EmptyResult::class];

        yield 'JsonRpcResultResponse-InitializeResult' => ['wrapper' => JsonRpcResultResponse::class, 'inner' => InitializeResult::class];

        yield 'JsonRpcResultResponse-ListRootsResult' => ['wrapper' => JsonRpcResultResponse::class, 'inner' => ListRootsResult::class];

        // Error responses, organized per Error subclass even though
        // `JsonRpcErrorResponse::fromArray` self-dispatches on `code`.
        yield 'JsonRpcErrorResponse-InternalError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InvalidParamsError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-InvalidRequestError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-MethodNotFoundError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];

        yield 'JsonRpcErrorResponse-ParseError' => ['wrapper' => JsonRpcErrorResponse::class, 'inner' => null];
    }

    /**
     * @param class-string $abstractBase
     */
    private static function assertEveryConcreteSubclassIsRegistered(string $abstractBase, string $label): void
    {
        $registered = [];

        foreach (self::registry() as $dir => $entry) {
            if (JsonRpcResultResponse::class === $entry['wrapper'] || JsonRpcErrorResponse::class === $entry['wrapper']) {
                continue;
            }

            if (is_subclass_of($entry['wrapper'], $abstractBase)) {
                $registered[$entry['wrapper']] = $dir;
            }
        }

        $missing = [];

        foreach (self::concreteSubclasses($abstractBase) as $subclass) {
            if (! isset($registered[$subclass])) {
                $missing[] = $subclass;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'Concrete %s without wire-shape fixtures: %s. Add fixtures under wire-shapes/{ShortName}/ and register in self::registry().',
            $label,
            implode(', ', $missing),
        ));
    }

    /**
     * Asserts the decoded fixture is a string-keyed map (a JSON object). PHPUnit
     * has no built-in equivalent that produces the `array<string, mixed>` shape
     * downstream callers need, so we narrow here.
     *
     * @param array<array-key, mixed> $value
     *
     * @phpstan-assert array<string, mixed> $value
     */
    private static function assertStringKeyed(array $value, string $fixturePath): void
    {
        foreach (array_keys($value) as $key) {
            self::assertIsString($key, \sprintf('Fixture "%s" must decode to a string-keyed object.', $fixturePath));
        }
    }

    /**
     * @param class-string              $wrapper
     * @param null|class-string<Result> $inner
     * @param array<string, mixed>      $decoded
     */
    private static function reconstruct(string $wrapper, ?string $inner, array $decoded): \JsonSerializable
    {
        if (JsonRpcResultResponse::class === $wrapper) {
            self::assertIsString($inner, 'JsonRpcResultResponse fixtures must declare an inner Result class.');
            self::assertArrayHasKey('id', $decoded);
            self::assertArrayHasKey('result', $decoded);
            self::assertIsArray($decoded['result']);
            self::assertStringKeyed($decoded['result'], 'inner result');

            $id = $decoded['id'];

            if (! \is_int($id) && ! \is_string($id)) {
                self::fail('JsonRpcResultResponse fixture "id" must be an int or string.');
            }

            return new JsonRpcResultResponse(new RequestId($id), $inner::fromArray($decoded['result']));
        }

        \assert(is_subclass_of($wrapper, Arrayable::class));

        return $wrapper::fromArray($decoded);
    }

    /**
     * Walks `src/Core/Schema/` and returns every concrete subclass of the
     * given abstract/interface base — used by completeness gates.
     *
     * @param class-string $base
     *
     * @return list<class-string>
     */
    private static function concreteSubclasses(string $base): array
    {
        $matches = [];

        foreach (self::sourceClasses() as $class) {
            if (! is_subclass_of($class, $base)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $matches[] = $class;
        }

        sort($matches);

        return $matches;
    }

    /**
     * Walks `src/` once and caches every concrete/abstract class found there.
     *
     * @return list<class-string>
     */
    private static function sourceClasses(): array
    {
        /** @var null|list<class-string> $cache */
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $directory = realpath(__DIR__.'/../../src');
        \assert(\is_string($directory));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $classes = [];

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);

            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($directory) + 1, -4);
            $class = 'Nexus\\Mcp\\'.strtr($relativePath, '/', '\\');

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $cache = $classes;
    }
}
