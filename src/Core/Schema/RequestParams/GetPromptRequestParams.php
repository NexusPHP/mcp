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
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Parameters for a `prompts/get` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#getpromptrequestparams
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
    public function __construct(string $name, ?array $arguments = null, ?RequestMeta $meta = null)
    {
        IdentifierNameValidator::validate($name, 'GetPromptRequestParams');

        if (null !== $arguments) {
            Assert::that($arguments)->isMap('GetPromptRequestParams arguments must be a string-keyed map.');

            foreach ($arguments as $value) {
                Assert::that($value)->isString('GetPromptRequestParams arguments values must all be strings, {type} given.');
            }
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
        Assert::that($data)->hasOffset('name', 'GetPromptRequestParams wire data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('GetPromptRequestParams wire "name" must be a string, {type} given.');

        $arguments = null;

        if (\array_key_exists('arguments', $data)) {
            Assert::that($data['arguments'])
                ->isArray('GetPromptRequestParams wire "arguments" must be an object, {type} given.')
                ->isMap('GetPromptRequestParams wire "arguments" must be a string-keyed object.')
            ;
            $arguments = [];

            foreach ($data['arguments'] as $key => $value) {
                Assert::that($value)->isString('GetPromptRequestParams wire argument value must be a string, {type} given.');
                $arguments[$key] = $value;
            }
        }

        $meta = RequestMeta::parseFromWire($data, 'Request params');

        return new self($name, $arguments, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if (null !== $this->arguments) {
            $data['arguments'] = $this->arguments;
        }

        return [...parent::toArray(), ...$data];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
