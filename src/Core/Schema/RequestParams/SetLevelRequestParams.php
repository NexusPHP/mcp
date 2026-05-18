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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * Parameters for a `logging/setLevel` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#setlevelrequestparams
 */
final readonly class SetLevelRequestParams extends RequestParams
{
    public function __construct(public LoggingLevel $level, RequestMetaObject $meta = new RequestMetaObject())
    {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('level', 'SetLevelRequestParams data missing "level".');
        $level = EnumValueValidator::parse(LoggingLevel::class, $data['level'], 'SetLevelRequestParams "level"');

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Request params "_meta" must be an object, {type} given.')
                ->isMap('Request params "_meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

        return new self($level, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'level' => $this->level->value,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
