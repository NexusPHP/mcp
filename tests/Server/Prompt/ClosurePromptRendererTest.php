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

namespace Nexus\Mcp\Tests\Server\Prompt;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClosurePromptRenderer::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ClosurePromptRendererTest extends TestCase
{
    public function testForwardsArgumentsAndContextToClosure(): void
    {
        $captured = ['arguments' => null, 'requestId' => 0];
        $expected = new GetPromptResult([]);
        $renderer = new ClosurePromptRenderer(
            static function (?array $arguments, ServerContext $context) use ($expected, &$captured): GetPromptResult {
                $captured = ['arguments' => $arguments, 'requestId' => $context->requestId->id];

                return $expected;
            },
        );

        $result = $renderer->render(['name' => 'Paul'], self::makeContext());

        self::assertSame($expected, $result);
        self::assertSame(['arguments' => ['name' => 'Paul'], 'requestId' => 7], $captured);
    }

    public function testForwardsNullArgumentsToClosure(): void
    {
        $captured = ['arguments' => ['marker']];
        $renderer = new ClosurePromptRenderer(
            static function (?array $arguments, ServerContext $context) use (&$captured): GetPromptResult {
                $captured = ['arguments' => $arguments];

                return new GetPromptResult([]);
            },
        );

        $renderer->render(null, self::makeContext());

        self::assertNull($captured['arguments']);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }
}
