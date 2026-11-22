<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Preconditions;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Pushery\HttpApi\Errors\RequestId;
use Pushery\HttpApi\Idempotency\Idempotent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional requests of RFC 9110 for the resources of a route: an entity tag on every answer that shows one, `304`
 * for a read whose `If-None-Match` names it, and `412` for a change whose `If-Match` does not.
 *
 * An answer of `200` or `201` that shows a model, as a `JsonApiResource` of one does, carries the model's
 * {@see EntityTag} in `ETag`, unless the route set a tag of its own. A `GET` or `HEAD` whose `If-None-Match` names the
 * tag of its `200` is answered with `304` and no body instead.
 *
 * A `PATCH`, `PUT` or `DELETE` needs an `If-Match` header with the tag of the version it changes, and one without is
 * refused with `428`. The tag is compared with the resource's current one inside a database transaction that locks the
 * resource's row and runs the route as well. Of two changes sent with the same tag, the second therefore waits for the
 * first, finds another version and is refused with `412` instead of undoing the first. The resource is the last model
 * among the route's parameters, where route model binding puts it. `If-Match: *` holds for every resource that still
 * exists, and a weak tag never holds, as the strong comparison of RFC 9110 has it. `POST` passes through, and so does
 * every method that changes nothing, each answer that shows a model carrying its tag.
 *
 * Behind {@see Idempotent}, a retried change gets the first answer back with the tag that
 * answer carried, while a retry that reached this middleware first would be refused with `412`, the resource having
 * changed with the first attempt.
 */
final readonly class Conditional
{
    /** The methods that change a resource, which need an `If-Match` header. */
    private const array CHANGES = ['PATCH', 'PUT', 'DELETE'];

    public function __construct(private Repository $config, private EntityTag $tags) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws ConditionalRouteBindsNoModel when a change reaches a route whose parameters hold no model
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('http-api.enabled') === false) {
            return $next($request);
        }

        if (! in_array($request->method(), self::CHANGES, true)) {
            return $this->tagged($request, $next($request));
        }

        $resource = $this->resourceOf($request);
        $expected = trim((string) $request->headers->get(PreconditionRefusal::HEADER, ''));

        if ($expected === '') {
            return $this->refuse(PreconditionRefusal::required(), $request);
        }

        return $resource->getConnection()->transaction(function () use ($resource, $expected, $request, $next): Response {
            if (! $this->holds($expected, $this->tags->of($resource, lock: true))) {
                return $this->refuse(PreconditionRefusal::failed(), $request);
            }

            return $this->tagged($request, $next($request));
        });
    }

    /**
     * The resource a change is held against: the last model among the route's parameters.
     *
     * @throws ConditionalRouteBindsNoModel when none of them holds a model
     */
    private function resourceOf(Request $request): Model
    {
        $resource = null;

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                $resource = $parameter;
            }
        }

        if (! $resource instanceof Model) {
            throw ConditionalRouteBindsNoModel::on($request->path());
        }

        return $resource;
    }

    /**
     * Whether an `If-Match` header holds for the resource's current tag, by the strong comparison of RFC 9110: `*` for
     * every resource that exists, otherwise one of the tags it lists, none of them weak, equal to the current one.
     */
    private function holds(string $header, ?string $current): bool
    {
        if ($current === null) {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        preg_match_all('/(W\/)?("[\x21\x23-\x7E\x80-\xFF]*")/', $header, $tags, PREG_SET_ORDER);

        foreach ($tags as $tag) {
            if ($tag[1] === '' && hash_equals($current, $tag[2])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer with the entity tag of the model it shows, where it is a `200` or `201` that shows one and carries no
     * tag of the route's own, and `304` in its place for a read whose `If-None-Match` names the tag of its `200`.
     */
    private function tagged(Request $request, Response $response): Response
    {
        $status = $response->getStatusCode();
        $shown = $response instanceof JsonResponse || $response instanceof IlluminateResponse ? $response->original : null;

        if ($shown instanceof Model && in_array($status, [200, 201], true) && ! $response->headers->has('ETag')) {
            $tag = $this->tags->of($shown);

            if ($tag !== null) {
                $response->setEtag($tag);
            }
        }

        if ($status === 200) {
            $response->isNotModified($request);
        }

        return $response;
    }

    /**
     * The answer to a refused change: the refusal's JSON:API error, naming the request's id like every error response.
     */
    private function refuse(PreconditionRefusal $refusal, Request $request): JsonResponse
    {
        return $refusal->toErrorDocument()->toResponse(RequestId::of($request));
    }
}
