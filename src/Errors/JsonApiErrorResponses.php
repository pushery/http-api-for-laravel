<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Http\JsonResponse;

/**
 * The JSON:API error document, `{"errors": [...]}`, as the package writes every error response unless the application
 * binds another {@see WritesErrorResponses}.
 *
 * The body is written without whitespace and with slashes unescaped, the bytes the gateway's encoder writes for the
 * same error: the texts of the catalog hold none of the characters the two encoders write differently.
 */
final readonly class JsonApiErrorResponses implements WritesErrorResponses
{
    public function respond(ErrorDocument $document, string $requestId): JsonResponse
    {
        return new JsonResponse(
            ['errors' => array_map(static fn (JsonApiError $error): array => $error->toArray($requestId), $document->errors)],
            $document->status(),
            $document->responseHeaders($requestId),
            JSON_UNESCAPED_SLASHES,
        );
    }
}
