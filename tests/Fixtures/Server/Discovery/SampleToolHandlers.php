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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery;

use Nexus\Mcp\Server\Attribute\InputSchema;
use Nexus\Mcp\Server\ServerContext;

final class SampleToolHandlers
{
    public function scalars(string $name, int $age, bool $active, float $score): void
    {
    }

    public function noArguments(): void
    {
    }

    /**
     * @param string $label A friendly label.
     */
    public function described(string $label): void
    {
    }

    public function optionalAndNullable(?string $nickname = null, int $count = 3): void
    {
    }

    public function enums(BackedStringEnum $color, BackedIntEnum $level, PureEnum $flag): void
    {
    }

    /**
     * @param 'celsius'|'fahrenheit' $unit
     */
    public function literalUnion(string $unit): void
    {
    }

    /**
     * @param non-empty-string $code
     * @param int<1, 5>        $rating
     */
    public function refined(string $code, int $rating): void
    {
    }

    /**
     * @param list<string>                 $tags
     * @param array{id: int, name: string} $owner
     */
    public function collections(array $tags, array $owner): void
    {
    }

    public function withContext(string $query, ServerContext $context): void
    {
    }

    public function paramConstraint(#[InputSchema(format: 'email', minLength: 3)] string $email): void
    {
    }

    public function paramDefinition(#[InputSchema(definition: ['type' => 'string', 'const' => 'fixed'])] string $token): void
    {
    }

    public function unsupported(\DateTimeImmutable $when): void
    {
    }

    /**
     * @param array<string, \DateTimeImmutable> $items
     */
    public function unmappableArrayDoc(array $items): void
    {
    }

    // @phpstan-ignore missingType.parameter
    public function untyped($anything): void
    {
    }

    public function mixedParameter(mixed $value): void
    {
    }

    public function enumDefaults(BackedStringEnum $color = BackedStringEnum::A, PureEnum $flag = PureEnum::Yes): void
    {
    }

    public function variadicStrings(string ...$tags): void
    {
    }

    /**
     * @param string ...$labels A label to apply.
     */
    public function variadicDescribed(string ...$labels): void
    {
    }

    // @phpstan-ignore missingType.parameter
    public function variadicUntyped(...$values): void
    {
    }

    public function geoPoint(Coordinate $point): void
    {
    }

    public function nestedObject(Place $place): void
    {
    }

    public function noConstructorObject(EmptyDto $thing): void
    {
    }

    public function abstractObject(AbstractShape $shape): void
    {
    }

    public function interfaceObject(ShapeInterface $shape): void
    {
    }

    public function contextNotLast(ServerContext $context, string $value): void
    {
    }

    /**
     * @param string $token A secret token.
     */
    public function definitionWithDocblock(#[InputSchema(definition: ['type' => 'string', 'const' => 'fixed'])] string $token): void
    {
    }

    #[InputSchema(definition: ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'required' => ['x']])]
    public function methodDefinition(int $ignored): void
    {
    }
}
