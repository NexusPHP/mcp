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
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Parameters for a `tools/call` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#calltoolrequestparams
 */
final readonly class CallToolRequestParams extends TaskAugmentedRequestParams
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @var null|array<string, mixed>
     */
    public ?array $arguments;

    /**
     * @param null|array<string, mixed> $arguments
     */
    public function __construct(
        string $name,
        ?array $arguments = null,
        ?TaskMetadata $task = null,
        RequestMetaObject $meta = new RequestMetaObject(),
    ) {
        IdentifierNameValidator::validate($name, '"params.name"');

        if (null !== $arguments) {
            Assert::that($arguments)->isMap('"params.arguments" must be a string-keyed map.');
        }

        $this->name = $name;
        $this->arguments = $arguments;

        parent::__construct($task, $meta);
    }

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
            ;
            $arguments = $data['arguments'];
        }

        $task = null;

        if (\array_key_exists('task', $data)) {
            Assert::that($data['task'])
                ->isArray('"params.task" must be an object, {type} given.')
                ->isMap('"params.task" must be a string-keyed object.')
            ;
            $task = TaskMetadata::fromArray($data['task']);
        }

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

        return new self($name, $arguments, $task, $meta);
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
