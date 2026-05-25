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

namespace Nexus\Mcp\Tests\Server\Discovery;

use Nexus\Mcp\Server\Discovery\DocBlockTypeResolver;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SampleToolHandlers;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DocBlockTypeResolver::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DocBlockTypeResolverTest extends TestCase
{
    private DocBlockTypeResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new DocBlockTypeResolver();
    }

    public function testParseNativeTypeReturnsTheParsedNode(): void
    {
        $node = $this->resolver->parseNativeType('int');

        self::assertInstanceOf(IdentifierTypeNode::class, $node);
        self::assertSame('int', $node->name);
    }

    public function testParamTagsKeyedByNameWithoutDollar(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(SampleToolHandlers::class, 'collections'));

        self::assertSame(['tags', 'owner'], array_keys($tags));
        self::assertArrayHasKey('tags', $tags);
        self::assertSame('list<string>', (string) $tags['tags']->type);
    }

    public function testParamTagDescriptionIsCaptured(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(SampleToolHandlers::class, 'described'));

        self::assertArrayHasKey('label', $tags);
        self::assertSame('A friendly label.', $tags['label']->description);
    }

    public function testMethodWithoutDocCommentYieldsNoTags(): void
    {
        self::assertSame([], $this->resolver->parseParamTags(new \ReflectionMethod(SampleToolHandlers::class, 'scalars')));
    }
}
