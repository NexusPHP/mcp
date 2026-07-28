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

namespace Nexus\Mcp\Core\Schema\NotificationParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\ParsesNumber;
use Nexus\Mcp\Core\Schema\ProgressToken;

/**
 * Parameters for a `notifications/progress` notification.
 *
 * @extends NotificationParams<array{
 *   _meta?: template-type<NotificationMetaObject, MetaObject, 'T'>,
 *   progressToken: int|non-empty-string,
 *   progress: float,
 *   total?: float,
 *   message?: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#progressnotificationparams
 */
final readonly class ProgressNotificationParams extends NotificationParams
{
    use ParsesNumber;

    /**
     * @var null|non-empty-string
     */
    public ?string $message;

    public function __construct(
        public ProgressToken $progressToken,
        public float $progress,
        public ?float $total = null,
        ?string $message = null,
        NotificationMetaObject $meta = new NotificationMetaObject(),
    ) {
        Assert::that($message)->nullOr()->isNonEmptyString('"params.message" must be a non-empty string or null.');

        $this->message = $message;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('progressToken', '"params" is missing the required "progressToken" key.');
        $progressToken = $data['progressToken'];
        Assert::that($progressToken)->isArrayKey('"params.progressToken" must be an int or string, {type} given.');

        Assert::that($data)->hasOffset('progress', '"params" is missing the required "progress" key.');
        $progress = self::parseNumber($data['progress'], '"params.progress" must be a number, {type} given.');

        $total = $data['total'] ?? null;

        if (null !== $total) {
            $total = self::parseNumber($total, '"params.total" must be a number or null, {type} given.');
        }

        $message = $data['message'] ?? null;
        Assert::that($message)->nullOr()->isString('"params.message" must be a string or null, {type} given.');

        $meta = new NotificationMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = NotificationMetaObject::fromArray($data['_meta']);
        }

        return new self(
            progressToken: new ProgressToken(token: $progressToken),
            progress: $progress,
            total: $total,
            message: $message,
            meta: $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['progressToken'] = $this->progressToken->token;
        $data['progress'] = $this->progress;

        if (null !== $this->total) {
            $data['total'] = $this->total;
        }

        if (null !== $this->message) {
            $data['message'] = $this->message;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
