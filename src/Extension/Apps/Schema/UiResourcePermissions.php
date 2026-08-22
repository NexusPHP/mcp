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

namespace Nexus\Mcp\Extension\Apps\Schema;

use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Sandbox permissions a UI resource requests from the host.
 *
 * @implements Arrayable<array{
 *   camera?: \stdClass,
 *   microphone?: \stdClass,
 *   geolocation?: \stdClass,
 *   clipboardWrite?: \stdClass,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */
final readonly class UiResourcePermissions implements Arrayable
{
    public function __construct(
        public bool $camera = false,
        public bool $microphone = false,
        public bool $geolocation = false,
        public bool $clipboardWrite = false,
    ) {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            camera: self::parseRequested($data, 'camera'),
            microphone: self::parseRequested($data, 'microphone'),
            geolocation: self::parseRequested($data, 'geolocation'),
            clipboardWrite: self::parseRequested($data, 'clipboardWrite'),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if ($this->camera) {
            $data['camera'] = new \stdClass();
        }

        if ($this->microphone) {
            $data['microphone'] = new \stdClass();
        }

        if ($this->geolocation) {
            $data['geolocation'] = new \stdClass();
        }

        if ($this->clipboardWrite) {
            $data['clipboardWrite'] = new \stdClass();
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param non-empty-string     $slot
     *
     * @throws \InvalidArgumentException
     */
    private static function parseRequested(array $data, string $slot): bool
    {
        if (! \array_key_exists($slot, $data)) {
            return false;
        }

        $value = $data[$slot];

        if (! \is_array($value) && ! $value instanceof \stdClass) {
            throw new \InvalidArgumentException(\sprintf(
                '"_meta.ui.permissions.%s" must be an object, %s given.',
                $slot,
                get_debug_type($value),
            ));
        }

        return true;
    }
}
