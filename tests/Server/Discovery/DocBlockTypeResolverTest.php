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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PromotedTagsDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SampleToolHandlers;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DocBlockTypeResolver::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DocBlockTypeResolverTest extends AbstractMcpTestCase
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

    public function testParamTagNamesAreQualified(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(ReflectedHandlers::class, 'listOfEnums'));

        self::assertArrayHasKey('colors', $tags);
        self::assertSame(\sprintf('list<%s>', BackedStringEnum::class), (string) $tags['colors']->type);
    }

    public function testPromotedPropertyDocblocksStandInForMissingParamTags(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(PromotedTagsDto::class, '__construct'));

        self::assertSame(['widths', 'tags', 'colors', 'ratios'], array_keys($tags));
        self::assertArrayHasKey('tags', $tags);
        self::assertSame('list<string>', (string) $tags['tags']->type);
        self::assertSame('$tags', $tags['tags']->parameterName);
        self::assertSame('The tags to apply.', $tags['tags']->description);
        self::assertFalse($tags['tags']->isVariadic);
        self::assertFalse($tags['tags']->isReference);
    }

    public function testPromotedPropertyDocblockNamesAreQualified(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(PromotedTagsDto::class, '__construct'));

        self::assertArrayHasKey('colors', $tags);
        self::assertSame(\sprintf('list<%s>', BackedStringEnum::class), (string) $tags['colors']->type);
    }

    public function testAParamTagWinsOverAPromotedPropertyDocblock(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(PromotedTagsDto::class, '__construct'));

        self::assertArrayHasKey('widths', $tags);
        self::assertSame('list<int>', (string) $tags['widths']->type);
    }

    public function testAFunctionHasNoPromotedPropertyTags(): void
    {
        self::assertSame([], $this->resolver->parseParamTags(new \ReflectionFunction(static fn(int $a): int => $a)));
    }

    public function testParamTagDescriptionIsCaptured(): void
    {
        $tags = $this->resolver->parseParamTags(new \ReflectionMethod(SampleToolHandlers::class, 'described'));

        self::assertArrayHasKey('label', $tags);
        self::assertSame('A friendly label.', $tags['label']->description);
    }

    public function testParamTagsOfAMethodAreParsedOnce(): void
    {
        $method = new \ReflectionMethod(SampleToolHandlers::class, 'collections');

        self::assertSame($this->resolver->parseParamTags($method), $this->resolver->parseParamTags($method));
    }

    public function testMethodWithoutDocCommentYieldsNoTags(): void
    {
        self::assertSame([], $this->resolver->parseParamTags(new \ReflectionMethod(SampleToolHandlers::class, 'scalars')));
    }
}
