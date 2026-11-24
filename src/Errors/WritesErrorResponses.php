<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Http\JsonResponse;

/**
 * How an error response is written.
 *
 * The package binds {@see JsonApiErrorResponses}, which writes a JSON:API error document. An application whose
 * contract fixes another format for some of its routes, an RFC 9457 problem document for an API it published before,
 * binds its own and writes that format from the same errors: their codes, titles and details, the name of each
 * detail, and the headers of the response. Every error response of the package goes through it, a failure the
 * renderer answers as well as a refusal of the idempotency and the precondition middleware.
 */
interface WritesErrorResponses
{
    /** The response that carries the document, every error naming the request id the response names in `Request-Id`. */
    public function respond(ErrorDocument $document, string $requestId): JsonResponse;
}
