<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidAppendQuery;
use Spatie\QueryBuilder\Exceptions\InvalidFieldQuery;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Spatie\QueryBuilder\Exceptions\UnknownIncludedFieldsQuery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Answers the failures of an API request with JSON:API errors from the catalog.
 *
 * The provider hands {@see self::render()} to Laravel's exception handler as a render callback. Laravel turns a missing
 * model into a 404 and a refused authorization into a 403 before it asks a callback, so both arrive here as HTTP
 * exceptions and are answered by their status. The message of an exception never reaches the client: the title and the
 * detail of every error come from the catalog. A known path asked with another method answers like an unknown path,
 * `404` without `Allow`, because a `405` would list the methods of a path to anyone who asks. A client error the catalog
 * has no code for answers `400` `malformed_request`, so a client does not send it again unchanged as it would after a
 * `5xx`. A server error the catalog has no code for, and an exception nobody anticipated, answer `500`
 * `internal_error`; the exception still reaches the reporters the way every other one does. With `app.debug` on, such
 * an exception is left to Laravel, whose debug answer is what a developer wants to read. An exception that carries its
 * own response, and a validation given one, keep that response.
 *
 * A refusal of `spatie/laravel-query-builder`, a filter, a sort, an include, a field or an append the route does not
 * allow, answers `400` with one error per parameter it names. The package is no requirement: where it is not installed,
 * no such refusal can be thrown.
 */
