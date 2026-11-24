<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * The errors of one error response and its headers. {@see WritesErrorResponses} writes the response, the JSON:API
 * document `{"errors": [...]}` of {@see JsonApiErrorResponses} unless the application binds another writer.
 *
 * Every error of a document has the same status, which is the status of the response, as one object per invalid field
 * of a validation has. The response carries the headers the catalog names for every error response, the request id
 * and the headers of the first error's code, and beside them only the headers given here, such as the limit headers
 * of a throttled request. A given header never replaces one of the others.
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

    /**
     * The response, every error naming the request id that the response names in `Request-Id`, written by the
     * {@see WritesErrorResponses} the application binds, and by {@see JsonApiErrorResponses} where none is bound.
     */
    public function toResponse(string $requestId): JsonResponse
    {
        $container = Container::getInstance();
        $writer = $container->bound(WritesErrorResponses::class) ? $container->make(WritesErrorResponses::class) : new JsonApiErrorResponses;

        return $writer->respond($this, $requestId);
    }

    /**
     * The headers of the response: the ones given here, those of the first error's code, the ones the catalog names for
     * every error response and the request id, a later one winning, so a given header never replaces one of the others.
     * A writer of another format sends them as well and sets its own `Content-Type` over the catalog's.
     *
     * @return array<string, string>
     */
    public function responseHeaders(string $requestId): array
    {
        return [...$this->headers, ...$this->errors[0]->headers, ...ErrorCodes::shipped()->responseHeaders, 'Request-Id' => $requestId];
    }
}
