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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;

/**
 * An optionally-sized icon that can be displayed in a user interface.
 *
 * @implements Arrayable<array{
 *   src: non-empty-string,
 *   mimeType?: non-empty-string,
 *   sizes?: list<non-empty-string>,
 *   theme?: 'dark'|'light',
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#icon
 */
final readonly class Icon implements Arrayable
{
    /**
     * @param non-empty-string            $src
     * @param null|non-empty-string       $mimeType
     * @param null|list<non-empty-string> $sizes
     * @param null|'dark'|'light'         $theme
     */
    public function __construct(
        public string $src,
        public ?string $mimeType = null,
        public ?array $sizes = null,
        public ?string $theme = null,
    ) {
        Rfc3986UriValidator::validate($src, '"icons.src"');
        Assert::that($mimeType)
            ->nullOr()
            ->isNonEmptyString('"icons.mimeType" must be a non-empty string or null.')
            ->matchesRegularExpression(
                '/\A[a-zA-Z][a-zA-Z!#$&^_.+-]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*\z/',
                '"icons.mimeType" must be a valid MIME type in the format "type/subtype".',
            )
        ;

        if (null !== $sizes) {
            Assert::that($sizes)
                ->values()
                ->isNonEmptyString('each "icons.sizes" must be a non-empty string.')
                ->matchesRegularExpression('/\A(\d+x\d+|any)\z/', 'each "icons.sizes" must be in the format "WIDTHxHEIGHT" or "any".')
            ;
        }

        Assert::that($theme)->nullOr()->isOneOf(['light', 'dark'], '"icons.theme" must be one of "light", "dark".');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('src', '"icons" is missing the required "src" key.');

        $src = $data['src'];
        Assert::that($src)->isNonEmptyString('"icons.src" must be a non-empty string, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isNonEmptyString('"icons.mimeType" must be a non-empty string or null, {type} given.');

        $sizes = null;

        if (isset($data['sizes'])) {
            Assert::that($data['sizes'])
                ->isList('"icons.sizes" must be a list of strings or null, {type} given.')
                ->values()->isNonEmptyString('each "icons.sizes" must be a non-empty string, {type} given.')
            ;
            $sizes = $data['sizes'];
        }

        $theme = $data['theme'] ?? null;
        Assert::that($theme)->nullOr()->isOneOf(['light', 'dark'], '"icons.theme" must be one of "light", "dark".');

        return new self(src: $src, mimeType: $mimeType, sizes: $sizes, theme: $theme);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['src' => $this->src];

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->sizes) {
            $data['sizes'] = $this->sizes;
        }

        if (null !== $this->theme) {
            $data['theme'] = $this->theme;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
