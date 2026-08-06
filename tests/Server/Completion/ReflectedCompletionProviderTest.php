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

namespace Nexus\Mcp\Tests\Server\Completion;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Server\Completion\ReflectedCompletionProvider;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReflectedCompletionProvider::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedCompletionProviderTest extends AbstractMcpTestCase
{
    public function testBindsTheValueToAStringParameter(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            public function complete(string $value): array
            {
                return ['prefix-'.$value];
            }
        };

        $result = self::provider($source)->complete('eu', null, self::makeContext());

        self::assertSame(['prefix-eu'], $result->completion['values']);
    }

    public function testBindsByTypeRegardlessOfParameterOrder(): void
    {
        $source = new class {
            public ?ServerContext $seenContext = null;

            /**
             * @param null|array<string, string> $others
             */
            public function complete(ServerContext $context, ?array $others, string $value): CompleteResult
            {
                $this->seenContext = $context;

                return new CompleteResult(completion: [
                    'values' => [$value.'/'.implode(',', $others ?? [])],
                    'hasMore' => false,
                ]);
            }
        };

        $context = self::makeContext();
        $result = self::provider($source)->complete('eu', ['country' => 'DE'], $context);

        self::assertSame(['eu/DE'], $result->completion['values']);
        self::assertFalse($result->completion['hasMore'] ?? null);
        self::assertSame($context, $source->seenContext);
    }

    public function testBindsNullContextArgumentsToTheArrayParameter(): void
    {
        $source = new class {
            /**
             * @param null|array<string, string> $others
             *
             * @return list<string>
             */
            public function complete(string $value, ?array $others): array
            {
                return null === $others ? [$value] : [];
            }
        };

        $result = self::provider($source)->complete('eu', null, self::makeContext());

        self::assertSame(['eu'], $result->completion['values']);
    }

    public function testAnEmptyListReturnStaysEmpty(): void
    {
        $source = new class {
            /**
             * @return list<string>
             */
            public function complete(string $value): array
            {
                return [];
            }
        };

        $result = self::provider($source)->complete('eu', null, self::makeContext());

        self::assertSame([], $result->completion['values']);
    }

    public function testRejectsAReturnThatIsNotACompleteResultOrStringList(): void
    {
        $source = new class {
            public function complete(string $value): int
            {
                return 42;
            }
        };

        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) must return a Nexus\\\Mcp\\\Core\\\Schema\\\Result\\\CompleteResult or a list of strings, int given\.$/',
        );

        self::provider($source)->complete('eu', null, self::makeContext());
    }

    public function testRejectsAListContainingANonString(): void
    {
        $source = new class {
            /**
             * @return array<array-key, mixed>
             */
            public function complete(string $value): array
            {
                return [42, 'ok'];
            }
        };

        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) must return a Nexus\\\Mcp\\\Core\\\Schema\\\Result\\\CompleteResult or a list of strings, array given\.$/',
        );

        self::provider($source)->complete('eu', null, self::makeContext());
    }

    public function testRejectsAStringKeyedMapOfStrings(): void
    {
        $source = new class {
            /**
             * @return array<string, string>
             */
            public function complete(string $value): array
            {
                return ['first' => 'ok'];
            }
        };

        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageMatches(
            '/^class@anonymous.*::complete\(\) must return a Nexus\\\Mcp\\\Core\\\Schema\\\Result\\\CompleteResult or a list of strings, array given\.$/',
        );

        self::provider($source)->complete('eu', null, self::makeContext());
    }

    public function testANonNullableArrayParameterReceivesAnEmptyMapWithoutContext(): void
    {
        $source = new class {
            /**
             * @param array<string, string> $others
             *
             * @return list<string>
             */
            public function complete(string $value, array $others): array
            {
                return [] === $others ? [$value.'/none'] : [];
            }
        };

        $result = self::provider($source)->complete('eu', null, self::makeContext());

        self::assertSame(['eu/none'], $result->completion['values']);
    }

    private static function provider(object $source): ReflectedCompletionProvider
    {
        return new ReflectedCompletionProvider($source, new \ReflectionMethod($source, 'complete'));
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
