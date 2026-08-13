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
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GenericResultMetaObject::class)]
#[CoversClass(ResultMetaObject::class)]
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GenericResultMetaObjectTest extends AbstractMcpTestCase
{
    public function testDefaultsToNoServerInfoAndEmptyExtras(): void
    {
        $meta = new GenericResultMetaObject();

        self::assertNull($meta->serverInfo);
        self::assertSame([], $meta->extras);
    }

    public function testConstructionCapturesAllFields(): void
    {
        $serverInfo = new Implementation(name: 'server', version: '1.0.0');
        $meta = new GenericResultMetaObject(serverInfo: $serverInfo, extras: ['vendor' => 'x']);

        self::assertSame($serverInfo, $meta->serverInfo);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testToArrayEmitsTheNamespacedServerInfoKey(): void
    {
        $meta = new GenericResultMetaObject(serverInfo: new Implementation(name: 'server', version: '1.0.0'));

        self::assertSame(
            [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'server', 'version' => '1.0.0']],
            $meta->toArray(),
        );
    }

    public function testToArrayEmitsExtrasAlongsideServerInfo(): void
    {
        $meta = new GenericResultMetaObject(
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
        $meta = new GenericResultMetaObject(extras: ['vendor' => 'x']);

        self::assertSame(['vendor' => 'x'], $meta->toArray());
    }

    public function testFromArrayParsesServerInfoAndExtras(): void
    {
        $meta = GenericResultMetaObject::fromArray([
            ResultMetaObject::SERVER_INFO_KEY => ['name' => 'server', 'version' => '2.1.0'],
            'vendor' => 'x',
        ]);

        self::assertInstanceOf(Implementation::class, $meta->serverInfo);

        self::assertSame('server', $meta->serverInfo->name);
        self::assertSame('2.1.0', $meta->serverInfo->version);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testFromArrayLeavesServerInfoNullWhenAbsent(): void
    {
        $meta = GenericResultMetaObject::fromArray(['vendor' => 'x']);

        self::assertNull($meta->serverInfo);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    #[DataProvider('provideFromArrayRejectsAMalformedServerInfoCases')]
    public function testFromArrayRejectsAMalformedServerInfo(mixed $value, string $message): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($message);

        GenericResultMetaObject::fromArray([ResultMetaObject::SERVER_INFO_KEY => $value]);
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
        $original = new GenericResultMetaObject(
            serverInfo: new Implementation(name: 'server', version: '1.0.0'),
            extras: ['key' => 'value', 'num' => 42],
        );

        self::assertSame($original->toArray(), GenericResultMetaObject::fromArray($original->toArray())->toArray());
    }

    public function testDeclaresServerInfoSeesTheTypedSlot(): void
    {
        $meta = new GenericResultMetaObject(serverInfo: new Implementation(name: 'server', version: '1.0.0'));

        self::assertTrue($meta->declaresServerInfo());
    }

    public function testDeclaresServerInfoSeesTheKeyAmongTheExtras(): void
    {
        $meta = new GenericResultMetaObject(extras: [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'x', 'version' => '1']]);

        self::assertTrue($meta->declaresServerInfo());
    }

    public function testDeclaresServerInfoIsFalseWhenNeitherCarriesIt(): void
    {
        self::assertFalse((new GenericResultMetaObject(extras: ['vendor' => 'x']))->declaresServerInfo());
    }

    public function testJsonSerializeMatchesToArrayWhenPopulated(): void
    {
        $meta = new GenericResultMetaObject(serverInfo: new Implementation(name: 'server', version: '1.0.0'));

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $meta = new GenericResultMetaObject();

        self::assertInstanceOf(\stdClass::class, $meta->jsonSerialize());
        self::assertSame('{}', json_encode($meta));
    }
}