final readonly class ErrorRenderer
{
    /** The limit headers of a throttled request, which its error response keeps. */
    private const array LIMIT_HEADERS = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    /** The wait in seconds a `429` names when its exception gives none, as `abort(429)` throws it. */
    private const string DEFAULT_RETRY_AFTER = '60';

    /**
     * @param  RecognizesApiRequests|null  $apiRequests  which requests belong to the API; the container hands in the one
     *                                                   the application binds, and without one the configuration decides
     */
    public function __construct(
        private Repository $config,
        private ?RecognizesApiRequests $apiRequests = null,
    ) {}

    /** The answer to a failure, or null where the request is no API request and Laravel answers as it would. */
    public function render(Throwable $failure, Request $request): ?JsonResponse
    {
        if (! $this->answersWithErrors($request)) {
            return null;
        }

        return $this->documentFor($failure, $request)?->toResponse(RequestId::of($request));
    }

    /** Whether a failure on this request is answered with JSON:API errors. */
    public function answersWithErrors(Request $request): bool
    {
        if (($this->apiRequests ?? new ConfiguredApiRequests($this->config))->isApiRequest($request)) {
            return true;
        }

        if (str_contains(strtolower((string) $request->header('Accept')), ErrorCodes::MEDIA_TYPE)) {
            return true;
        }

        return $this->config->get('http-api.errors.when_json_is_expected') === true && $request->expectsJson();
    }

    /** The errors a failure is answered with, or null for one Laravel answers itself. */
    public function documentFor(Throwable $failure, Request $request): ?ErrorDocument
    {
        return match (true) {
            $failure instanceof ProvidesErrorDocument => $failure->toErrorDocument(),
            $failure instanceof HttpResponseException,
            $failure instanceof ValidationException && $failure->response !== null => null,
            $failure instanceof ValidationException => $this->validation($failure, $request),
            $failure instanceof AuthenticationException => ErrorDocument::of(JsonApiError::of('unauthenticated')),
            $failure instanceof InvalidQuery => $this->invalidQuery($failure),
            $failure instanceof HttpExceptionInterface => $this->http($failure),
            $this->config->get('app.debug') === true => null,
            default => ErrorDocument::of(JsonApiError::of('internal_error')),
        };
    }

    /**
     * One error per invalid field, each naming its field: a member of the body by its JSON pointer, a query parameter by
     * its name. A field the query carries and the body does not is a query parameter, and so is every field of a
     * request whose method has no body.
     */
    private function validation(ValidationException $failure, Request $request): ErrorDocument
    {
        $code = $failure->status === 422 ? ErrorCodes::shipped()->code('validation_failed') : ErrorCodes::shipped()->forStatus($failure->status);
        // Laravel puts a JSON body into the request bag when it builds the request, so the bag holds the body of a form
        // and of a JSON request alike.
        $body = $request->request->all();
        $errors = [];

        foreach (array_keys($failure->errors()) as $field) {
            $field = (string) $field;
            $inQuery = Arr::has($request->query->all(), $field) && ! Arr::has($body, $field);
            $source = $inQuery || in_array($request->getMethod(), ['GET', 'HEAD'], true)
                ? ['parameter' => $this->parameter($field)]
                : ['pointer' => $this->pointer($field)];
            $errors[] = JsonApiError::of($code->code, source: $source);
        }

        return new ErrorDocument($errors === [] ? [JsonApiError::of($code->code)] : $errors);
    }

    /**
     * One `malformed_request` per parameter a refusal of the query builder names, as the request named it: `filter[status]`
     * for a filter, `sort`, `include`, `fields[orders]` for a field of a type, and `append`.
     */
    private function invalidQuery(InvalidQuery $failure): ErrorDocument
    {
        $parameters = match (true) {
            $failure instanceof InvalidFilterQuery => $failure->unknownFilters->map($this->filterParameter(...)),
            $failure instanceof InvalidSortQuery => $failure->unknownSorts->map(static fn (): string => 'sort'),
            $failure instanceof InvalidIncludeQuery => $failure->unknownIncludes->map(static fn (): string => 'include'),
            $failure instanceof InvalidFieldQuery => $failure->unknownFields->map($this->fieldsParameter(...)),
            $failure instanceof UnknownIncludedFieldsQuery => $failure->unknownFields->map($this->fieldsParameter(...)),
            $failure instanceof InvalidAppendQuery => $failure->appendsNotAllowed->map(static fn (): string => 'append'),
            default => new Collection,
        };
        $errors = [];

        foreach ($parameters->unique()->values() as $parameter) {
            $errors[] = JsonApiError::of('malformed_request', source: ['parameter' => $parameter]);
        }

        return new ErrorDocument($errors === [] ? [JsonApiError::of('malformed_request')] : $errors);
    }

    /**
     * The parameter a filter names: `status` is `filter[status]`.
     *
     * @param  mixed  $filter  a name the refusal of the query builder carries in a collection it does not type
     */
    private function filterParameter(mixed $filter): string
    {
        return 'filter['.$this->text($filter).']';
    }

    /**
     * The parameter a field names: `orders.secret` is a field of `fields[orders]`, and a field without a type is `fields`.
     *
     * @param  mixed  $field  a name the refusal of the query builder carries in a collection it does not type
     */
    private function fieldsParameter(mixed $field): string
    {
        $field = $this->text($field);
        $type = str_contains($field, '.') ? substr($field, 0, (int) strrpos($field, '.')) : '';

        return $type === '' ? 'fields' : 'fields['.$type.']';
    }

    /**
     * A name of a refusal as text, and an empty one for a value that is no name.
     *
     * @param  mixed  $value  an entry of a collection of the query builder, which types none of its entries
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The error of an HTTP exception, or null for a status that is no error.
     *
     * A throttled request keeps its limit headers and names its wait, and the maintenance mode keeps its `Retry-After`
     * when the end is known. Every other header of the exception stays out, `Allow` and `Refresh` among them.
     */
    private function http(HttpExceptionInterface $failure): ?ErrorDocument
    {
        $status = $failure->getStatusCode() === 405 ? 404 : $failure->getStatusCode();

        if ($status < 400 || $status > 599) {
            return null;
        }

        $headers = [];

        foreach ($failure->getHeaders() as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[strtolower($name)] = (string) $value;
            }
        }

        $wait = $headers['retry-after'] ?? '';

        return match ($status) {
            429 => ErrorDocument::of(
                JsonApiError::of('rate_limited', values: ['retry_after' => preg_match('/\A\d+\z/', $wait) === 1 ? $wait : self::DEFAULT_RETRY_AFTER]),
                $this->limitHeaders($headers),
            ),
            503 => ErrorDocument::of(JsonApiError::forStatus(503), preg_match('/\A\d+\z/', $wait) === 1 ? ['Retry-After' => $wait] : []),
            default => ErrorDocument::of(JsonApiError::forStatus($status)),
        };
    }

    /**
     * The limit headers a throttled request carries, under their usual names.
     *
     * @param  array<string, string>  $headers  by lower-case name
     * @return array<string, string>
     */
    private function limitHeaders(array $headers): array
    {
        $kept = [];

        foreach (self::LIMIT_HEADERS as $name) {
            if (array_key_exists(strtolower($name), $headers)) {
                $kept[$name] = $headers[strtolower($name)];
            }
        }

        return $kept;
    }

    /** A field as a JSON pointer (RFC 6901): `data.attributes.name` is `/data/attributes/name`. */
    private function pointer(string $field): string
    {
        return '/'.implode('/', array_map(
            static fn (string $segment): string => str_replace(['~', '/'], ['~0', '~1'], $segment),
            explode('.', $field),
        ));
    }

    /** A field as a query parameter: `filter.status` is `filter[status]`. */
    private function parameter(string $field): string
    {
        $segments = explode('.', $field);

        return array_shift($segments).implode('', array_map(static fn (string $segment): string => '['.$segment.']', $segments));
    }
}
