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

namespace Nexus\Mcp\Tools\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Enforces the test-class conventions: structure and `@internal`, recognised `#[Group]` names,
 * and a `#[CoversClass]` chain reaching every in-package ancestor of the covered class.
 *
 * @implements Rule<InClassNode>
 *
 * @internal
 */
final class TestClassConventionsRule implements Rule
{
    private const array RECOGNISED_GROUP_NAMES = [
        'auto-review',
        'client-tests',
        'core-tests',
        'extension-tests',
        'server-tests',
        'static-analysis',
        'unit-tests',
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    #[\Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! ConventionScope::isTestClass($class)) {
            return [];
        }

        return [
            ...self::checkStructure($class),
            ...self::checkGroups($class),
            ...$this->checkCoversChain($class),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function checkStructure(ClassReflection $class): array
    {
        $reflection = $class->getNativeReflection();
        $errors = [];

        if ($reflection->isAbstract()) {
            if (! str_starts_with($reflection->getShortName(), 'Abstract')) {
                $errors[] = RuleErrorBuilder::message('Abstract test class must have a class name starting with "Abstract".')
                    ->identifier('nexusMcp.testClassStructure')
                    ->build()
                ;
            }
        } elseif (! $reflection->isFinal()) {
            $errors[] = RuleErrorBuilder::message('Test class should be final.')
                ->identifier('nexusMcp.testClassStructure')
                ->build()
            ;
        }

        $docComment = $reflection->getDocComment();

        if (! \is_string($docComment) || ! str_contains($docComment, '@internal')) {
            $errors[] = RuleErrorBuilder::message('Test class should be marked as @internal in a class-level PHPDoc.')
                ->identifier('nexusMcp.testClassStructure')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function checkGroups(ClassReflection $class): array
    {
        $attributes = $class->getNativeReflection()->getAttributes(Group::class);

        if ([] === $attributes) {
            return [
                RuleErrorBuilder::message('Test class is missing a #[Group] attribute.')
                    ->identifier('nexusMcp.testClassGroup')
                    ->build(),
            ];
        }

        $errors = [];

        foreach ($attributes as $attribute) {
            $name = $attribute->getArguments()[0] ?? null;

            if (\is_string($name) && \in_array($name, self::RECOGNISED_GROUP_NAMES, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Unrecognised #[Group(\'%s\')]. Expected one of: \'%s\'.',
                \is_string($name) ? $name : get_debug_type($name),
                implode('\', \'', self::RECOGNISED_GROUP_NAMES),
            ))
                ->identifier('nexusMcp.testClassGroup')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkCoversChain(ClassReflection $class): array
    {
        $reflection = $class->getNativeReflection();

        if ([] !== $reflection->getAttributes(CoversNothing::class)) {
            return [];
        }

        $coversAttributes = $reflection->getAttributes(CoversClass::class);

        if ([] === $coversAttributes) {
            return [
                RuleErrorBuilder::message('Test class must declare at least one #[CoversClass] (or opt out with #[CoversNothing]).')
                    ->identifier('nexusMcp.testClassCovers')
                    ->build(),
            ];
        }

        $covered = [];

        foreach ($coversAttributes as $attribute) {
            $name = $attribute->getArguments()[0] ?? null;

            if (\is_string($name)) {
                $covered[] = $name;
            }
        }

        $primary = str_replace('Nexus\\Mcp\\Tests\\', 'Nexus\\Mcp\\', substr($class->getName(), 0, -\strlen('Test')));

        if (! $this->reflectionProvider->hasClass($primary)) {
            return [
                RuleErrorBuilder::message(\sprintf(
                    'Cannot derive the primary covered class for this test. Expected "%s" to exist.',
                    $primary,
                ))
                    ->identifier('nexusMcp.testClassCovers')
                    ->build(),
            ];
        }

        $expected = [$primary];
        $parent = $this->reflectionProvider->getClass($primary)->getParentClass();

        while (null !== $parent) {
            if (str_starts_with($parent->getName(), 'Nexus\\Mcp\\')) {
                $expected[] = $parent->getName();
            }

            $parent = $parent->getParentClass();
        }

        $errors = [];

        foreach (array_diff($expected, $covered) as $missing) {
            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Missing #[CoversClass(\\%s::class)] for an in-package ancestor of the covered class.',
                $missing,
            ))
                ->identifier('nexusMcp.testClassCovers')
                ->build()
            ;
        }

        foreach (array_diff($covered, $expected) as $unexpected) {
            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Unexpected #[CoversClass(\\%s::class)] outside the covered class inheritance chain.',
                $unexpected,
            ))
                ->identifier('nexusMcp.testClassCovers')
                ->build()
            ;
        }

        return $errors;
    }
}
