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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Parameters for a `prompts/get` request.
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#getpromptrequestparams
 */
final readonly class GetPromptRequestParams extends RequestParams
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @var null|array<string, string>
     */
    public ?array $arguments;

    /**
     * @param null|array<string, string> $arguments
     */
    public function __construct(string $name, RequestMetaObject $meta, ?array $arguments = null)
    {
        IdentifierNameValidator::validate($name, '"params.name"');

        if (null !== $arguments) {
            Assert::that($arguments)
                ->isMap('"params.arguments" must be a string-keyed map.')
                ->values()->isString('"params.arguments" values must all be strings, {type} given.')
            ;
        }

        $this->name = $name;
        $this->arguments = $arguments;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'missing the required "name" key.');
        $name = $data['name'];
        Assert::that($name)->isString('"params.name" must be a string, {type} given.');

        $arguments = null;

        if (\array_key_exists('arguments', $data)) {
            Assert::that($data['arguments'])
                ->isArray('"params.arguments" must be an object, {type} given.')
                ->isMap('"params.arguments" must be a string-keyed object.')
                ->values()->isString('"params.arguments" value must be a string, {type} given.')
            ;
            $arguments = $data['arguments'];
        }

        Assert::that($data)->hasOffset('_meta', '"params" missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->isMap('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(name: $name, meta: $meta, arguments: $arguments);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if ([] !== ($this->arguments ?? [])) {
            $data['arguments'] = $this->arguments;
        }

        return [...parent::toArray(), ...$data];
    }
}
