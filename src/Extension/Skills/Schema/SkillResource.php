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

namespace Nexus\Mcp\Extension\Skills\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * One file of a skill's manifest.
 *
 * @implements Arrayable<array{
 *   uri: non-empty-string,
 *   digest: non-empty-string,
 *   size: int<0, max>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2640-skills-extension.md
 */
final readonly class SkillResource implements Arrayable
{
    public const string DIGEST_PREFIX = 'sha256:';
    public const string DIGEST_PATTERN = '/\Asha256:[0-9a-f]{64}\z/';

    /**
     * @param non-empty-string $uri
     * @param non-empty-string $digest `sha256:` and the 64 lowercase hexadecimal characters of the file's SHA-256
     * @param int<0, max>      $size   Byte length of the file's raw content
     */
    public function __construct(
        public string $uri,
        public string $digest,
        public int $size,
    ) {
        Assert::that($uri)->isNonEmptyString('skill resource "uri" must be a non-empty string.');
        Assert::that($digest)->matchesRegularExpression(
            self::DIGEST_PATTERN,
            'skill resource "digest" must be "sha256:" followed by 64 lowercase hexadecimal characters, {value} given.',
        );
        Assert::that($size)->isNaturalInt('skill resource "size" must be a non-negative integer, {value} given.');
    }

    /**
     * @internal
     *
     * @return non-empty-string
     */
    public static function computeDigest(string $bytes): string
    {
        return self::DIGEST_PREFIX.hash('sha256', $bytes);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'skill resource is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isNonEmptyString('skill resource "uri" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('digest', 'skill resource is missing the required "digest" key.');
        $digest = $data['digest'];
        Assert::that($digest)->isNonEmptyString('skill resource "digest" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('size', 'skill resource is missing the required "size" key.');
        $size = $data['size'];
        Assert::that($size)->isNaturalInt('skill resource "size" must be a non-negative integer, {type} given.');

        return new self(uri: $uri, digest: $digest, size: $size);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'digest' => $this->digest,
            'size' => $this->size,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
