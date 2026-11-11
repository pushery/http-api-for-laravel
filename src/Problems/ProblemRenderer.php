<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Answers the failures of an API request with problem documents.
 *
 * The provider hands {@see self::render()} to Laravel's exception handler as a render callback. Laravel turns a missing
 * model into a 404 and a refused authorization into a 403 before it asks a callback, so both arrive here as HTTP
 * exceptions and keep their status. An exception that carries its own response, and a validation given one, keep that
 * response. An exception nobody anticipated answers `500` and says nothing about itself: its
 * message could carry anything, and it still reaches the reporters the way every other one does. With `app.debug` on,
 * such an exception is left to Laravel, whose debug answer is what a developer wants to read.
 */
final readonly class ProblemRenderer
{
    /**
     * @param  RecognizesApiRequests|null  $apiRequests  which requests belong to the API; the container hands in the one
     *                                                   the application binds, and without one the configuration decides
     */
    public function __construct(
        private Repository $config,
        private Translator $translator,
        private ?RecognizesApiRequests $apiRequests = null,
    ) {}

    /** The answer to a failure, or null where the request is no API request and Laravel answers as it would. */
    public function render(Throwable $failure, Request $request): ?JsonResponse
    {
        if (! $this->answersWithProblems($request)) {
            return null;
        }

        return $this->problemFor($failure)?->toResponse();
    }

    /** Whether a failure on this request is answered with a problem document. */
    public function answersWithProblems(Request $request): bool
    {
        if (($this->apiRequests ?? new ConfiguredApiRequests($this->config))->isApiRequest($request)) {
            return true;
        }

        if (str_contains((string) $request->header('Accept'), Problem::MEDIA_TYPE)) {
            return true;
        }

        return $this->config->get('http-api.problems.when_json_is_expected', true) !== false && $request->expectsJson();
    }

    /** The problem a failure is answered with, or null for one Laravel answers itself. */
    public function problemFor(Throwable $failure): ?Problem
    {
        return match (true) {
            $failure instanceof ProvidesProblem => $failure->toProblem(),
            $failure instanceof HttpResponseException,
            $failure instanceof ValidationException && $failure->response !== null => null,
            $failure instanceof ValidationException => new Problem(
                $failure->status >= 400 && $failure->status <= 599 ? $failure->status : 422,
                detail: $this->sentence('validation'),
                members: ['errors' => $this->errorsOf($failure)],
            ),
            $failure instanceof AuthenticationException => new Problem(401, detail: $this->sentence('authentication'), headers: $this->challenge()),
            $failure instanceof HttpExceptionInterface => $this->httpProblem($failure),
            $this->config->get('app.debug') === true => null,
            default => new Problem(500, detail: $this->sentence('server')),
        };
    }

    /**
     * The problem an HTTP exception answers with, or null for a status that is no error.
     *
     * The message an HTTP exception was thrown with, as `abort(403, '…')` writes it, is meant for the client and is the
     * problem's detail, and so is the answer of a refused authorization, which Laravel carries over. The message of any
     * other exception Laravel turns into an HTTP exception, a missing model's among them, names classes and keys, and
     * stays out.
     */
    private function httpProblem(HttpExceptionInterface $failure): ?Problem
    {
        $status = $failure->getStatusCode();

        if ($status < 400 || $status > 599) {
            return null;
        }

        $message = trim($failure->getMessage());
        $previous = $failure->getPrevious();

        return new Problem(
            $status,
            detail: $message !== '' && (! $previous instanceof Throwable || $previous instanceof AuthorizationException) ? $message : null,
            headers: $this->stringHeaders($failure->getHeaders()),
        );
    }

    /**
     * The WWW-Authenticate header of a 401, which RFC 9110 §15.5.2 requires, or none where the setting names no scheme.
     *
     * @return array<string, string>
     */
    private function challenge(): array
    {
        $scheme = $this->config->get('http-api.problems.www_authenticate', 'Bearer');

        return is_string($scheme) && trim($scheme) !== '' ? ['WWW-Authenticate' => trim($scheme)] : [];
    }

    private function sentence(string $problem): string
    {
        $line = $this->translator->get('http-api::messages.problems.'.$problem);

        return is_string($line) ? $line : '';
    }

    /** @return array<string, list<string>> */
    private function errorsOf(ValidationException $failure): array
    {
        $errors = [];

        foreach ($failure->errors() as $field => $messages) {
            $errors[(string) $field] = is_array($messages) ? array_values(array_filter($messages, is_string(...))) : [];
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $strings = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $strings[$name] = (string) $value;
            }
        }

        return $strings;
    }
}
