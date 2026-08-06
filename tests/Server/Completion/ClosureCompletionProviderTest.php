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
use Nexus\Mcp\Server\Completion\ClosureCompletionProvider;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClosureCompletionProvider::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ClosureCompletionProviderTest extends AbstractMcpTestCase
{
    public function testDelegatesAllThreeInputsToTheClosure(): void
    {
        $captured = [];
        $provider = new ClosureCompletionProvider(
            static function (string $value, ?array $contextArguments, ServerContext $context) use (&$captured): CompleteResult {
                $captured = [$value, $contextArguments, $context];

                return new CompleteResult(completion: ['values' => ['wrapped-'.$value]]);
            },
        );

        $context = new ServerContext(
            new RequestId(id: 3),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );

        $result = $provider->complete('eu', ['country' => 'DE'], $context);

        self::assertSame(['wrapped-eu'], $result->completion['values']);
        self::assertSame(['eu', ['country' => 'DE'], $context], $captured);
    }
}
