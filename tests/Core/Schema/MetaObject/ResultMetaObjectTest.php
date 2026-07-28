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

namespace Nexus\Mcp\Tests\Core\Schema\MetaObject;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResultMetaObject::class)]
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResultMetaObjectTest extends TestCase
{
    public function testDefaultsToNoServerInfoAndEmptyExtras(): void
    {
        $meta = new ResultMetaObject();

        self::assertNull($meta->serverInfo);
        self::assertSame([], $meta->extras);
    }

    public function testConstructionCapturesAllFields(): void
    {
        $serverInfo = new Implementation(name: 'server', version: '1.0.0');
        $meta = new ResultMetaObject(serverInfo: $serverInfo, extras: ['vendor' => 'x']);

        self::assertSame($serverInfo, $meta->serverInfo);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testToArrayEmitsTheNamespacedServerInfoKey(): void
    {
        $meta = new ResultMetaObject(serverInfo: new Implementation(name: 'server', version: '1.0.0'));

        self::assertSame(
            [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'server', 'version' => '1.0.0']],
            $meta->toArray(),
        );
    }

    public function testToArrayEmitsExtrasAlongsideServerInfo(): void
    {
        $meta = new ResultMetaObject(
            serverInfo: new Implementation(name: 'server', version: '1.0.0'),
            extras: ['vendor' => 'x'],
        );

        self::assertSame(
            [
                ResultMetaObject::SERVER_INFO_KEY => ['name' => 'server', 'version' => '1.0.0'],
                'vendor' => 'x',
            ],
            $meta->toArray(),
        );
    }

    public function testToArrayOmitsServerInfoWhenNull(): void
    {
        $meta = new ResultMetaObject(extras: ['vendor' => 'x']);

        self::assertSame(['vendor' => 'x'], $meta->toArray());
    }

    public function testFromArrayParsesServerInfoAndExtras(): void
    {
        $meta = ResultMetaObject::fromArray([
            ResultMetaObject::SERVER_INFO_KEY => ['name' => 'server', 'version' => '2.1.0'],
            'vendor' => 'x',
        ]);

        if (! $meta->serverInfo instanceof Implementation) {
            self::fail('Expected the server info to be parsed.');
        }

        self::assertSame('server', $meta->serverInfo->name);
        self::assertSame('2.1.0', $meta->serverInfo->version);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testFromArrayLeavesServerInfoNullWhenAbsent(): void
    {
        $meta = ResultMetaObject::fromArray(['vendor' => 'x']);

        self::assertNull($meta->serverInfo);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    #[DataProvider('provideFromArrayRejectsAMalformedServerInfoCases')]
    public function testFromArrayRejectsAMalformedServerInfo(mixed $value, string $message): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($message);

        ResultMetaObject::fromArray([ResultMetaObject::SERVER_INFO_KEY => $value]);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideFromArrayRejectsAMalformedServerInfoCases(): iterable
    {
        yield 'not an array' => [
            'server',
            '"_meta.io.modelcontextprotocol/serverInfo" must be an object, string given.',
        ];

        yield 'int-keyed array' => [
            ['server'],
            '"_meta.io.modelcontextprotocol/serverInfo" must be a string-keyed object.',
        ];
    }

    public function testRoundTripPreservesAllFields(): void
    {
        $original = new ResultMetaObject(
            serverInfo: new Implementation(name: 'server', version: '1.0.0'),
            extras: ['key' => 'value', 'num' => 42],
        );

        self::assertSame($original->toArray(), ResultMetaObject::fromArray($original->toArray())->toArray());
    }

    public function testDeclaresServerInfoSeesTheTypedSlot(): void
    {
        $meta = new ResultMetaObject(serverInfo: new Implementation(name: 'server', version: '1.0.0'));

        self::assertTrue($meta->declaresServerInfo());
    }

    public function testDeclaresServerInfoSeesTheKeyAmongTheExtras(): void
    {
        // `fromArray` hoists the key into the typed slot, but a directly constructed instance
        // can still carry it here, and `toArray` would drop it in favour of a later stamp.
        $meta = new ResultMetaObject(extras: [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'x', 'version' => '1']]);

        self::assertTrue($meta->declaresServerInfo());
    }

    public function testDeclaresServerInfoIsFalseWhenNeitherCarriesIt(): void
    {
        self::assertFalse(new ResultMetaObject(extras: ['vendor' => 'x'])->declaresServerInfo());
    }

    public function testJsonSerializeMatchesToArrayWhenPopulated(): void
    {
        $meta = new ResultMetaObject(serverInfo: new Implementation(name: 'server', version: '1.0.0'));

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $meta = new ResultMetaObject();

        self::assertInstanceOf(\stdClass::class, $meta->jsonSerialize());
        self::assertSame('{}', json_encode($meta));
    }
}
