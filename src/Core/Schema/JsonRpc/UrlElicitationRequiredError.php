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
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
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
            \sprintf('UrlElicitationRequiredError inner error code must be %d, {value} given.', ProtocolErrorCode::UrlElicitationRequired->value),
        );
        Assert::that($elicitations)
            ->isList('UrlElicitationRequiredError elicitations must be a list, got non-list array.')
            ->values()->isInstanceOf(ElicitRequestUrlParams::class)
        ;

        $this->elicitations = $elicitations;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? null;
        Assert::that($id)->nullOr()->isArrayKey('UrlElicitationRequiredError wire "id" must be int, string, or null; {type} given.');

        Assert::that($data)->hasOffset('error', 'UrlElicitationRequiredError wire data missing "error".');
        Assert::that($data['error'])
            ->isArray('UrlElicitationRequiredError wire "error" must be an object, {type} given.')
            ->isMap('UrlElicitationRequiredError wire "error" must be a string-keyed object.')
        ;

        $errorData = $data['error'];

        Assert::that($errorData)->hasOffset('data', 'UrlElicitationRequiredError wire error data missing "data".');
        Assert::that($errorData['data'])
            ->isArray('UrlElicitationRequiredError wire error "data" must be an object, {type} given.')
            ->isMap('UrlElicitationRequiredError wire error "data" must be a string-keyed object.')
        ;

        $payload = $errorData['data'];

        Assert::that($payload)->hasOffset('elicitations', 'UrlElicitationRequiredError wire error data missing "elicitations".');
        Assert::that($payload['elicitations'])
            ->isList('UrlElicitationRequiredError wire "elicitations" must be a list, got non-list array.')
            ->values()
            ->isArray('UrlElicitationRequiredError wire elicitations entry must be an object, {type} given.')
            ->isMap('UrlElicitationRequiredError wire elicitations entry must be a string-keyed object.')
        ;
        $elicitations = array_map(ElicitRequestUrlParams::fromArray(...), $payload['elicitations']);

        $message = $errorData['message'] ?? null;
        Assert::that($message)->isString('UrlElicitationRequiredError wire error "message" must be a string, {type} given.');

        $error = new UrlElicitationRequiredErrorPayload($message, $payload);

        return new self(
            null === $id ? null : new RequestId($id),
            $error,
            $elicitations,
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
