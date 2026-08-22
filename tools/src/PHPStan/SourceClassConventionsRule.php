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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule enforcing the shipped-class conventions on every class under `src/`.
 *
 * @implements Rule<InClassNode>
 *
 * @internal
 */
final class SourceClassConventionsRule implements Rule
{
    private const int DOCBLOCK_SUMMARY_MAX_WIDTH = 120;

    /**
     * Classes carrying a public method outside their declared interface for a constraint PHP interfaces cannot
     * express: paired construction behind a private constructor, and a half-`Arrayable` envelope.
     */
    private const array INTERFACE_FAITHFULNESS_EXEMPT = [
        InMemoryTransport::class,
        JsonRpcResultResponse::class,
    ];

    #[\Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! ConventionScope::isSourceClass($class)) {
            return [];
        }

        return [
            ...$this->checkDocblockSummaryWidth($class),
            ...$this->checkSeeTagComesLast($class),
            ...$this->checkNaming($class),
            ...$this->checkProperties($class),
            ...$this->checkProtectedMethods($class),
            ...$this->checkInterfaceFaithfulness($class),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkDocblockSummaryWidth(ClassReflection $class): array
    {
        $docComment = $class->getNativeReflection()->getDocComment();

        if (! \is_string($docComment)) {
            return [];
        }

        $errors = [];

        foreach ($this->extractDocblockSummaryLines($docComment) as $line) {
            $width = mb_strlen($line);

            if ($width <= self::DOCBLOCK_SUMMARY_MAX_WIDTH) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Docblock summary line is %d chars wide, over the %d limit, so VSCode and GitHub horizontal-scroll.',
                $width,
                self::DOCBLOCK_SUMMARY_MAX_WIDTH,
            ))
                ->identifier('nexusMcp.classDocblockSummaryWidth')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkSeeTagComesLast(ClassReflection $class): array
    {
        $docComment = $class->getNativeReflection()->getDocComment();

        if (! \is_string($docComment)) {
            return [];
        }

        preg_match_all('/^\s*\*\s*(@\S+)/m', $docComment, $matches);
        $tags = $matches[1];

        if (! \in_array('@see', $tags, true)) {
            return [];
        }

        $last = end($tags);

        if ('@see' === $last) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                '`@see` must be the last tag in the class docblock (after `@implements`/`@extends`/`@template*`/`@phpstan-type`), but the last tag is "%s".',
                $last,
            ))
                ->identifier('nexusMcp.seeTagPosition')
                ->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkNaming(ClassReflection $class): array
    {
        $reflection = $class->getNativeReflection();
        $name = $reflection->getShortName();

        if ($reflection->isTrait()) {
            return str_ends_with($name, 'Trait')
                ? [$this->namingError('Trait "%s" must NOT use the *Trait suffix.', $class)]
                : [];
        }

        if ($reflection->isEnum()) {
            return str_ends_with($name, 'Enum')
                ? [$this->namingError('Enum "%s" must NOT use the *Enum suffix.', $class)]
                : [];
        }

        if (ConventionScope::isSchemaClass($class)) {
            return [];
        }

        if ($reflection->isInterface()) {
            return str_ends_with($name, 'Interface')
                ? []
                : [$this->namingError('Interface "%s" outside src/Core/Schema/ must use the *Interface suffix.', $class)];
        }

        if ($reflection->isAbstract()) {
            return str_starts_with($name, 'Abstract')
                ? []
                : [$this->namingError('Abstract class "%s" outside src/Core/Schema/ must use the Abstract* prefix.', $class)];
        }

        if ($reflection->isFinal()) {
            return [];
        }

        $docComment = $reflection->getDocComment();

        if (! \is_string($docComment) || ! str_contains($docComment, '@no-final')) {
            return [$this->namingError(
                'Concrete class "%s" outside src/Core/Schema/ is not final. Its docblock must carry @no-final.',
                $class,
            )];
        }

        return [];
    }

    private function namingError(string $template, ClassReflection $class): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf($template, $class->getName()))
            ->identifier('nexusMcp.classNaming')
            ->build()
        ;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkProperties(ClassReflection $class): array
    {
        $reflection = $class->getNativeReflection();

        if ($reflection->isInterface()) {
            return [];
        }

        $errors = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isReadOnly() || $property->isPrivateSet() || $property->isProtectedSet()) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Public property $%s is externally mutable. Mark it readonly or restrict set with `private(set)`/`protected(set)`.',
                $property->getName(),
            ))
                ->identifier('nexusMcp.publicPropertyMutability')
                ->build()
            ;
        }

        // Abstract classes own their protected properties as forwarded state for subclasses.
        if ($reflection->isAbstract()) {
            return $errors;
        }

        $inherited = [];
        $parent = $reflection->getParentClass();

        while (false !== $parent) {
            foreach ($parent->getProperties(\ReflectionProperty::IS_PROTECTED) as $property) {
                $inherited[] = $property->getName();
            }

            $parent = $parent->getParentClass();
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PROTECTED) as $property) {
            if (\in_array($property->getName(), $inherited, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Protected property $%s is not defined by a parent class. Consider private visibility.',
                $property->getName(),
            ))
                ->identifier('nexusMcp.protectedPropertyVisibility')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkProtectedMethods(ClassReflection $class): array
    {
        $reflection = $class->getNativeReflection();

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            return [];
        }

        $inherited = [];
        $parent = $reflection->getParentClass();

        while (false !== $parent) {
            foreach ($parent->getMethods(\ReflectionMethod::IS_PROTECTED) as $method) {
                $inherited[] = $method->getName();
            }

            $parent = $parent->getParentClass();
        }

        $errors = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PROTECTED) as $method) {
            if (\in_array($method->getName(), $inherited, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Protected method %s() is not inherited from a parent class. Consider private visibility.',
                $method->getName(),
            ))
                ->identifier('nexusMcp.protectedMethodVisibility')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkInterfaceFaithfulness(ClassReflection $class): array
    {
        $reflection = $class->getNativeReflection();
        $docComment = $reflection->getDocComment();

        if (\is_string($docComment) && str_contains($docComment, '@internal')) {
            return [];
        }

        if ($reflection->isInterface() || $reflection->isTrait()) {
            return [];
        }

        if ([] === $reflection->getInterfaceNames()) {
            return [];
        }

        if (\in_array($class->getName(), self::INTERFACE_FAITHFULNESS_EXEMPT, true)) {
            return [];
        }

        $allowed = ['__construct', '__destruct', '__wakeup'];

        foreach ($reflection->getInterfaces() as $interface) {
            $allowed = [...$allowed, ...$this->publicMethodNames($interface)];
        }

        $parent = $reflection->getParentClass();

        while (false !== $parent) {
            $allowed = [...$allowed, ...$this->publicMethodNames($parent)];
            $parent = $parent->getParentClass();
        }

        $errors = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodDoc = $method->getDocComment();

            if (\is_string($methodDoc) && str_contains($methodDoc, '@internal')) {
                continue;
            }

            if (\in_array($method->getName(), $allowed, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Public method %s() is not on an implemented interface, inherited from a parent class, or marked @internal.',
                $method->getName(),
            ))
                ->identifier('nexusMcp.interfaceFaithfulness')
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<string>
     */
    private function publicMethodNames(\ReflectionClass $reflection): array
    {
        $names = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $names[] = $method->getName();
        }

        return $names;
    }

    /**
     * The lines before the first `@`-tag, kept with their leading ` * ` so width is measured as rendered.
     *
     * @return list<string>
     */
    private function extractDocblockSummaryLines(string $docComment): array
    {
        $lines = [];

        foreach (explode("\n", $docComment) as $line) {
            $trimmed = trim($line);

            if ('/**' === $trimmed || '*/' === $trimmed) {
                continue;
            }

            $content = preg_replace('/^\s*\*\s?/', '', $line) ?? '';

            if (str_starts_with(ltrim($content), '@')) {
                break;
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
