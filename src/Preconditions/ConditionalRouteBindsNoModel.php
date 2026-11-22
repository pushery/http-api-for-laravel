<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Preconditions;

use LogicException;
use Pushery\HttpApi\Errors\ErrorDocument;
use Pushery\HttpApi\Errors\JsonApiError;
use Pushery\HttpApi\Errors\ProvidesErrorDocument;

/**
 * A change reaches a route under {@see Conditional} whose parameters hold no model, so its `If-Match` has nothing to be
 * held against.
 *
 * That is a setting of the application, not a mistake of the client: the route binds the resource it changes by
 * route model binding, or the middleware runs before `SubstituteBindings`. It is thrown rather than let through, so
 * the change does not run unchecked, it reaches the reporters, and a client reads a `500` `internal_error`. Its
 * message, which names the route, stays in the report.
 */
final class ConditionalRouteBindsNoModel extends LogicException implements ProvidesErrorDocument
{
    public static function on(string $path): self
    {
        return new self(sprintf(
            'The request to [%s] changes a resource, and no parameter of its route holds a model to hold its If-Match against. Bind the resource by route model binding, after SubstituteBindings.',
            $path,
        ));
    }

    public function toErrorDocument(): ErrorDocument
    {
        return ErrorDocument::of(JsonApiError::of('internal_error'));
    }
}
