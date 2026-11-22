<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Holds a route to the content negotiation of JSON:API 1.1.
 *
 * A request body comes as `application/vnd.api+json`. One in another media type, one with a media type parameter other
 * than `profile`, and one that asks for an extension through `ext` is refused with `415`: the package supports no
 * extension. A request whose `Accept` header admits no JSON:API answer is refused with `406`: the header names the
 * media type, and every time with another parameter or with an extension, or it names neither the media type nor a
 * range that covers it. A request without `Accept` takes any answer.
 *
 * Both refusals are HTTP exceptions, which the renderer answers as JSON:API errors like every other failure.
 */
final class NegotiateJsonApi
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws HttpException with `415` or `406` for a request the route cannot take or cannot answer
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getContent() !== '' && ! $this->takes((string) $request->headers->get('Content-Type'))) {
            throw new HttpException(415);
        }

        if (! $this->answers((string) $request->headers->get('Accept'))) {
            throw new HttpException(406);
        }

        return $next($request);
    }

    /** Whether a body of this media type is taken: JSON:API, with no parameter but `profile`. */
    private function takes(string $contentType): bool
    {
        [$type, $parameters] = $this->mediaType($contentType);

        return $type === ErrorCodes::MEDIA_TYPE && array_diff(array_keys($parameters), ['profile']) === [];
    }

    /**
     * Whether a JSON:API answer is acceptable. Where the header names the media type, one instance of it without a
     * parameter but `profile` and `q`, and with a weight above zero, has to be there; where it does not, a range that
     * covers it with a weight above zero has to be. A named instance weighted zero excludes the media type, whatever
     * range covers it, because the more specific range decides.
     */
    private function answers(string $accept): bool
    {
        if (trim($accept) === '') {
            return true;
        }

        $named = false;
        $covered = false;

        foreach (explode(',', $accept) as $range) {
            [$type, $parameters] = $this->mediaType($range);
            $wanted = ! array_key_exists('q', $parameters) || (float) $parameters['q'] > 0.0;

            if ($type === ErrorCodes::MEDIA_TYPE) {
                $named = true;

                if ($wanted && array_diff(array_keys($parameters), ['profile', 'q']) === []) {
                    return true;
                }

                continue;
            }

            $covered = $covered || ($wanted && in_array($type, ['*/*', 'application/*'], true));
        }

        return ! $named && $covered;
    }

    /**
     * A media type in lower case and its parameters by lower-case name.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function mediaType(string $value): array
    {
        $parts = explode(';', $value);
        $parameters = [];

        foreach (array_slice($parts, 1) as $parameter) {
            [$name, $content] = array_pad(explode('=', $parameter, 2), 2, '');
            $parameters[strtolower(trim($name))] = trim($content, " \t\"");
        }

        return [strtolower(trim($parts[0])), $parameters];
    }
}
