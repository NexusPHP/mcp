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

namespace Nexus\Mcp\Core\Schema\JsonRpc;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;

/**
 * An error response that indicates that the server requires the client to
 * provide additional information via an elicitation request.
 *
 * @implements Arrayable<array{
 *   jsonrpc: '2.0',
 *   id?: int|non-empty-string,
 *   error: template-type<Error, Arrayable, 'T'>,
 * }>
 *
 * @internal
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
final readonly class UrlElicitationRequiredError implements Arrayable
{
    public const string JSONRPC_VERSION = '2.0';

    /**
     * @var list<ElicitRequestUrlParams>
     */
    public array $elicitations;

    /**
     * @param list<ElicitRequestUrlParams> $elicitations
     */
    public function __construct(
        public ?RequestId $id,
        public Error $error,
        array $elicitations,
    ) {
        Assert::that($error->code)->isIdentical(
            ProtocolErrorCode::UrlElicitationRequired->value,
            '"error" code must be {other}, {value} given.',
        );
        Assert::that($elicitations)
            ->isList('"elicitations" must be a list, non-list array given.')
            ->values()->isInstanceOf(ElicitRequestUrlParams::class)
        ;

        $this->elicitations = $elicitations;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? null;
        Assert::that($id)->nullOr()->isArrayKey('"id" must be an int, string, or null, {type} given.');

        Assert::that($data)->hasOffset('error', 'missing the required "error" key.');
        Assert::that($data['error'])
            ->isArray('"error" must be an object, {type} given.')
            ->isMap('"error" must be a string-keyed object.')
        ;

        $error = $data['error'];

        Assert::that($error)->hasOffset('data', '"error" is missing the required "data" key.');
        Assert::that($error['data'])
            ->isArray('"error.data" must be an object, {type} given.')
            ->isMap('"error.data" must be a string-keyed object.')
        ;

        $payload = $error['data'];

        Assert::that($payload)->hasOffset('elicitations', '"error.data" is missing the required "elicitations" key.');
        Assert::that($payload['elicitations'])
            ->isList('"error.data.elicitations" must be a list, non-list array given.')
            ->values()
            ->isArray('each "error.data.elicitations" must be an object, {type} given.')
            ->isMap('each "error.data.elicitations" must be a string-keyed object.')
        ;
        $elicitations = array_map(ElicitRequestUrlParams::fromArray(...), $payload['elicitations']);

        $message = $error['message'] ?? null;
        Assert::that($message)->isString('"error.message" must be a string, {type} given.');

        $error = new UrlElicitationRequiredErrorPayload(message: $message, data: $payload);

        return new self(
            id: null === $id ? null : new RequestId(id: $id),
            error: $error,
            elicitations: $elicitations,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $envelope = ['jsonrpc' => self::JSONRPC_VERSION];

        if (null !== $this->id) {
            $envelope['id'] = $this->id->id;
        }

        $envelope['error'] = $this->error->toArray();

        return $envelope;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
