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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Handler\Request\ExtensionDeclarationGate;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ExtensionDeclarationGate::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ExtensionDeclarationGateTest extends AbstractMcpTestCase
{
    public function testDeclaresReadsThePerRequestCapabilities(): void
    {
        self::assertTrue(ExtensionDeclarationGate::declares(
            $this->buildContext(['com.example/snapshot' => []]),
            'com.example/snapshot',
        ));
        self::assertFalse(ExtensionDeclarationGate::declares(
            $this->buildContext(['com.example/other' => []]),
            'com.example/snapshot',
        ));
        self::assertFalse(ExtensionDeclarationGate::declares(
            $this->buildContext(null),
            'com.example/snapshot',
        ));
    }

    public function testRefuseNamesTheMissingExtension(): void
    {
        $exception = ExtensionDeclarationGate::refuse($this->buildContext(null), 'com.example/snapshot');

        self::assertSame(
            ['requiredCapabilities' => ['extensions' => ['com.example/snapshot' => []]]],
            $exception->errorData,
        );
    }

    /**
     * @param null|array<non-empty-string, array<string, mixed>> $extensions
     */
    private function buildContext(?array $extensions): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(clientCapabilities: new ClientCapabilities(extensions: $extensions)),
            new RecordingSender(),
        );
    }
}
