<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A JSON:API error document, `{"errors": [...]}`, and the response that carries it.
 *
 * Every error of a document has the same status, which is the status of the response, as one object per invalid field
 * of a validation has. The response carries the headers the catalog names for every error response, the request id
 * and the headers of the first error's code, and beside them only the headers given here, such as the limit headers
 * of a throttled request. A given header never replaces one of the others.
 *
 * The body is written without whitespace and with slashes unescaped, the bytes the gateway's encoder writes for the
 * same error: the texts of the catalog hold none of the characters the two encoders write differently.
 */
final readonly class ErrorDocument
{
    /**
     * @param  non-empty-list<JsonApiError>  $errors
     * @param  array<string, string>  $headers  further headers of the response
     *
     * @throws InvalidArgumentException for errors of different statuses
     */
    public function __construct(
        public array $errors,
        public array $headers = [],
    ) {
        foreach ($errors as $error) {
            if ($error->code->status !== $errors[0]->code->status) {
                throw new InvalidArgumentException('The errors of one document share one status, the status of its response.');
            }
        }
    }

    /**
     * A document of one error.
     *
     * @param  array<string, string>  $headers
     */
    public static function of(JsonApiError $error, array $headers = []): self
    {
        return new self([$error], $headers);
    }

    public function status(): int
    {
        return $this->errors[0]->code->status;
    }

    /** The response, every error naming the request id that the response names in `Request-Id`. */
    public function toResponse(string $requestId): JsonResponse
    {
        return new JsonResponse(
            ['errors' => array_map(static fn (JsonApiError $error): array => $error->toArray($requestId), $this->errors)],
            $this->status(),
            [...$this->headers, ...$this->errors[0]->headers, ...ErrorCodes::shipped()->responseHeaders, 'Request-Id' => $requestId],
            JSON_UNESCAPED_SLASHES,
        );
    }
}
