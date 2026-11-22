<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

/**
 * An exception that knows the JSON:API errors it is answered with.
 *
 * The renderer asks it before any other mapping, so an application's own refusal reaches a client with the code, the
 * detail and the source it chose instead of a bare `500`. Its errors are made from the catalog as well, with
 * {@see JsonApiError::of()}, so a code the catalog does not have cannot be answered.
 */
interface ProvidesErrorDocument
{
    public function toErrorDocument(): ErrorDocument;
}
