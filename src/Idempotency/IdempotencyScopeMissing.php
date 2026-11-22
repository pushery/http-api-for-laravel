<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use LogicException;
use Pushery\HttpApi\Errors\ErrorDocument;
use Pushery\HttpApi\Errors\JsonApiError;
use Pushery\HttpApi\Errors\ProvidesErrorDocument;

/**
 * A request carries an `Idempotency-Key`, and nothing says who sent it.
 *
 * That is a setting of the application, not a mistake of the client: the route lets an unauthenticated caller write,
 * and no scope resolver was bound that knows that caller. It is thrown rather than answered quietly, so it reaches the
 * reporters, and a client reads a `500` `internal_error`. Its message, which names the resolver to bind, stays in the
 * report.
 */
final class IdempotencyScopeMissing extends LogicException implements ProvidesErrorDocument
{
    public static function on(string $path): self
    {
        return new self(sprintf(
            'The request to [%s] carries an Idempotency-Key, and the bound %s finds no scope for it. Bind a resolver that knows the caller of this route.',
            $path,
            ResolvesIdempotencyScope::class,
        ));
    }

    public function toErrorDocument(): ErrorDocument
    {
        return ErrorDocument::of(JsonApiError::of('internal_error'));
    }
}
