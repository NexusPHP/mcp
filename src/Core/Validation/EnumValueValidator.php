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

namespace Nexus\Mcp\Core\Validation;

use Nexus\Assert\ExpectationFailedException;

/**
 * Parses a value into a backed enum case.
 *
 * @internal
 */
final class EnumValueValidator
{
    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T>  $enumClass
     * @param non-empty-string $context   Label prefix for the error message (e.g. `PromptMessage "role"`).
     *
     * @return T
     *
     * @throws ExpectationFailedException
     */
    public static function parse(string $enumClass, mixed $value, string $context): \BackedEnum
    {
        if (\is_string($value) || \is_int($value)) {
            try {
                $case = $enumClass::tryFrom($value);
            } catch (\TypeError) {
                $case = null;
            }

            if (null !== $case) {
                return $case;
            }
        }

        throw new ExpectationFailedException(
            '{context} must be one of [{cases}], {value} given.',
            [
                'context' => $context,
                'cases' => implode(', ', array_map(
                    static fn(\BackedEnum $case): string => var_export($case->value, true),
                    $enumClass::cases(),
                )),
                'value' => var_export($value, true),
            ],
        );
    }
}
