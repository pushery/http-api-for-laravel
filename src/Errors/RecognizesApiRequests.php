<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Http\Request;

/**
 * Which requests belong to the API, and so have their failures answered with JSON:API errors.
 *
 * The package binds {@see ConfiguredApiRequests}, which reads the API host, the hosts and the route prefixes of the
 * configuration. An application whose routes know their API better, by a route group, a middleware or the mode its API
 * runs in, binds its own, and the package asks that one instead. A request that asks for `application/vnd.api+json`,
 * and with `errors.when_json_is_expected` one that expects JSON, gets JSON:API errors whatever this answers.
 */
interface RecognizesApiRequests
{
    public function isApiRequest(Request $request): bool;
}
