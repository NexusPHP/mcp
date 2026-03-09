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

/**
 * An optionally-sized icon that can be displayed in a user interface.
 *
 * @implements Arrayable<array{
 *   src: non-empty-string,
 *   mimeType?: non-empty-string,
 *   sizes?: list<non-empty-string>,
 *   theme?: 'dark'|'light',
 * }>
 */
final readonly class Icon implements Arrayable
{
    private const array VALID_ICON_THEMES = ['light', 'dark'];

    /**
     * @var non-empty-string
     */
    public string $src;

    /**
     * @var null|non-empty-string
     */
    public ?string $mimeType;

    /**
     * @var null|list<non-empty-string>
     */
    public ?array $sizes;

    /**
     * @var null|'dark'|'light'
     */
    public ?string $theme;

    /**
     * @param null|list<string> $sizes
     */
    public function __construct(string $src, ?string $mimeType = null, ?array $sizes = null, ?string $theme = null)
    {
        Assert::that($src)
            ->isNonEmptyString('Icon src must be a non-empty string.')
            ->matchesRegularExpression('/^(?:https?:\/\/\S+|data:[^;]+;base64,[A-Za-z0-9+\/]+={0,2})$/', 'Icon src must be a valid HTTP/HTTPS URL or a data URI with base64-encoded data.')
        ;
        Assert::that($mimeType)
            ->nullOr()
            ->isNonEmptyString('Icon mimeType must be a non-empty string or null.')
            ->matchesRegularExpression('/^[a-zA-Z][a-zA-Z!#$&^_.+-]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*$/', 'Icon mimeType must be a valid MIME type in the format "type/subtype".')
        ;

        if (null !== $sizes) {
            foreach ($sizes as $size) {
                Assert::that($size)
                    ->nullOr()
                    ->isNonEmptyString('Icon size must be a non-empty string.')
                    ->matchesRegularExpression('/^(\d+x\d+|any)$/', 'Icon size must be in the format "WIDTHxHEIGHT" or "any".')
                ;
            }
        }

        if (null !== $theme && ! \in_array($theme, self::VALID_ICON_THEMES, true)) {
            throw new \InvalidArgumentException(\sprintf('Icon theme must be one of "%s".', implode('", "', self::VALID_ICON_THEMES)));
        }

        $this->src = $src;
        $this->mimeType = $mimeType;
        $this->sizes = $sizes;
        $this->theme = $theme;
    }

    #[\Override]
    public static function fromArray(array $data): self
    {
        $data += ['mimeType' => null, 'sizes' => null, 'theme' => null];

        return new self($data['src'], $data['mimeType'], $data['sizes'], $data['theme']);
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
